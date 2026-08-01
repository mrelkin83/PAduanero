<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Excepciones\LlmException;
use App\Servicios\ClientesLlm\ClienteAnthropic;
use App\Servicios\Config;
use App\Servicios\Credenciales;
use App\Servicios\GateDorado;
use App\Servicios\Llm;
use App\Soporte\Http;
use App\Soporte\Logger;
use App\Soporte\RespuestaHttp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Las tres invariantes del servicio `Llm`.
 *
 *  1. `consumo_ia` se escribe siempre, también en el fallo.
 *  2. El presupuesto corta ANTES de llamar, y un modelo sin costo no entra.
 *  3. La cascada no cae en un modelo sin firma; si no hay ninguno, escala.
 *
 * Las tres fallan en silencio si se relajan, que es exactamente por lo que
 * tienen prueba propia.
 */
#[Group('critica')]
final class LlmTest extends CasoBaseBd
{
    private string $promptId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->promptId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO prompts (id, clave, version, contenido, activo) VALUES (?, ?, 1, ?, 1)'
        )->execute([$this->promptId, GateDorado::CLAVE_PROMPT, 'Eres el asistente del despacho.']);
    }

    /** @param list<RespuestaHttp> $respuestas */
    private function http(array $respuestas): Http
    {
        return new class ($respuestas) extends Http {
            /** @var list<string> */
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
                $this->llamadas[] = (string) ($json['model'] ?? '?');

                return array_shift($this->respuestas)
                    ?? new RespuestaHttp(500, '', 'sin respuesta preparada', 0);
            }
        };
    }

    private function respuestaOk(string $texto = 'Cuénteme qué pasó.'): RespuestaHttp
    {
        return new RespuestaHttp(200, json_encode([
            'content' => [['type' => 'text', 'text' => $texto]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ], JSON_THROW_ON_ERROR), null, 250);
    }

    private function credencialesFalsas(): Credenciales
    {
        return new class implements Credenciales {
            public function obtener(string $servicio, string $clave, string $entorno = 'produccion'): string
            {
                return 'sk-de-prueba';
            }

            public function guardar(string $servicio, string $clave, string $valor, string $entorno, string $usuarioId): array
            {
                return ['mascara' => '****'];
            }

            public function probar(string $servicio, string $entorno): array
            {
                return ['ok' => true, 'mensaje' => ''];
            }

            public function rotarClaveMaestra(string $nuevaClave): void
            {
            }
        };
    }

    private function llm(Http $http): Llm
    {
        return new Llm(
            $this->bd,
            $this->credencialesFalsas(),
            $this->config(),
            new GateDorado($this->bd),
            Logger::desdeEntorno(),
            [new ClienteAnthropic($http)],
        );
    }

    private function config(): Config
    {
        return new class implements Config {
            /** @var array<string,mixed> */
            public array $valores = ['presupuesto_ia_mensual_usd' => 100];

            public function get(string $clave, mixed $porDefecto = null): mixed
            {
                return $this->valores[$clave] ?? $porDefecto;
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

    /**
     * Deja un modelo listo para responder: activo, con costo verificado y con
     * conjunto dorado en verde contra el prompt vigente.
     */
    private function autorizar(string $identificador, bool $primario = false, int $ordenFallback = 9): string
    {
        $pdo = $this->bd->pdo();

        $pdo->prepare(
            'UPDATE modelos_ia
                SET costos_verificados = 1, activo = 1, orden_fallback = ?,
                    dorado_estado = \'verde\', dorado_en = NOW(), dorado_prompt_id = ?
              WHERE identificador = ?'
        )->execute([$ordenFallback, $this->promptId, $identificador]);

        if ($primario) {
            $pdo->prepare('UPDATE modelos_ia SET es_primario = 1 WHERE identificador = ?')
                ->execute([$identificador]);
        }

        $stmt = $pdo->prepare('SELECT id FROM modelos_ia WHERE identificador = ?');
        $stmt->execute([$identificador]);

        return (string) $stmt->fetchColumn();
    }

    private function consumo(): array
    {
        return $this->bd->pdo()
            ->query('SELECT * FROM consumo_ia ORDER BY id')
            ->fetchAll();
    }

    // ── Camino feliz ─────────────────────────────────────────────────────

    #[Test]
    public function respondeElPrimarioYRegistraElConsumo(): void
    {
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $respuesta = $this->llm($this->http([$this->respuestaOk()]))
            ->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);

        self::assertSame('Cuénteme qué pasó.', $respuesta->texto);
        self::assertFalse($respuesta->huboFallback);

        $filas = $this->consumo();
        self::assertCount(1, $filas);
        self::assertSame(1, (int) $filas[0]['exito']);
        self::assertSame(1000, (int) $filas[0]['tokens_entrada']);
        // 1000 entrada a 5 USD/1M + 500 salida a 25 USD/1M = 0,005 + 0,0125
        self::assertSame('0.017500', $filas[0]['costo_usd']);
    }

    // ── Invariante 1: el fallo también deja huella ───────────────────────

    #[Test]
    public function unTimeoutTambienEscribeEnConsumoIa(): void
    {
        // Un timeout que no deja huella hace que el gasto y la tasa de error
        // mientan: el panel enseña un sistema sano que está quemando
        // reintentos contra un proveedor caído.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $http = $this->http([new RespuestaHttp(0, '', 'Operation timed out', 15000)]);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
            self::fail('debió lanzar LlmException');
        } catch (LlmException $e) {
            self::assertSame('proveedor', $e->motivo);
        }

        $filas = $this->consumo();
        self::assertCount(1, $filas);
        self::assertSame(0, (int) $filas[0]['exito']);
        self::assertStringContainsString('timed out', (string) $filas[0]['error']);
        self::assertSame(15000, (int) $filas[0]['latencia_ms']);
    }

    #[Test]
    public function cadaEscalonDeLaCascadaDejaSuPropiaFila(): void
    {
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);
        $this->autorizar('claude-sonnet-5', ordenFallback: 1);

        $http = $this->http([
            new RespuestaHttp(503, '{"error":"overloaded"}', null, 800),
            $this->respuestaOk('Respondió el suplente.'),
        ]);

        $respuesta = $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);

        self::assertTrue($respuesta->huboFallback);
        self::assertSame('claude-sonnet-5', $respuesta->modeloIdentificador);
        self::assertSame(['claude-opus-5', 'claude-sonnet-5'], $http->llamadas);

        $filas = $this->consumo();
        self::assertCount(2, $filas);
        self::assertSame(0, (int) $filas[0]['exito']);
        self::assertSame(1, (int) $filas[1]['exito']);
    }

    #[Test]
    public function unErrorNuestroNoBajaLaCascada(): void
    {
        // Un 400 va a fallar igual en el suplente. Bajar solo multiplica
        // latencia y gasto antes de dar el mismo error.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);
        $this->autorizar('claude-sonnet-5', ordenFallback: 1);

        $http = $this->http([
            new RespuestaHttp(400, '{"error":"invalid_request"}', null, 90),
            $this->respuestaOk(),
        ]);

        $this->expectException(LlmException::class);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
        } finally {
            self::assertSame(['claude-opus-5'], $http->llamadas);
        }
    }

    // ── Invariante 2: el presupuesto ─────────────────────────────────────

    #[Test]
    public function elPresupuestoCortaAntesDeLlamar(): void
    {
        // Después sería un informe del daño, no un tope. Se comprueba que NO
        // hubo llamada HTTP, no solo que lanzó.
        $modeloId = $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $this->bd->pdo()->prepare(
            'INSERT INTO consumo_ia (modelo_id, tokens_entrada, tokens_salida, costo_usd)
             VALUES (?, 0, 0, 150)'
        )->execute([$modeloId]);

        $http = $this->http([$this->respuestaOk()]);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
            self::fail('debió lanzar LlmException');
        } catch (LlmException $e) {
            self::assertSame('presupuesto', $e->motivo);
        }

        self::assertSame([], $http->llamadas, 'no puede haber salido ninguna petición');
    }

    #[Test]
    public function elGastoDelMesAnteriorNoCuentaContraEsteMes(): void
    {
        $modeloId = $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $this->bd->pdo()->prepare(
            'INSERT INTO consumo_ia (modelo_id, costo_usd, creado_en)
             VALUES (?, 500, DATE_SUB(NOW(), INTERVAL 2 MONTH))'
        )->execute([$modeloId]);

        $respuesta = $this->llm($this->http([$this->respuestaOk()]))
            ->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);

        self::assertNotSame('', $respuesta->texto);
    }

    #[Test]
    public function unModeloSinCostoNoEntraEnLaCascada(): void
    {
        // Es el guardia que deja de guardar: a costo cero el presupuesto no
        // se agota jamás. Se marca verificado pero con costo NULL, que es la
        // combinación tramposa.
        $this->bd->pdo()->exec(
            "UPDATE modelos_ia
                SET costos_verificados = 1, activo = 1, es_primario = 0,
                    dorado_estado = 'verde',
                    costo_entrada_usd_1m = NULL, costo_salida_usd_1m = NULL
              WHERE identificador = 'claude-opus-5'"
        );

        $http = $this->http([$this->respuestaOk()]);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
            self::fail('debió lanzar LlmException');
        } catch (LlmException $e) {
            self::assertSame('sin_modelo_autorizado', $e->motivo);
        }

        self::assertSame([], $http->llamadas);
    }

    // ── Invariante 3: la cascada no salta el gate ────────────────────────

    #[Test]
    public function unSuplenteSinConjuntoDoradoNoResponde(): void
    {
        // El caso que convierte la firma en decorativa si se hace mal: el
        // primario cae y el suplente contesta sin haber pasado el gate.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $this->bd->pdo()->exec(
            "UPDATE modelos_ia
                SET costos_verificados = 1, activo = 1, orden_fallback = 1,
                    dorado_estado = 'sin_correr'
              WHERE identificador = 'claude-sonnet-5'"
        );

        $http = $this->http([
            new RespuestaHttp(503, '{"error":"overloaded"}', null, 800),
            $this->respuestaOk('No debería llegar aquí.'),
        ]);

        $this->expectException(LlmException::class);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
        } finally {
            self::assertSame(
                ['claude-opus-5'],
                $http->llamadas,
                'el suplente sin firma no puede recibir la petición',
            );
        }
    }

    #[Test]
    public function siElPromptCambioLaCascadaSeQuedaSinModelos(): void
    {
        // El verde de ayer no dice nada sobre lo que el bot diría hoy. La
        // conducta correcta es escalar, no responder.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $this->bd->pdo()->exec('UPDATE prompts SET activo = 0');
        $otro = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO prompts (id, clave, version, contenido, activo) VALUES (?, ?, 2, ?, 1)'
        )->execute([$otro, GateDorado::CLAVE_PROMPT, 'Prompt nuevo.']);

        $http = $this->http([$this->respuestaOk()]);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
            self::fail('debió lanzar LlmException');
        } catch (LlmException $e) {
            self::assertSame('sin_modelo_autorizado', $e->motivo);
        }

        self::assertSame([], $http->llamadas);
    }

    #[Test]
    public function laCascadaNoBajaAUnModeloDeOtroProposito(): void
    {
        // Un modelo de embeddings pasa el gate —no le dice nada a nadie— pero
        // no puede acabar contestando un chat.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $pdo = $this->bd->pdo();
        $proveedorId = $pdo->query("SELECT id FROM proveedores_ia WHERE clave='anthropic'")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO modelos_ia
                (id, proveedor_id, identificador, nombre_visible, proposito,
                 costo_entrada_usd_1m, costo_salida_usd_1m, costos_verificados,
                 activo, orden_fallback)
             VALUES (UUID(), ?, ?, ?, \'embeddings\', 0.1, 0.1, 1, 1, 1)'
        )->execute([$proveedorId, 'text-embedding-3-large', 'Embeddings']);

        $http = $this->http([
            new RespuestaHttp(503, '{}', null, 800),
            $this->respuestaOk('No debería llegar aquí.'),
        ]);

        $this->expectException(LlmException::class);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
        } finally {
            self::assertSame(['claude-opus-5'], $http->llamadas);
        }
    }

    #[Test]
    public function unModeloRetiradoNoSeUsaNiComoSuplente(): void
    {
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);
        $this->autorizar('claude-sonnet-5', ordenFallback: 1);

        $this->bd->pdo()->exec(
            "UPDATE modelos_ia SET retirado_en = NOW() WHERE identificador = 'claude-sonnet-5'"
        );

        $http = $this->http([new RespuestaHttp(503, '{}', null, 800), $this->respuestaOk()]);

        $this->expectException(LlmException::class);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
        } finally {
            self::assertSame(['claude-opus-5'], $http->llamadas);
        }
    }

    #[Test]
    public function desactivarElProveedorApagaSusModelos(): void
    {
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);
        $this->bd->pdo()->exec("UPDATE proveedores_ia SET activo = 0 WHERE clave = 'anthropic'");

        $http = $this->http([$this->respuestaOk()]);

        try {
            $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
            self::fail('debió lanzar LlmException');
        } catch (LlmException $e) {
            self::assertSame('sin_modelo_autorizado', $e->motivo);
        }

        self::assertSame([], $http->llamadas);
    }

    // ── Respuestas inutilizables ─────────────────────────────────────────

    #[Test]
    public function unaRespuestaVaciaNoSeDaPorBuena(): void
    {
        // `max_tokens` acota pensamiento MÁS respuesta: un tope corto puede
        // agotarse pensando y devolver texto vacío. Enviarle eso al contacto
        // es un mensaje en blanco por WhatsApp.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);
        $this->autorizar('claude-sonnet-5', ordenFallback: 1);

        $http = $this->http([
            new RespuestaHttp(200, json_encode([
                'content' => [],
                'stop_reason' => 'max_tokens',
                'usage' => ['input_tokens' => 900, 'output_tokens' => 600],
            ], JSON_THROW_ON_ERROR), null, 3000),
            $this->respuestaOk('Respondió el suplente.'),
        ]);

        $respuesta = $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);

        self::assertSame('Respondió el suplente.', $respuesta->texto);
        self::assertStringContainsString(
            'max_tokens',
            (string) $this->consumo()[0]['error'],
        );
    }

    #[Test]
    public function unaNegativaDelClasificadorNoRevientaAlLeerElContenido(): void
    {
        // Llega como 200 con `content` vacío. Leer content[0] sin comprobar
        // el `stop_reason` revienta con una respuesta exitosa.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $http = $this->http([
            new RespuestaHttp(200, json_encode([
                'content' => [],
                'stop_reason' => 'refusal',
                'usage' => ['input_tokens' => 200, 'output_tokens' => 0],
            ], JSON_THROW_ON_ERROR), null, 400),
        ]);

        $this->expectException(LlmException::class);

        $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);
    }

    #[Test]
    public function elPensamientoDelModeloNoLlegaAlContacto(): void
    {
        // `content` es una lista de bloques y no todos son texto. Concatenar
        // todo enviaría el razonamiento del modelo a un cliente por WhatsApp.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $http = $this->http([new RespuestaHttp(200, json_encode([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'El plazo de respuesta es de 15 días hábiles.'],
                ['type' => 'text', 'text' => 'Cuénteme qué documento recibió.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ], JSON_THROW_ON_ERROR), null, 300)]);

        $respuesta = $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);

        self::assertSame('Cuénteme qué documento recibió.', $respuesta->texto);
        self::assertStringNotContainsString('15 días', $respuesta->texto);
    }

    // ── La petición que sale ─────────────────────────────────────────────

    #[Test]
    public function noSeEnviaTemperatureAAnthropic(): void
    {
        // Los modelos actuales de la familia Opus/Sonnet 5 la rechazan con
        // 400. Enviarla porque la columna existe rompería toda llamada.
        $this->autorizar('claude-opus-5', primario: true, ordenFallback: 0);

        $capturado = null;

        $http = new class ($this->respuestaOk(), $capturado) extends Http {
            /** @var array<string,mixed>|null */
            public ?array $cuerpo = null;

            public function __construct(private readonly RespuestaHttp $r, mixed $ignorado)
            {
                parent::__construct();
            }

            public function pedir(string $metodo, string $url, array $cabeceras = [], ?array $json = null): RespuestaHttp
            {
                $this->cuerpo = $json;

                return $this->r;
            }
        };

        $this->llm($http)->chat('Sistema', [['role' => 'user', 'content' => 'hola']]);

        self::assertArrayNotHasKey('temperature', (array) $http->cuerpo);
        self::assertArrayNotHasKey('thinking', (array) $http->cuerpo);
        self::assertSame('Sistema', $http->cuerpo['system']);
    }
}
