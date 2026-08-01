<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Modelos\Contacto;
use App\Motor\Agenda;
use App\Motor\ConstructorPrompt;
use App\Motor\Estados;
use App\Motor\MotorConversacional;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\CasoRepo;
use App\Repositorios\ConsentimientoRepo;
use App\Repositorios\ConsultaRepo;
use App\Repositorios\ContactoRepo;
use App\Repositorios\ConversacionEstadoRepo;
use App\Repositorios\PromptRepo;
use App\Servicios\ClientesLlm\ClienteAnthropic;
use App\Servicios\Config;
use App\Servicios\Credenciales;
use App\Servicios\GateDorado;
use App\Servicios\Llm;
use App\Servicios\OutboxMysql;
use App\Servicios\Pagos;
use App\Soporte\Cifrado;
use App\Soporte\Fechas;
use App\Soporte\Http;
use App\Soporte\Logger;
use App\Soporte\RespuestaHttp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * La agenda dentro del motor (Etapa 5): el modelo propone, la base dispone.
 *
 * Lo que se defiende aquí: **nada factual sale del LLM.** Horarios, precio
 * y enlace de pago los pone el sistema en un apéndice de plantilla; el
 * texto del modelo solo acompaña. Y todo lo que la acción trae —fechas,
 * ids— se valida como dato sucio (regla 12).
 */
#[Group('critica')]
final class AgendaMotorTest extends CasoBaseBd
{
    private const TELEFONO = '573001112233';
    private const CONV = 42;

    /** @var list<array{consultaId:string,montoPesos:int}> */
    private array $linksCreados = [];

    private ContactoRepo $contactos;
    private ConsentimientoRepo $consentimientos;
    private ConversacionEstadoRepo $conversaciones;
    private ConsultaRepo $consultas;
    private OutboxMysql $outbox;
    private string $fecha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linksCreados = [];
        $this->contactos = new ContactoRepo($this->bd, Cifrado::desdeEntorno(), new AuditoriaRepo($this->bd));
        $this->consentimientos = new ConsentimientoRepo($this->bd);
        $this->conversaciones = new ConversacionEstadoRepo($this->bd);
        $this->consultas = new ConsultaRepo($this->bd);
        $this->outbox = new OutboxMysql($this->bd);

        // Una fecha dentro de la ventana de agendamiento, siempre a futuro:
        // las pruebas no pueden depender del día en que se corran.
        $this->fecha = Fechas::ahora()->modify('+7 days')->format('Y-m-d');

        $promptId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO prompts (id, clave, version, contenido, activo) VALUES (?, ?, 1, ?, 1)'
        )->execute([$promptId, GateDorado::CLAVE_PROMPT, 'Eres el asistente del despacho.']);

        $this->bd->pdo()->exec(
            "UPDATE modelos_ia
                SET costos_verificados = 1, activo = 1, es_primario = 1, orden_fallback = 0
              WHERE identificador = 'claude-opus-5'"
        );

        // Horario garantizado para el día de la semana de la fecha elegida —
        // solo si las semillas no traen ya una franja para ese día, porque
        // dos franjas solapadas duplican los cupos listados.
        $dia = Fechas::diaSemana($this->fecha);
        $hay = $this->bd->pdo()->prepare('SELECT COUNT(*) FROM horarios WHERE dia_semana = ? AND activo = 1');
        $hay->execute([$dia]);

        if ((int) $hay->fetchColumn() === 0) {
            $this->bd->pdo()->prepare(
                "INSERT INTO horarios (dia_semana, hora_inicio, hora_fin, activo)
                 VALUES (?, '09:00:00', '18:00:00', 1)"
            )->execute([$dia]);
        }
    }

    /** Motor con la agenda conectada y un Pagos espía. */
    private function motor(string $respuestaModelo): MotorConversacional
    {
        $http = new class ($respuestaModelo) extends Http {
            public function __construct(private readonly string $texto)
            {
                parent::__construct();
            }

            public function pedir(string $m, string $u, array $c = [], ?array $json = null): RespuestaHttp
            {
                return new RespuestaHttp(200, json_encode([
                    'content' => [['type' => 'text', 'text' => $this->texto]],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 500, 'output_tokens' => 200],
                ], JSON_THROW_ON_ERROR), null, 300);
            }
        };

        $config = $this->config();
        $gate = new GateDorado($this->bd);

        return new MotorConversacional(
            $this->contactos,
            $this->consentimientos,
            new CasoRepo($this->bd),
            $this->conversaciones,
            new Llm($this->bd, $this->credenciales(), $config, $gate, Logger::desdeEntorno(), [new ClienteAnthropic($http)]),
            $this->outbox,
            $config,
            Logger::desdeEntorno(),
            new ConstructorPrompt(new PromptRepo($this->bd), $gate),
            new Agenda($this->consultas, $this->pagosEspia(), $config, Logger::desdeEntorno()),
        );
    }

    private function pagosEspia(): Pagos
    {
        $links = &$this->linksCreados;

        return new class ($links) implements Pagos {
            public function __construct(private array &$links)
            {
            }

            public function crearLink(string $consultaId, int $montoPesos, string $descripcion, Contacto $contacto): array
            {
                $this->links[] = ['consultaId' => $consultaId, 'montoPesos' => $montoPesos];

                return [
                    'url' => 'https://checkout.wompi.co/p/?reference=PA-prueba',
                    'referencia' => 'PA-prueba',
                    'pagoId' => 'pago-1',
                    'expiraEn' => new \DateTimeImmutable('+45 minutes'),
                ];
            }

            public function verificarWebhook(string $cuerpoCrudo, array $cabeceras): array
            {
                return ['valido' => false, 'referencia' => '', 'estado' => ''];
            }

            public function procesarWebhook(string $cuerpoCrudo, array $cabeceras): array
            {
                return ['valido' => false, 'procesado' => false, 'referencia' => '', 'estado' => ''];
            }

            public function consultarEstado(string $referencia): array
            {
                return ['encontrado' => false, 'estado' => '', 'monto_centavos' => 0];
            }
        };
    }

    private function config(): Config
    {
        return new class implements Config {
            public function get(string $clave, mixed $porDefecto = null): mixed
            {
                return [
                    'motor_ia_pausado' => false,
                    'motor_modo_sombra' => true,
                    'max_turnos_ia' => 40,
                    'minutos_reserva_pago' => 45,
                    'dias_max_anticipacion' => 30,
                    'presupuesto_ia_mensual_usd' => 100,
                ][$clave] ?? $porDefecto;
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
    }

    private function credenciales(): Credenciales
    {
        return new class implements Credenciales {
            public function obtener(string $servicio, string $clave, string $entorno = 'produccion'): string
            {
                return 'sk-de-prueba';
            }

            public function guardar(string $s, string $c, string $v, string $e, string $u): array
            {
                return ['mascara' => '****'];
            }

            public function probar(string $s, string $e): array
            {
                return ['ok' => true, 'mensaje' => ''];
            }

            public function rotarClaveMaestra(string $n): void
            {
            }
        };
    }

    /** Contacto con consentimiento, caso vinculado y un turno despachado. */
    private function turno(string $respuestaModelo, ?string $casoId = null): string
    {
        $contacto = $this->contactos->porTelefono(self::TELEFONO)
            ?? $this->contactos->crear(self::TELEFONO, 'whatsapp');

        if ($this->consentimientos->ultimoPorContacto($contacto->id) === null) {
            $this->consentimientos->registrar($contacto->id, 'v1', 'Aviso', otorgado: true);
        }

        $motor = $this->motor($respuestaModelo);
        $motor->procesar(self::CONV, self::TELEFONO, 'mensaje del contacto');

        if ($casoId !== null) {
            $this->conversaciones->vincularCaso(self::CONV, $casoId);
        }

        $this->bd->pdo()->exec(
            'UPDATE conversacion_estado SET buffer_hasta = DATE_SUB(NOW(), INTERVAL 1 SECOND)'
        );
        $motor->despacharRafaga(self::CONV);

        $textos = array_map(
            static fn (array $e): string => (string) (json_decode((string) $e['payload'], true)['texto'] ?? ''),
            array_filter(
                $this->eventos('chatwoot.entregar'),
                static fn (array $e): bool => true,
            ),
        );

        return implode("\n---\n", $textos);
    }

    private function crearCaso(): string
    {
        $contacto = $this->contactos->porTelefono(self::TELEFONO)
            ?? $this->contactos->crear(self::TELEFONO, 'whatsapp');

        return (new CasoRepo($this->bd))->crear($contacto->id, ['tipo_caso' => 'decomiso'])->id;
    }

    /** @return list<array<string,mixed>> */
    private function eventos(string $tipo): array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM eventos_outbox WHERE tipo = ? ORDER BY id');
        $stmt->execute([$tipo]);

        return $stmt->fetchAll();
    }

    // ── PROPONER_ASESORIA ────────────────────────────────────────────────

    #[Test]
    public function proponerReservaElCupoYAdjuntaElEnlaceDePago(): void
    {
        $casoId = $this->crearCaso();

        $salida = $this->turno(
            'Con gusto le reservo. '
            . '{"accion":"PROPONER_ASESORIA","fecha":"' . $this->fecha . '","horaInicio":"15:00"}',
            $casoId,
        );

        // La reserva es real y con expiración (regla 7).
        $consulta = $this->bd->pdo()->query("SELECT * FROM consultas")->fetch();

        self::assertNotFalse($consulta);
        self::assertSame('reservada', $consulta['estado']);
        self::assertSame('15:00:00', $consulta['hora_inicio']);
        self::assertNotNull($consulta['reserva_expira']);

        // El precio que viajó a la pasarela es el de la MODALIDAD, en pesos
        // (ADR-010): el LLM no lo dijo y no podría cambiarlo.
        self::assertSame([['consultaId' => $consulta['id'], 'montoPesos' => 400000]], $this->linksCreados);

        // El enlace va en el apéndice del mensaje.
        self::assertStringContainsString('checkout.wompi.co', $salida);
        self::assertStringContainsString('45 minutos', $salida);
        self::assertStringContainsString('400.000', $salida);

        // Y la máquina avanza a pendiente_pago.
        self::assertSame(
            Estados::PENDIENTE_PAGO,
            $this->conversaciones->porConversacion(self::CONV)?->nodo(),
        );
    }

    #[Test]
    public function sinCasoNoHayPropuestaNiReserva(): void
    {
        // El embudo califica antes de cobrar: una propuesta sin caso es el
        // modelo saltándose el triage, y se ignora.
        $this->turno(
            'Pague ya. {"accion":"PROPONER_ASESORIA","fecha":"' . $this->fecha . '","horaInicio":"15:00"}',
        );

        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM consultas')->fetchColumn());
        self::assertSame([], $this->linksCreados);
    }

    #[Test]
    public function unaFechaAlucinadaNoReservaNada(): void
    {
        $casoId = $this->crearCaso();

        // Un año adelante: fuera de la ventana de `dias_max_anticipacion`.
        $lejana = Fechas::ahora()->modify('+1 year')->format('Y-m-d');

        $salida = $this->turno(
            'Listo. {"accion":"PROPONER_ASESORIA","fecha":"' . $lejana . '","horaInicio":"15:00"}',
            $casoId,
        );

        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM consultas')->fetchColumn());
        self::assertStringContainsString('Qué día', $salida, 'se le repregunta en vez de reservar');
    }

    #[Test]
    public function siElCupoSeOcupoSeOfrecenAlternativasReales(): void
    {
        $casoId = $this->crearCaso();

        // Otro cliente tomó las 15:00.
        $otroContacto = $this->contactos->crear('573009998877', 'whatsapp');
        $otroCaso = (new CasoRepo($this->bd))->crear($otroContacto->id, ['tipo_caso' => 'decomiso'])->id;
        $modalidad = (string) $this->bd->pdo()
            ->query('SELECT id FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')->fetchColumn();
        $this->consultas->reservar($otroCaso, $otroContacto->id, $modalidad, $this->fecha, '15:00:00', 45);

        $salida = $this->turno(
            'Reservo. {"accion":"PROPONER_ASESORIA","fecha":"' . $this->fecha . '","horaInicio":"15:00"}',
            $casoId,
        );

        self::assertStringContainsString('acaba de ocuparse', $salida);
        // Las alternativas son horas REALES del horario sembrado, no
        // inventadas: 9 de la mañana existe en la franja de la prueba.
        self::assertStringContainsString('9 a. m.', $salida);
        self::assertSame([], $this->linksCreados, 'sin cupo no hay enlace');
    }

    // ── VER_SLOTS ────────────────────────────────────────────────────────

    #[Test]
    public function verSlotsAdjuntaLosHorariosDeLaBase(): void
    {
        $casoId = $this->crearCaso();

        $salida = $this->turno(
            'Déjeme consultar. {"accion":"VER_SLOTS","fecha":"' . $this->fecha . '"}',
            $casoId,
        );

        self::assertStringContainsString('Horarios disponibles', $salida);
        self::assertStringContainsString('9 a. m.', $salida);
    }

    // ── CANCELAR / REAGENDAR ─────────────────────────────────────────────

    #[Test]
    public function cancelarSoloFuncionaSobreConsultaPropia(): void
    {
        $casoId = $this->crearCaso();

        // La consulta es de OTRA persona.
        $otroContacto = $this->contactos->crear('573009998877', 'whatsapp');
        $otroCaso = (new CasoRepo($this->bd))->crear($otroContacto->id, ['tipo_caso' => 'decomiso'])->id;
        $modalidad = (string) $this->bd->pdo()
            ->query('SELECT id FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')->fetchColumn();
        $ajena = $this->consultas->reservar($otroCaso, $otroContacto->id, $modalidad, $this->fecha, '10:00:00', 45);

        $this->turno(
            'La cancelo. {"accion":"CANCELAR_CONSULTA","consultaId":"' . $ajena->id . '"}',
            $casoId,
        );

        // Regla 12: el id vino del modelo y el modelo lo leyó de un chat. La
        // cita de otra persona sigue intacta.
        self::assertSame('reservada', $this->consultas->porId($ajena->id)?->estado);
    }

    #[Test]
    public function cancelarUnaReservaPropiaFunciona(): void
    {
        $casoId = $this->crearCaso();
        $contacto = $this->contactos->porTelefono(self::TELEFONO);
        $modalidad = (string) $this->bd->pdo()
            ->query('SELECT id FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')->fetchColumn();
        $mia = $this->consultas->reservar($casoId, $contacto->id, $modalidad, $this->fecha, '10:00:00', 45);

        $salida = $this->turno(
            'Listo. {"accion":"CANCELAR_CONSULTA","consultaId":"' . $mia->id . '"}',
            $casoId,
        );

        self::assertSame('cancelada', $this->consultas->porId($mia->id)?->estado);
        self::assertStringContainsString('cancelada', $salida);
    }

    #[Test]
    public function unaAsesoriaPagadaNoSeCancelaPorChatYSeAvisaAPedro(): void
    {
        $casoId = $this->crearCaso();
        $contacto = $this->contactos->porTelefono(self::TELEFONO);
        $modalidad = (string) $this->bd->pdo()
            ->query('SELECT id FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')->fetchColumn();
        $mia = $this->consultas->reservar($casoId, $contacto->id, $modalidad, $this->fecha, '10:00:00', 45);
        $this->consultas->cambiarEstado($mia->id, 'pagada');

        $salida = $this->turno(
            'La cancelo. {"accion":"CANCELAR_CONSULTA","consultaId":"' . $mia->id . '"}',
            $casoId,
        );

        // Hay dinero de por medio: la resuelve una persona.
        self::assertSame('pagada', $this->consultas->porId($mia->id)?->estado);
        self::assertStringContainsString('la gestiona', $salida);
        self::assertNotEmpty($this->eventos('alerta.escalamiento'), 'Pedro queda avisado');
    }

    #[Test]
    public function reagendarUnaReservaPropiaMueveLaCita(): void
    {
        $casoId = $this->crearCaso();
        $contacto = $this->contactos->porTelefono(self::TELEFONO);
        $modalidad = (string) $this->bd->pdo()
            ->query('SELECT id FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')->fetchColumn();
        $mia = $this->consultas->reservar($casoId, $contacto->id, $modalidad, $this->fecha, '10:00:00', 45);

        $salida = $this->turno(
            'Muevo su cita. {"accion":"REAGENDAR_CONSULTA","consultaId":"' . $mia->id
            . '","fecha":"' . $this->fecha . '","horaInicio":"16:00"}',
            $casoId,
        );

        $viva = $this->bd->pdo()->query(
            "SELECT hora_inicio FROM consultas WHERE estado = 'reservada'"
        )->fetchColumn();

        self::assertSame('16:00:00', $viva);
        self::assertStringContainsString('4 p. m.', $salida);
    }
}
