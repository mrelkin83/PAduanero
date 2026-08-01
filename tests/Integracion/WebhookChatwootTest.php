<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Motor\ConstructorPrompt;
use App\Motor\MotorConversacional;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\CasoRepo;
use App\Repositorios\ConsentimientoRepo;
use App\Repositorios\ContactoRepo;
use App\Repositorios\ConversacionEstadoRepo;
use App\Repositorios\PromptRepo;
use App\Servicios\ClientesLlm\ClienteAnthropic;
use App\Servicios\Config;
use App\Servicios\Credenciales;
use App\Servicios\GateDorado;
use App\Servicios\Llm;
use App\Servicios\OutboxMysql;
use App\Servicios\WebhookChatwoot;
use App\Soporte\Cifrado;
use App\Soporte\Http;
use App\Soporte\Logger;
use App\Soporte\RespuestaHttp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * La puerta de entrada.
 *
 * Lo que se defiende aquí: que no entre quien no debe, que un agente humano
 * apague la IA, y que los eventos que no interesan no hagan que Chatwoot los
 * reintente para siempre.
 */
#[Group('critica')]
final class WebhookChatwootTest extends CasoBaseBd
{
    private const SECRETO = 'secreto-de-prueba-suficientemente-largo';

    private ConversacionEstadoRepo $conversaciones;
    private ContactoRepo $contactos;
    private ConsentimientoRepo $consentimientos;

    protected function setUp(): void
    {
        parent::setUp();

        $cifrado = Cifrado::desdeEntorno();
        $this->contactos = new ContactoRepo($this->bd, $cifrado, new AuditoriaRepo($this->bd));
        $this->consentimientos = new ConsentimientoRepo($this->bd);
        $this->conversaciones = new ConversacionEstadoRepo($this->bd);

        $promptId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO prompts (id, clave, version, contenido, activo) VALUES (?, ?, 1, ?, 1)'
        )->execute([$promptId, GateDorado::CLAVE_PROMPT, 'Eres el asistente del despacho.']);

        $this->bd->pdo()->prepare(
            "UPDATE modelos_ia
                SET costos_verificados = 1, activo = 1, es_primario = 1, orden_fallback = 0,
                    dorado_estado = 'verde', dorado_en = NOW(), dorado_prompt_id = ?
              WHERE identificador = 'claude-opus-5'"
        )->execute([$promptId]);
    }

    private function webhook(string $secreto = self::SECRETO): WebhookChatwoot
    {
        $http = new class extends Http {
            public function pedir(string $metodo, string $url, array $cabeceras = [], ?array $json = null): RespuestaHttp
            {
                return new RespuestaHttp(200, json_encode([
                    'content' => [['type' => 'text', 'text' => 'Cuénteme más.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], JSON_THROW_ON_ERROR), null, 100);
            }
        };

        $config = new class implements Config {
            /** @var array<string,mixed> */
            public array $valores = [
                'motor_ia_pausado' => false,
                'motor_modo_sombra' => true,
                'max_turnos_ia' => 40,
                'ventana_rafaga_segundos' => 8,
            ];

            public function get(string $clave, mixed $porDefecto = null): mixed
            {
                return array_key_exists($clave, $this->valores) ? $this->valores[$clave] : $porDefecto;
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

        $credenciales = new class implements Credenciales {
            public function obtener(string $servicio, string $clave, string $entorno = 'produccion'): string
            {
                return 'sk';
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

        $gate = new GateDorado($this->bd);
        $outbox = new OutboxMysql($this->bd);

        $motor = new MotorConversacional(
            $this->contactos,
            $this->consentimientos,
            new CasoRepo($this->bd),
            $this->conversaciones,
            new Llm($this->bd, $credenciales, $config, $gate, Logger::desdeEntorno(), [new ClienteAnthropic($http)]),
            $outbox,
            $config,
            Logger::desdeEntorno(),
            new ConstructorPrompt(new PromptRepo($this->bd), $gate),
        );

        return new WebhookChatwoot(
            $motor,
            $this->conversaciones,
            $outbox,
            Logger::desdeEntorno(),
            $secreto,
        );
    }

    /** @return array<string,mixed> */
    private function entrante(string $texto, int $conv = 77): array
    {
        return [
            'event' => 'message_created',
            'id' => 1001,
            'content' => $texto,
            'message_type' => 'incoming',
            'private' => false,
            'conversation' => ['id' => $conv],
            'sender' => ['id' => 5, 'type' => 'contact', 'phone_number' => '+573001112233'],
        ];
    }

    // ── Autenticación ────────────────────────────────────────────────────

    #[Test]
    public function sinElSecretoNoSeEntra(): void
    {
        $webhook = $this->webhook();

        self::assertFalse($webhook->autenticado(null));
        self::assertFalse($webhook->autenticado(''));
        self::assertFalse($webhook->autenticado('otro-secreto'));
        self::assertTrue($webhook->autenticado(self::SECRETO));
    }

    #[Test]
    public function sinSecretoConfiguradoElEndpointQuedaCerrado(): void
    {
        // Abrirlo «mientras tanto» es como se queda abierto.
        $webhook = $this->webhook(secreto: '');

        self::assertFalse($webhook->autenticado(''));
        self::assertFalse($webhook->autenticado('lo-que-sea'));
    }

    // ── Regla 8: el agente humano apaga la IA ────────────────────────────

    #[Test]
    public function cuandoUnAgenteEscribeLaIaSeApaga(): void
    {
        $this->conversaciones->buscarOCrear(77);

        $resultado = $this->webhook()->manejar([
            'event' => 'message_created',
            'id' => 2002,
            'content' => 'Buenas, soy Pedro, yo le atiendo.',
            'message_type' => 'outgoing',
            'private' => false,
            'conversation' => ['id' => 77],
            'sender' => ['id' => 3, 'type' => 'user'],
        ]);

        self::assertSame('ia_apagada', $resultado['accion']);
        self::assertFalse($this->conversaciones->porConversacion(77)->iaActiva);
    }

    #[Test]
    public function elBorradorDelPropioBotNoApagaLaIa(): void
    {
        // En modo sombra el bot escribe notas privadas. Si contaran como
        // «un humano tomó la conversación», el motor se apagaría solo en
        // cuanto escribiera su primera respuesta.
        $this->conversaciones->buscarOCrear(77);

        $resultado = $this->webhook()->manejar([
            'event' => 'message_created',
            'id' => 2003,
            'content' => '🤖 BORRADOR DEL MOTOR — no se ha enviado al contacto',
            'message_type' => 'outgoing',
            'private' => true,
            'conversation' => ['id' => 77],
            'sender' => ['id' => 9, 'type' => 'agent_bot'],
        ]);

        self::assertSame('ignorado', $resultado['accion']);
        self::assertTrue($this->conversaciones->porConversacion(77)->iaActiva);
    }

    #[Test]
    public function anteUnRemitenteDesconocidoSeAsumeHumano(): void
    {
        // Apagar de más cuesta que Pedro la reactive; apagar de menos cuesta
        // que el bot conteste por encima de él en el mismo hilo.
        $this->conversaciones->buscarOCrear(77);

        $resultado = $this->webhook()->manejar([
            'event' => 'message_created',
            'id' => 2004,
            'content' => 'texto',
            'message_type' => 'outgoing',
            'private' => false,
            'conversation' => ['id' => 77],
            // sin `sender`
        ]);

        self::assertSame('ia_apagada', $resultado['accion']);
    }

    // ── Eventos que no interesan ─────────────────────────────────────────

    #[Test]
    public function losEventosQueNoInteresanSeIgnoranSinError(): void
    {
        // Devolver error haría que Chatwoot los reintentara para siempre.
        foreach (['conversation_created', 'webwidget_triggered', 'contact_updated'] as $tipo) {
            $resultado = $this->webhook()->manejar(['event' => $tipo]);

            self::assertSame('ignorado', $resultado['accion']);
        }
    }

    #[Test]
    public function unMensajeSinTextoNoSeContesta(): void
    {
        // Audio, imagen o documento. Callarse es mejor que responder a algo
        // que no se leyó.
        $evento = $this->entrante('');
        $evento['attachments'] = [['file_type' => 'audio']];

        self::assertSame('ignorado', $this->webhook()->manejar($evento)['accion']);
    }

    // ── El camino normal ─────────────────────────────────────────────────

    #[Test]
    public function unMensajeEntranteLlegaAlMotor(): void
    {
        $resultado = $this->webhook()->manejar($this->entrante('Hola, tengo un problema'));

        // Sin consentimiento todavía: el motor pide el aviso.
        self::assertSame('silencio', $resultado['accion']);
        self::assertNotNull($this->contactos->porTelefono('573001112233'));

        $pendientes = $this->bd->pdo()
            ->query("SELECT COUNT(*) FROM eventos_outbox WHERE tipo = 'chatwoot.entregar'")
            ->fetchColumn();

        self::assertSame(1, (int) $pendientes, 'se encoló el aviso de habeas data');
    }

    #[Test]
    public function elTelefonoSeNormalizaAE164SinSigno(): void
    {
        $this->webhook()->manejar($this->entrante('hola'));

        self::assertNotNull($this->contactos->porTelefono('573001112233'));
    }

    #[Test]
    public function elReintentoInmediatoNoDuplicaElTurno(): void
    {
        // Guarda parcial: cubre el reintento mientras la ráfaga sigue abierta,
        // que es el caso frecuente. El reintento tardío necesita la columna
        // propuesta en PLAN_BUILD §Etapa 4.
        $contacto = $this->contactos->crear('573001112233', 'whatsapp');
        $this->consentimientos->registrar($contacto->id, 'v1', 'Aviso', otorgado: true);

        $webhook = $this->webhook();
        $webhook->manejar($this->entrante('me llegó un requerimiento'));
        $segundo = $webhook->manejar($this->entrante('me llegó un requerimiento'));

        self::assertSame('duplicado', $segundo['accion']);
        self::assertCount(1, $this->conversaciones->porConversacion(77)->buffer);
    }

    #[Test]
    public function unaSenalCriticaEscalaDesdeElWebhook(): void
    {
        $resultado = $this->webhook()->manejar($this->entrante('La POLFA está en mi bodega'));

        self::assertSame('escalo', $resultado['accion']);
        self::assertFalse($this->conversaciones->porConversacion(77)->iaActiva);
    }
}
