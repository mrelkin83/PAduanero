<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Modelos\EventoOutbox;
use App\Motor\MotivoEscalamiento;
use App\Servicios\Manejadores\EventoDescartado;
use App\Servicios\Manejadores\ManejadorEvento;
use App\Servicios\OutboxMysql;
use App\Servicios\WorkerOutbox;
use App\Soporte\Logger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * La cola de efectos externos y su worker (ADR-004).
 *
 * Lo que se defiende: que nada se pierda, que la regla 14 no se pueda violar
 * por la puerta del payload, y que un evento imposible no tape a los que sí
 * podían salir.
 */
#[Group('critica')]
final class OutboxTest extends CasoBaseBd
{
    private OutboxMysql $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = new OutboxMysql($this->bd);
    }

    /** Manejador de prueba con conducta programable. */
    private function manejador(string $tipo, ?\Throwable $lanza = null): ManejadorEvento
    {
        return new class ($tipo, $lanza) implements ManejadorEvento {
            /** @var list<int> */
            public array $vistos = [];

            public function __construct(
                private readonly string $tipo,
                private readonly ?\Throwable $lanza,
            ) {
            }

            public function tipos(): array
            {
                return [$this->tipo];
            }

            public function manejar(EventoOutbox $evento): void
            {
                $this->vistos[] = $evento->id;

                if ($this->lanza !== null) {
                    throw $this->lanza;
                }
            }
        };
    }

    private function fila(int $id): array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM eventos_outbox WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    // ── Encolar y reclamar ───────────────────────────────────────────────

    #[Test]
    public function encolarEsUnaEscrituraSeguraDentroDeUnaTransaccion(): void
    {
        // Es la razón de ser del patrón: escribir el caso y avisar a Pedro son
        // dos cosas, y la segunda no puede arrastrar a la primera si tarda.
        $pdo = $this->bd->pdo();
        $pdo->beginTransaction();
        $id = $this->outbox->encolar('alerta.modelo_retirado', ['modelo' => 'claude-opus-5']);
        $pdo->commit();

        self::assertSame('pendiente', $this->fila($id)['estado']);
    }

    #[Test]
    public function tomarMarcaProcesandoYNoLoDevuelveDosVeces(): void
    {
        $this->outbox->encolar('x', ['a' => 1]);
        $this->outbox->encolar('x', ['a' => 2]);

        $primera = $this->outbox->tomar();
        self::assertCount(2, $primera);
        self::assertSame('procesando', $this->fila($primera[0]->id)['estado']);

        // Un segundo worker no puede llevarse lo mismo.
        self::assertSame([], $this->outbox->tomar());
    }

    #[Test]
    public function unEventoProgramadoAFuturoNoSeToma(): void
    {
        $this->outbox->encolar('x', [], retrasoSegundos: 600);

        self::assertSame([], $this->outbox->tomar());
    }

    #[Test]
    public function elOrdenEsPorDisponibilidadYNoPorLlegada(): void
    {
        $tarde = $this->outbox->encolar('x', ['n' => 'tarde'], retrasoSegundos: 0);
        $this->bd->pdo()
            ->prepare('UPDATE eventos_outbox SET disponible_en = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
            ->execute([$tarde]);

        $pronto = $this->outbox->encolar('x', ['n' => 'pronto']);

        $tomados = array_map(static fn (EventoOutbox $e): int => $e->id, $this->outbox->tomar());

        self::assertSame([$tarde, $pronto], $tomados);
    }

    // ── Regla 14 ─────────────────────────────────────────────────────────

    #[Test]
    public function laAlertaDeEscalamientoNoPuedeLlevarTextoDelMensaje(): void
    {
        // La regla 14 dice «cero texto del mensaje en ninguna tabla, cola o
        // notificación». El outbox ES esa cola. Por eso el método construye
        // el payload y no lo recibe: no hay parámetro por el que quepa.
        $id = $this->outbox->encolarAlertaEscalamiento(
            '573001112233',
            MotivoEscalamiento::URGENCIA,
            123,
        );

        $payload = json_decode((string) $this->fila($id)['payload'], true);

        // El conjunto de claves, no su orden: MySQL reordena al almacenar JSON
        // y afirmar sobre el orden sería afirmar sobre un detalle del motor.
        // Lo que la regla 14 fija es QUÉ puede haber, y nada más.
        self::assertEqualsCanonicalizing(
            ['telefono', 'motivo', 'urgente', 'chatwoot_conv_id'],
            array_keys($payload),
            'el payload de escalamiento tiene exactamente los cuatro campos que la regla 14 autoriza',
        );
        self::assertTrue($payload['urgente']);
        self::assertSame(123, $payload['chatwoot_conv_id']);
    }

    #[Test]
    public function elMetodoDeEscalamientoNoAceptaUnCampoDeTexto(): void
    {
        // Comprobación estructural: si alguien añadiera un parámetro de texto
        // «para que Pedro no tenga que abrir Chatwoot», esta prueba lo ve.
        $parametros = (new \ReflectionMethod(OutboxMysql::class, 'encolarAlertaEscalamiento'))
            ->getParameters();

        self::assertSame(
            ['telefonoContacto', 'motivo', 'chatwootConvId'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parametros),
        );
    }

    // ── Backoff y descarte ───────────────────────────────────────────────

    #[Test]
    public function reprogramarSubeLaEsperaEnCadaIntento(): void
    {
        $id = $this->outbox->encolar('x', []);

        foreach (OutboxMysql::ESPERAS_MIN as $i => $esperados) {
            $this->outbox->tomar();
            $this->outbox->reprogramar($id, 'Chatwoot caído');

            $fila = $this->fila($id);
            self::assertSame('pendiente', $fila['estado'], "tras el intento " . ($i + 1));

            $minutos = (int) $this->bd->pdo()->query(
                "SELECT TIMESTAMPDIFF(MINUTE, NOW(), disponible_en) FROM eventos_outbox WHERE id = {$id}"
            )->fetchColumn();

            // TIMESTAMPDIFF trunca, así que un minuto exacto puede leerse 0.
            self::assertGreaterThanOrEqual($esperados - 1, $minutos);
            self::assertLessThanOrEqual($esperados, $minutos);

            // Adelantar el reloj de la fila para poder tomarla otra vez.
            $this->bd->pdo()->exec(
                "UPDATE eventos_outbox SET disponible_en = NOW() WHERE id = {$id}"
            );
        }

        // Agotadas las esperas, deja de golpear.
        $this->outbox->tomar();
        $this->outbox->reprogramar($id, 'sigue caído');

        self::assertSame('fallido', $this->fila($id)['estado']);
    }

    #[Test]
    public function unWorkerMuertoNoDejaElEventoPerdidoParaSiempre(): void
    {
        // Sin esta recuperación, «nunca se pierde» sería falso — y falso en
        // silencio, que es lo peor: la cola se ve sin pendientes.
        $id = $this->outbox->encolar('x', []);
        $this->outbox->tomar();

        self::assertSame(0, $this->outbox->recuperarAtascados(15), 'todavía no está atascado');

        $this->bd->pdo()->exec(
            "UPDATE eventos_outbox SET disponible_en = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = {$id}"
        );

        self::assertSame(1, $this->outbox->recuperarAtascados(15));
        self::assertSame('pendiente', $this->fila($id)['estado']);
    }

    #[Test]
    public function unEventoRecienEncoladoYRecienTomadoNoSeRecupera(): void
    {
        // El atasco se mide desde que se RECLAMÓ, no desde que se encoló.
        // Midiéndolo mal, un evento que llevaba una hora en cola y que un
        // worker vivo acaba de tomar se le arrebataría de las manos.
        $id = $this->outbox->encolar('x', []);
        $this->bd->pdo()->exec(
            "UPDATE eventos_outbox SET creado_en = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE id = {$id}"
        );

        $this->outbox->tomar();

        self::assertSame(0, $this->outbox->recuperarAtascados(15));
        self::assertSame('procesando', $this->fila($id)['estado']);
    }

    // ── El worker ────────────────────────────────────────────────────────

    #[Test]
    public function elWorkerDespachaYMarcaEnviado(): void
    {
        $id = $this->outbox->encolar('prueba', []);
        $manejador = $this->manejador('prueba');

        $resumen = (new WorkerOutbox($this->outbox, Logger::desdeEntorno(), [$manejador]))->pasada();

        self::assertSame(1, $resumen['despachados']);
        self::assertSame([$id], $manejador->vistos);
        self::assertSame('enviado', $this->fila($id)['estado']);
        self::assertNotNull($this->fila($id)['procesado_en']);
    }

    #[Test]
    public function unFalloTransitorioSeReprogramaYUnDescarteNo(): void
    {
        $transitorio = $this->outbox->encolar('cae', []);
        $imposible = $this->outbox->encolar('malo', []);

        $worker = new WorkerOutbox(
            $this->outbox,
            Logger::desdeEntorno(),
            [
                $this->manejador('cae', new \RuntimeException('Chatwoot no responde')),
                $this->manejador('malo', new EventoDescartado('payload sin conversación')),
            ],
        );

        $resumen = $worker->pasada();

        self::assertSame(1, $resumen['reprogramados']);
        self::assertSame(1, $resumen['descartados']);
        self::assertSame('pendiente', $this->fila($transitorio)['estado']);
        self::assertSame('fallido', $this->fila($imposible)['estado']);
    }

    #[Test]
    public function unTipoSinManejadorNoSeReintentaCincoVeces(): void
    {
        // Reintentarlo no va a hacer que aparezca la clase que falta, y
        // mientras tanto retrasa a los eventos que sí podían salir.
        $id = $this->outbox->encolar('tipo.que.nadie.atiende', []);

        $resumen = (new WorkerOutbox($this->outbox, Logger::desdeEntorno(), []))->pasada();

        self::assertSame(1, $resumen['descartados']);
        self::assertSame('fallido', $this->fila($id)['estado']);
        self::assertStringContainsString('Sin manejador', (string) $this->fila($id)['ultimo_error']);
    }

    #[Test]
    public function unEventoQueFallaNoBloqueaALosDemas(): void
    {
        $malo = $this->outbox->encolar('cae', []);
        $bueno = $this->outbox->encolar('prueba', []);

        $worker = new WorkerOutbox(
            $this->outbox,
            Logger::desdeEntorno(),
            [
                $this->manejador('cae', new \RuntimeException('boom')),
                $this->manejador('prueba'),
            ],
        );

        $worker->pasada();

        self::assertSame('pendiente', $this->fila($malo)['estado']);
        self::assertSame('enviado', $this->fila($bueno)['estado']);
    }

    #[Test]
    public function elEstadoDeLaColaSeVeDeUnVistazo(): void
    {
        $this->outbox->encolar('x', []);
        $fallido = $this->outbox->encolar('y', []);
        $this->outbox->marcarFallido($fallido, 'motivo');

        $estado = $this->outbox->estado();

        self::assertSame(1, $estado['pendientes']);
        self::assertSame(1, $estado['fallidos']);
        self::assertSame(0, $estado['procesando']);
    }
}
