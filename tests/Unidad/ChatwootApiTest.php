<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Excepciones\ChatwootNoDisponible;
use App\Modelos\Usuario;
use App\Servicios\ChatwootApi;
use App\Servicios\Config;
use App\Soporte\Http;
use App\Soporte\Logger;
use App\Soporte\RespuestaHttp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El servicio de bandeja, sin salir a la red.
 *
 * Lo que se defiende aquí, por orden de gravedad: que el modo sombra no se
 * pueda saltar por descuido, que un 500 no duplique el mensaje en el hilo de
 * un cliente, y que un fallo de red no se pierda en silencio.
 */
#[Group('critica')]
final class ChatwootApiTest extends TestCase
{
    /** @param list<RespuestaHttp> $respuestas */
    private function http(array $respuestas): Http
    {
        return new class ($respuestas) extends Http {
            /** @var list<array{metodo:string,url:string,cuerpo:array<string,mixed>|null}> */
            public array $llamadas = [];

            /** @param list<RespuestaHttp> $respuestas */
            public function __construct(private array $respuestas)
            {
                parent::__construct();
            }

            public function pedir(
                string $metodo,
                string $url,
                array $cabeceras = [],
                ?array $json = null,
            ): RespuestaHttp {
                $this->llamadas[] = ['metodo' => $metodo, 'url' => $url, 'cuerpo' => $json];

                return array_shift($this->respuestas)
                    ?? new RespuestaHttp(500, '', 'sin respuesta preparada', 0);
            }
        };
    }

    private function config(bool $sombra): Config
    {
        return new class ($sombra) implements Config {
            /** @var array<string,mixed> */
            public array $valores;

            public function __construct(bool $sombra)
            {
                $this->valores = ['motor_modo_sombra' => $sombra];
            }

            public function get(string $clave, mixed $porDefecto = null): mixed
            {
                return array_key_exists($clave, $this->valores) ? $this->valores[$clave] : $porDefecto;
            }

            public function set(string $clave, mixed $valor, string $usuarioId, ?string $motivo = null): void
            {
                $this->valores[$clave] = $valor;
            }

            public function getGrupo(string $grupo): array
            {
                return [];
            }

            public function invalidarCache(?string $clave = null): void
            {
            }
        };
    }

    private function chatwoot(Http $http, ?Config $config = null, ?int $agente = 7): ChatwootApi
    {
        return new ChatwootApi(
            $http,
            $config ?? $this->config(sombra: true),
            Logger::desdeEntorno(),
            'https://bandeja.local',
            '1',
            'token-de-prueba',
            $agente,
            // No dormir de verdad: la suite tardaría casi un segundo por cada
            // caso de reintento.
            static fn (int $ms): null => null,
        );
    }

    private function ok(int $id = 555): RespuestaHttp
    {
        return new RespuestaHttp(200, json_encode(['id' => $id], JSON_THROW_ON_ERROR), null, 60);
    }

    // ── Modo sombra ──────────────────────────────────────────────────────

    #[Test]
    public function enModoSombraSeEscribeNotaPrivadaYNoMensaje(): void
    {
        $http = $this->http([$this->ok()]);

        $resultado = $this->chatwoot($http)->entregar(42, 'Cuénteme qué pasó.');

        self::assertTrue($resultado['sombra']);
        self::assertTrue($http->llamadas[0]['cuerpo']['private']);
        self::assertStringContainsString(
            ChatwootApi::MARCA_SOMBRA,
            $http->llamadas[0]['cuerpo']['content'],
        );
        self::assertStringContainsString('Cuénteme qué pasó.', $http->llamadas[0]['cuerpo']['content']);
    }

    #[Test]
    public function conElModoSombraApagadoSiSeEnviaAlContacto(): void
    {
        $http = $this->http([$this->ok()]);

        $resultado = $this->chatwoot($http, $this->config(sombra: false))
            ->entregar(42, 'Cuénteme qué pasó.');

        self::assertFalse($resultado['sombra']);
        self::assertFalse($http->llamadas[0]['cuerpo']['private']);
        self::assertSame('Cuénteme qué pasó.', $http->llamadas[0]['cuerpo']['content']);
    }

    #[Test]
    public function siLaClaveDelModoSombraFaltaSeAsumeSombra(): void
    {
        // Si alguien borra la fila, la migración se queda a medias o la
        // caché devuelve basura, el comportamiento seguro es NO enviarle
        // nada a un cliente. El valor por defecto no es «false» por comodidad.
        $vacia = new class implements Config {
            public function get(string $clave, mixed $porDefecto = null): mixed
            {
                return $porDefecto;
            }

            public function set(string $clave, mixed $valor, string $usuarioId, ?string $motivo = null): void
            {
            }

            public function getGrupo(string $grupo): array
            {
                return [];
            }

            public function invalidarCache(?string $clave = null): void
            {
            }
        };

        $http = $this->http([$this->ok()]);

        self::assertTrue($this->chatwoot($http, $vacia)->entregar(42, 'hola')['sombra']);
        self::assertTrue($http->llamadas[0]['cuerpo']['private']);
    }

    #[Test]
    public function elBorradorVaMarcadoParaQueNadieLoConfundaConUnEnvio(): void
    {
        // El día que se active el envío automático, una nota y un mensaje se
        // parecen demasiado en la bandeja.
        $http = $this->http([$this->ok()]);

        $this->chatwoot($http)->entregar(42, 'texto');

        self::assertStringStartsWith(
            ChatwootApi::MARCA_SOMBRA,
            $http->llamadas[0]['cuerpo']['content'],
        );
    }

    // ── Reintentos y duplicados ──────────────────────────────────────────

    #[Test]
    public function unFalloDeRedSeReintentaHastaTresVeces(): void
    {
        $http = $this->http([
            new RespuestaHttp(0, '', 'Connection reset by peer', 5000),
            new RespuestaHttp(0, '', 'Connection reset by peer', 5000),
            $this->ok(),
        ]);

        self::assertSame(555, $this->chatwoot($http)->notaPrivada(42, 'texto'));
        self::assertCount(3, $http->llamadas);
    }

    #[Test]
    public function un500NoSeReintentaParaNoDuplicarElMensaje(): void
    {
        // ESTA es la distinción que protege el hilo de un cliente. Un 500
        // significa que Chatwoot corrió y pudo haber creado el mensaje antes
        // de romperse; repetir lo pondría dos veces.
        $http = $this->http([
            new RespuestaHttp(500, '{"error":"boom"}', null, 300),
            $this->ok(),
        ]);

        try {
            $this->chatwoot($http)->responder(42, 'texto');
            self::fail('debió lanzar ChatwootNoDisponible');
        } catch (ChatwootNoDisponible $e) {
            self::assertFalse($e->agotoReintentos);
            self::assertSame(1, $e->intentos);
        }

        self::assertCount(1, $http->llamadas, 'no puede haber un segundo intento');
    }

    #[Test]
    public function un503SiSeReintentaPorqueLaPeticionNoLlegoAProcesarse(): void
    {
        $http = $this->http([
            new RespuestaHttp(503, 'upstream unavailable', null, 100),
            $this->ok(),
        ]);

        self::assertSame(555, $this->chatwoot($http)->notaPrivada(42, 'texto'));
        self::assertCount(2, $http->llamadas);
    }

    #[Test]
    public function un401NoSeReintenta(): void
    {
        // Va a fallar igual. Reintentar solo multiplica la latencia antes de
        // dar el mismo error.
        $http = $this->http([new RespuestaHttp(401, '{"error":"unauthorized"}', null, 40)]);

        $this->expectException(ChatwootNoDisponible::class);

        try {
            $this->chatwoot($http)->notaPrivada(42, 'texto');
        } finally {
            self::assertCount(1, $http->llamadas);
        }
    }

    #[Test]
    public function agotarLosReintentosSeDistingueDelRechazo(): void
    {
        // El worker del outbox tiene que poder distinguir lo transitorio
        // —a lo que vale la pena volver— del rechazo definitivo, que solo
        // llenaría la cola.
        $caido = new RespuestaHttp(0, '', 'timeout', 15000);
        $http = $this->http([$caido, $caido, $caido]);

        try {
            $this->chatwoot($http)->notaPrivada(42, 'texto');
            self::fail('debió lanzar');
        } catch (ChatwootNoDisponible $e) {
            self::assertTrue($e->agotoReintentos);
            self::assertSame(3, $e->intentos);
        }
    }

    #[Test]
    public function sinConfigurarFallaSinIntentarSalirALaRed(): void
    {
        $http = $this->http([$this->ok()]);

        $chatwoot = new ChatwootApi(
            $http,
            $this->config(sombra: true),
            Logger::desdeEntorno(),
            '',
            '1',
            '',
            null,
            static fn (int $ms): null => null,
        );

        $this->expectException(ChatwootNoDisponible::class);

        try {
            $chatwoot->notaPrivada(42, 'texto');
        } finally {
            self::assertSame([], $http->llamadas);
        }
    }

    // ── Higiene de la bandeja ────────────────────────────────────────────

    #[Test]
    public function noSeEnviaUnMensajeVacio(): void
    {
        $http = $this->http([$this->ok()]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->chatwoot($http)->notaPrivada(42, "   \n  ");
        } finally {
            self::assertSame([], $http->llamadas);
        }
    }

    #[Test]
    public function lasEtiquetasSeNormalizanParaNoDuplicarse(): void
    {
        // Sin esto acabas con «Urgente», «urgente» y «URGENTE» como tres
        // etiquetas distintas en la bandeja.
        $http = $this->http([$this->ok()]);

        $this->chatwoot($http)->etiquetar(42, ['Urgente', 'urgente', 'Acto Administrativo', '  ']);

        self::assertSame(
            ['urgente', 'acto-administrativo'],
            array_values($http->llamadas[0]['cuerpo']['labels']),
        );
    }

    #[Test]
    public function unaPrioridadInventadaEsUnErrorDeProgramacion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->chatwoot($this->http([]))->cambiarPrioridad(42, 'altisima');
    }

    #[Test]
    public function sinAgenteConfiguradoElEscalamientoSigueOcurriendo(): void
    {
        // Un escalamiento sin asignar está peor atendido; uno que revienta
        // por no encontrar al agente no se atiende en absoluto.
        $http = $this->http([]);

        $this->chatwoot($http, agente: null)->asignarAlAbogado(42);

        self::assertSame([], $http->llamadas);
    }

    #[Test]
    public function unaRespuestaSinIdNoSeReintenta(): void
    {
        // Chatwoot aceptó: el mensaje está en el hilo. Reintentar lo
        // duplicaría por no haber podido leer un número.
        $http = $this->http([new RespuestaHttp(200, '{"ok":true}', null, 50)]);

        self::assertSame(0, $this->chatwoot($http)->notaPrivada(42, 'texto'));
        self::assertCount(1, $http->llamadas);
    }

    #[Test]
    public function sincronizarAgenteNoReCreaAlQueYaTieneId(): void
    {
        $http = $this->http([]);

        $usuario = new Usuario(
            id: 'u1',
            email: 'pedro@despacho.co',
            nombre: 'Pedro',
            rol: 'abogado',
            rolId: 2,
            chatwootAgentId: 99,
            totpActivo: true,
            activo: true,
            intentosFallidos: 0,
            bloqueadoHasta: null,
        );

        self::assertSame(99, $this->chatwoot($http)->sincronizarAgente($usuario));
        self::assertSame([], $http->llamadas);
    }
}
