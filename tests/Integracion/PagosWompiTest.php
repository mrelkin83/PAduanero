<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Excepciones\CredencialNoEncontradaException;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\CasoRepo;
use App\Repositorios\ConsultaRepo;
use App\Repositorios\ContactoRepo;
use App\Servicios\Config;
use App\Servicios\Credenciales;
use App\Servicios\OutboxMysql;
use App\Servicios\Pagos;
use App\Servicios\PagosWompi;
use App\Soporte\Cifrado;
use App\Soporte\Http;
use App\Soporte\Logger;
use App\Soporte\RespuestaHttp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Cobro (`PRUEBAS.md` §1, nivel 1) — las dos reglas que no admiten grieta:
 *
 *  · Regla 6: `pagada` SOLO por webhook con firma verificada. Nunca por
 *    afirmación de nadie.
 *  · ADR-010: los pesos se vuelven centavos en `crearLink()` y en ningún
 *    otro sitio. La modalidad sembrada de $400.000 tiene que producir
 *    exactamente 40000000 centavos.
 */
#[Group('critica')]
final class PagosWompiTest extends CasoBaseBd
{
    public const SECRETO_EVENTOS = 'test_events_secret_2026';

    private Pagos $pagos;
    private ConsultaRepo $consultas;
    private OutboxMysql $outbox;
    private \App\Modelos\Contacto $contacto;
    private string $casoId;
    private string $consultaId;

    protected function setUp(): void
    {
        parent::setUp();

        $contactos = new ContactoRepo(
            $this->bd,
            Cifrado::desdeEntorno(),
            new AuditoriaRepo($this->bd),
        );
        $this->contacto = $contactos->crear('573001112233', 'whatsapp');
        $this->casoId = (new CasoRepo($this->bd))
            ->crear($this->contacto->id, ['tipo_caso' => 'decomiso'])->id;

        $this->consultas = new ConsultaRepo($this->bd);
        $modalidadId = (string) $this->bd->pdo()
            ->query('SELECT id FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')
            ->fetchColumn();

        $reserva = $this->consultas->reservar(
            $this->casoId,
            $this->contacto->id,
            $modalidadId,
            '2026-08-05',
            '14:00:00',
            45,
        );
        $this->consultaId = $reserva->id;

        // El hilo del caso, para que la confirmación tenga adónde ir.
        $conversaciones = new \App\Repositorios\ConversacionEstadoRepo($this->bd);
        $conversaciones->buscarOCrear(77, $this->contacto->id);
        $conversaciones->vincularCaso(77, $this->casoId);

        $this->outbox = new OutboxMysql($this->bd);
        $this->pagos = new PagosWompi(
            $this->bd,
            $this->credenciales(),
            $this->consultas,
            $this->config(),
            $this->outbox,
            $this->httpMudo(),
            Logger::desdeEntorno(),
        );
    }

    private function credenciales(): Credenciales
    {
        return new class implements Credenciales {
            /** @var array<string,string> */
            private array $valores = [
                'wompi.llave_publica' => 'pub_test_abc123',
                'wompi.llave_privada' => 'prv_test_def456',
                'wompi.clave_integridad' => 'test_integrity_secret',
                'wompi.clave_eventos' => PagosWompiTest::SECRETO_EVENTOS,
            ];

            public function obtener(string $servicio, string $clave, string $entorno = 'produccion'): string
            {
                return $this->valores[$servicio . '.' . $clave]
                    ?? throw new CredencialNoEncontradaException("sin {$servicio}.{$clave}");
            }

            public function guardar(
                string $servicio,
                string $clave,
                string $valor,
                string $entorno,
                string $usuarioId,
            ): array {
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

    private function config(): Config
    {
        return new class implements Config {
            public function get(string $clave, mixed $porDefecto = null): mixed
            {
                return $clave === 'minutos_reserva_pago' ? 45 : $porDefecto;
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

    private function httpMudo(): Http
    {
        return new class extends Http {
            public function pedir(string $m, string $u, array $c = [], ?array $json = null): RespuestaHttp
            {
                throw new \LogicException('Esta prueba no debía salir a la red.');
            }
        };
    }

    /**
     * Un evento de Wompi firmado como Wompi los firma: SHA256 de los valores
     * de `signature.properties` en orden + timestamp + secreto de eventos.
     *
     * @param array<string,mixed> $cambios
     * @return array{cuerpo:string}
     */
    private function eventoFirmado(string $referencia, string $estado, int $monto, array $cambios = []): array
    {
        $transaccion = [
            'id' => '1234-1609-TEST',
            'amount_in_cents' => $monto,
            'reference' => $referencia,
            'status' => $estado,
            ...$cambios,
        ];

        $timestamp = 1754040000;
        $checksum = hash('sha256', $transaccion['id'] . $transaccion['status']
            . $transaccion['amount_in_cents'] . $timestamp . self::SECRETO_EVENTOS);

        return ['cuerpo' => (string) json_encode([
            'event' => 'transaction.updated',
            'data' => ['transaction' => $transaccion],
            'signature' => [
                'checksum' => $checksum,
                'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
            ],
            'timestamp' => $timestamp,
        ], JSON_THROW_ON_ERROR)];
    }

    /** @return array<string,mixed> */
    private function pago(string $referencia): array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM pagos WHERE referencia = ?');
        $stmt->execute([$referencia]);

        return $stmt->fetch() ?: [];
    }

    private function estadoConsulta(): string
    {
        $stmt = $this->bd->pdo()->prepare('SELECT estado FROM consultas WHERE id = ?');
        $stmt->execute([$this->consultaId]);

        return (string) $stmt->fetchColumn();
    }

    // ── ADR-010: el factor 100 ───────────────────────────────────────────

    #[Test]
    public function laModalidadSembradaProduceExactamente40000000Centavos(): void
    {
        // La prueba de nivel 1 que exige el ADR-010 con estos números
        // literales: $400.000 → 40000000 centavos. Un error de factor 100 en
        // cualquier sentido le cobra $40.000.000 a un cliente o $4.000 a
        // Pedro.
        $precio = (int) $this->bd->pdo()
            ->query('SELECT precio_cop FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')
            ->fetchColumn();

        self::assertSame(400000, $precio, 'la semilla va en PESOS');

        $link = $this->pagos->crearLink($this->consultaId, $precio, 'Asesoría', $this->contacto);

        self::assertSame(40000000, (int) $this->pago($link['referencia'])['monto_centavos']);
        self::assertStringContainsString('amount-in-cents=40000000', $link['url']);
    }

    #[Test]
    public function elEnlaceVaFirmadoConLaClaveDeIntegridad(): void
    {
        // Sin la firma de integridad, cualquiera que conozca la llave
        // pública arma un checkout por otro monto y paga $4.000 por una
        // asesoría de $400.000.
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);

        $esperada = hash('sha256', $link['referencia'] . '40000000' . 'COP' . 'test_integrity_secret');

        self::assertStringContainsString('signature%3Aintegrity=' . $esperada, $link['url']);
    }

    #[Test]
    public function soloUnaConsultaReservadaGeneraEnlace(): void
    {
        $this->consultas->cambiarEstado($this->consultaId, 'cancelada');

        $this->expectException(\DomainException::class);

        $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
    }

    // ── Regla 6: la firma manda ──────────────────────────────────────────

    #[Test]
    public function unWebhookAprobadoConFirmaValidaConfirmaLaConsulta(): void
    {
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
        $evento = $this->eventoFirmado($link['referencia'], 'APPROVED', 40000000);

        $r = $this->pagos->procesarWebhook($evento['cuerpo'], []);

        self::assertTrue($r['valido']);
        self::assertTrue($r['procesado']);

        $pago = $this->pago($link['referencia']);

        self::assertSame('aprobado', $pago['estado']);
        self::assertSame(1, (int) $pago['firma_verificada']);
        self::assertNotNull($pago['confirmado_en']);
        self::assertSame('pagada', $this->estadoConsulta());
    }

    #[Test]
    public function unaFirmaInvalidaNoEscribeNada(): void
    {
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
        $evento = $this->eventoFirmado($link['referencia'], 'APPROVED', 40000000);

        // El mismo evento con el checksum corrompido.
        $roto = str_replace('"checksum":"', '"checksum":"00', $evento['cuerpo']);

        $r = $this->pagos->procesarWebhook($roto, []);

        self::assertFalse($r['valido']);
        self::assertFalse($r['procesado']);
        self::assertSame('pendiente', $this->pago($link['referencia'])['estado']);
        self::assertSame('reservada', $this->estadoConsulta());
        self::assertNull($this->pago($link['referencia'])['payload_webhook'], 'ni el payload se guarda');
    }

    #[Test]
    public function manipularElMontoRompeLaFirma(): void
    {
        // El ataque obvio: interceptar el evento y cambiarle el monto. Los
        // valores firmados incluyen amount_in_cents, así que el checksum
        // deja de cuadrar.
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
        $evento = $this->eventoFirmado($link['referencia'], 'APPROVED', 40000000);

        $manipulado = str_replace('"amount_in_cents":40000000', '"amount_in_cents":400000', $evento['cuerpo']);

        $r = $this->pagos->procesarWebhook($manipulado, []);

        self::assertFalse($r['valido']);
        self::assertSame('reservada', $this->estadoConsulta());
    }

    #[Test]
    public function unMontoFirmadoQueNoCuadraConElPagoNoConfirma(): void
    {
        // Firma VÁLIDA pero de otro monto: un APPROVED legítimo de $4.000
        // no puede confirmar una asesoría de $400.000.
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
        $evento = $this->eventoFirmado($link['referencia'], 'APPROVED', 400000);

        $r = $this->pagos->procesarWebhook($evento['cuerpo'], []);

        self::assertTrue($r['valido'], 'la firma sí cuadra');
        self::assertFalse($r['procesado'], 'pero el monto no, y no se confirma');
        self::assertSame('reservada', $this->estadoConsulta());
    }

    #[Test]
    public function elMismoEventoDosVecesConfirmaUna(): void
    {
        // Wompi reintenta. La idempotencia por referencia es innegociable
        // (CONTRATOS §Pagos): la segunda entrega se reconoce sin confirmar
        // de nuevo y SIN volver a encolar la confirmación al contacto.
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
        $evento = $this->eventoFirmado($link['referencia'], 'APPROVED', 40000000);

        $primera = $this->pagos->procesarWebhook($evento['cuerpo'], []);
        $eventosTrasPrimera = $this->conteoOutbox();

        $segunda = $this->pagos->procesarWebhook($evento['cuerpo'], []);

        self::assertTrue($primera['procesado']);
        self::assertFalse($segunda['procesado'], 'la segunda no procesa');
        self::assertTrue($segunda['valido'], 'pero se reconoce, para que Wompi no reintente');
        self::assertSame($eventosTrasPrimera, $this->conteoOutbox(), 'cero efectos duplicados');
    }

    #[Test]
    public function unRechazoQuedaRegistradoSinTocarLaReserva(): void
    {
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
        $evento = $this->eventoFirmado($link['referencia'], 'DECLINED', 40000000);

        $r = $this->pagos->procesarWebhook($evento['cuerpo'], []);

        self::assertTrue($r['valido']);
        self::assertFalse($r['procesado']);
        self::assertSame('rechazado', $this->pago($link['referencia'])['estado']);
        // La reserva sigue viva: el contacto puede intentar pagar de nuevo
        // dentro de la ventana.
        self::assertSame('reservada', $this->estadoConsulta());
    }

    #[Test]
    public function unaReferenciaDesconocidaSeReconoceSinProcesar(): void
    {
        $evento = $this->eventoFirmado('PA-inexistente-abc', 'APPROVED', 40000000);

        $r = $this->pagos->procesarWebhook($evento['cuerpo'], []);

        self::assertTrue($r['valido']);
        self::assertFalse($r['procesado']);
    }

    // ── Los efectos de la confirmación ───────────────────────────────────

    #[Test]
    public function laConfirmacionEncolaMensajeRecordatorioYAlerta(): void
    {
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
        $this->pagos->procesarWebhook(
            $this->eventoFirmado($link['referencia'], 'APPROVED', 40000000)['cuerpo'],
            [],
        );

        $eventos = $this->bd->pdo()->query(
            'SELECT tipo, payload, disponible_en, creado_en FROM eventos_outbox ORDER BY id'
        )->fetchAll();

        $tipos = array_column($eventos, 'tipo');

        self::assertContains('chatwoot.entregar', $tipos, 'confirmación al contacto');
        self::assertContains('alerta.pago_confirmado', $tipos, 'aviso a Pedro');
        self::assertCount(
            2,
            array_filter($tipos, static fn (string $t): bool => $t === 'chatwoot.entregar'),
            'confirmación + recordatorio',
        );

        // El recordatorio queda PROGRAMADO, no inmediato: su disponible_en
        // está en el futuro (la cita de prueba está a días de distancia).
        $programados = array_filter(
            $eventos,
            static fn (array $e): bool => $e['disponible_en'] > $e['creado_en'],
        );

        self::assertNotEmpty($programados, 'el recordatorio de 24 h quedó a futuro');
    }

    #[Test]
    public function laAlertaAPedroNoLlevaContenidoDelCaso(): void
    {
        // Misma disciplina que la regla 14: al teléfono personal va la
        // agenda, jamás el contenido. El payload solo puede tener fecha,
        // hora y conversación.
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);
        $this->pagos->procesarWebhook(
            $this->eventoFirmado($link['referencia'], 'APPROVED', 40000000)['cuerpo'],
            [],
        );

        $payload = (string) $this->bd->pdo()->query(
            "SELECT payload FROM eventos_outbox WHERE tipo = 'alerta.pago_confirmado'"
        )->fetchColumn();

        $claves = array_keys((array) json_decode($payload, true));
        sort($claves);

        self::assertSame(['chatwoot_conv_id', 'fecha', 'hora'], $claves);
    }

    // ── La expiración (regla 7) ──────────────────────────────────────────

    #[Test]
    public function unaReservaVencidaExpiraYSuPagoPendienteTambien(): void
    {
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);

        // Vence la ventana.
        $this->bd->pdo()->prepare(
            "UPDATE consultas SET reserva_expira = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?"
        )->execute([$this->consultaId]);

        $expiradas = $this->consultas->expirarVencidas();

        self::assertContains($this->consultaId, $expiradas);
        self::assertSame('expirada', $this->estadoConsulta());

        // Lo que hace el cron con los pagos colgantes.
        $this->bd->pdo()->prepare(
            "UPDATE pagos SET estado = 'expirado'
              WHERE consulta_id = ? AND estado IN ('creado','pendiente')"
        )->execute([$this->consultaId]);

        self::assertSame('expirado', $this->pago($link['referencia'])['estado']);
    }

    #[Test]
    public function unWebhookTardioSobreUnPagoExpiradoNoResucitaLaConsulta(): void
    {
        // El contacto guardó el enlace y pagó a la hora. El dinero llegó a
        // Wompi de verdad, pero el cupo ya se liberó: confirmar aquí
        // agendaría sobre un slot que otro pudo tomar. Queda registrado
        // `aprobado` en el pago para conciliación (hay que devolver o
        // reagendar A MANO), y la consulta no se toca.
        $link = $this->pagos->crearLink($this->consultaId, 400000, 'Asesoría', $this->contacto);

        $this->bd->pdo()->prepare(
            "UPDATE consultas SET estado = 'expirada' WHERE id = ?"
        )->execute([$this->consultaId]);
        $this->bd->pdo()->prepare(
            "UPDATE pagos SET estado = 'expirado' WHERE referencia = ?"
        )->execute([$link['referencia']]);

        $r = $this->pagos->procesarWebhook(
            $this->eventoFirmado($link['referencia'], 'APPROVED', 40000000)['cuerpo'],
            [],
        );

        self::assertTrue($r['valido']);
        self::assertSame('aprobado', $this->pago($link['referencia'])['estado'], 'el dinero real queda registrado');
        self::assertSame('expirada', $this->estadoConsulta(), 'pero el cupo liberado no se resucita solo');
    }

    private function conteoOutbox(): int
    {
        return (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM eventos_outbox')->fetchColumn();
    }
}
