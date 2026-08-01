<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Excepciones\SlotOcupadoException;
use App\Repositorios\CasoRepo;
use App\Repositorios\ConsultaRepo;
use App\Repositorios\ContactoRepo;
use App\Repositorios\AuditoriaRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Doble reserva de cupo (`PRUEBAS.md` §1, nivel 1).
 *
 * Dos clientes en el mismo horario es una crisis con Pedro. Lo que se prueba
 * aquí no es que la reserva funcione —eso es lo fácil— sino que las DOS
 * líneas de defensa del ADR-015 estén vivas, incluido el solapamiento
 * parcial, que es el que el índice único no ve.
 */
#[Group('critica')]
final class ConsultaRepoTest extends CasoBaseBd
{
    private ConsultaRepo $consultas;
    private string $casoId;
    private string $contactoId;
    private string $modalidadId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultas = new ConsultaRepo($this->bd);

        $contactos = new ContactoRepo(
            $this->bd,
            Cifrado::desdeEntorno(),
            new AuditoriaRepo($this->bd),
        );

        $contacto = $contactos->crear('573001112233', 'whatsapp');
        $this->contactoId = $contacto->id;

        $caso = (new CasoRepo($this->bd))->crear($contacto->id, ['tipo_caso' => 'decomiso']);
        $this->casoId = $caso->id;

        $this->modalidadId = (string) $this->bd->pdo()
            ->query('SELECT id FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')
            ->fetchColumn();
    }

    private function reservar(string $hora, string $fecha = '2026-08-05'): object
    {
        return $this->consultas->reservar(
            $this->casoId,
            $this->contactoId,
            $this->modalidadId,
            $fecha,
            $hora,
            45,
        );
    }

    // ── Las dos líneas de defensa ────────────────────────────────────────

    #[Test]
    public function noSePuedeReservarDosVecesLaMismaHora(): void
    {
        $this->reservar('14:00:00');

        $this->expectException(SlotOcupadoException::class);

        $this->reservar('14:00:00');
    }

    #[Test]
    public function elSolapamientoParcialTambienSeBloquea(): void
    {
        // ESTE es el caso que el índice único NO ve: `slot_unico` es
        // CONCAT(fecha,'T',hora_inicio), así que 14:00 y 14:30 son claves
        // distintas. Con la modalidad de 60 minutos sembrada, la segunda
        // reserva pisa media hora de la primera.
        $this->reservar('14:00:00');

        $this->expectException(SlotOcupadoException::class);

        $this->reservar('14:30:00');
    }

    #[Test]
    public function elSolapamientoPorDetrasTambienSeBloquea(): void
    {
        // La condición (inicio_a < fin_b) AND (inicio_b < fin_a) tiene que
        // valer en los dos sentidos. Reservar 13:30 cuando ya hay 14:00
        // choca por el otro extremo.
        $this->reservar('14:00:00');

        $this->expectException(SlotOcupadoException::class);

        $this->reservar('13:30:00');
    }

    #[Test]
    public function dosCitasContiguasSiCaben(): void
    {
        // El límite es estricto: 15:00 empieza justo cuando 14:00 termina.
        // Si la comparación fuera <= en vez de <, esto fallaría y la agenda
        // perdería la mitad de los cupos.
        $this->reservar('14:00:00');
        $segunda = $this->reservar('15:00:00');

        self::assertSame('15:00:00', $segunda->horaInicio);
        self::assertSame('16:00:00', $segunda->horaFin);
    }

    #[Test]
    public function unaCancelacionLiberaElCupo(): void
    {
        $primera = $this->reservar('14:00:00');
        $this->consultas->cambiarEstado($primera->id, 'cancelada');

        $segunda = $this->reservar('14:00:00');

        self::assertNotSame($primera->id, $segunda->id);
    }

    #[Test]
    public function elIndiceUnicoSigueVivoComoSegundaLinea(): void
    {
        // Se salta la validación de solapamiento escribiendo directo, que es
        // lo que haría un script de mantenimiento. El UNIQUE tiene que parar.
        $this->reservar('14:00:00');

        $this->expectException(\PDOException::class);

        $this->bd->pdo()->prepare(
            'INSERT INTO consultas
                (id, caso_id, contacto_id, modalidad_id, fecha, hora_inicio, hora_fin,
                 estado, precio_cop)
             VALUES (UUID(), ?, ?, ?, \'2026-08-05\', \'14:00:00\', \'15:00:00\',
                     \'reservada\', 400000)'
        )->execute([$this->casoId, $this->contactoId, $this->modalidadId]);
    }

    // ── Precio congelado (ADR-010) ───────────────────────────────────────

    #[Test]
    public function elPrecioSeCongelaAlReservarYVaEnPesos(): void
    {
        $reserva = $this->reservar('14:00:00');

        self::assertSame(400_000, $reserva->precioCop);

        // Sube la tarifa desde el panel.
        $this->bd->pdo()->exec('UPDATE modalidades_asesoria SET precio_cop = 550000');

        self::assertSame(
            400_000,
            $this->consultas->porId($reserva->id)?->precioCop,
            'una reserva viva conserva el precio con el que se hizo',
        );
    }

    // ── Expiración (regla 7) ─────────────────────────────────────────────

    #[Test]
    public function lasReservasVencidasExpiranYLiberanElCupo(): void
    {
        $reserva = $this->reservar('14:00:00');

        $this->bd->pdo()
            ->prepare('UPDATE consultas SET reserva_expira = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')
            ->execute([$reserva->id]);

        $expiradas = $this->consultas->expirarVencidas();

        self::assertSame([$reserva->id], $expiradas);
        self::assertSame('expirada', $this->consultas->porId($reserva->id)?->estado);

        // Y el cupo vuelve a estar libre, que es el objeto del ejercicio.
        self::assertNotNull($this->reservar('14:00:00'));
    }

    #[Test]
    public function unaConsultaPagadaNoExpiraAunqueTuvieraFechaDeExpiracion(): void
    {
        // Si el cron cancelara una consulta ya cobrada, el cliente perdería
        // la cita que pagó. `cambiarEstado` limpia la expiración al pagar.
        $reserva = $this->reservar('14:00:00');
        $this->consultas->cambiarEstado($reserva->id, 'pagada');

        $this->bd->pdo()
            ->prepare('UPDATE consultas SET reserva_expira = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?')
            ->execute([$reserva->id]);

        self::assertSame([], $this->consultas->expirarVencidas());
    }

    // ── Reagendar ────────────────────────────────────────────────────────

    #[Test]
    public function reagendarNoChocaConsigoMismo(): void
    {
        // Mover de 14:00 a 14:30 con una modalidad de una hora solaparía con
        // la reserva original si no se cancelara primero.
        $reserva = $this->reservar('14:00:00');

        $nueva = $this->consultas->reagendar($reserva->id, '2026-08-05', '14:30:00');

        self::assertSame('14:30:00', $nueva->horaInicio);
        self::assertSame('cancelada', $this->consultas->porId($reserva->id)?->estado);
    }

    #[Test]
    public function siLaNuevaHoraEstaOcupadaSeConservaLaCitaOriginal(): void
    {
        // Quedarse sin ninguna cita es peor que no poder moverla.
        $mia = $this->reservar('14:00:00');
        $ajena = $this->reservar('16:00:00');

        try {
            $this->consultas->reagendar($mia->id, '2026-08-05', '16:00:00');
            self::fail('debió lanzar SlotOcupadoException');
        } catch (SlotOcupadoException) {
            // esperado
        }

        self::assertSame('reservada', $this->consultas->porId($mia->id)?->estado);
        self::assertSame('reservada', $this->consultas->porId($ajena->id)?->estado);
    }

    #[Test]
    public function reagendarConservaLaVentanaDePagoQueQuedaba(): void
    {
        // Dos defectos posibles y los dos silenciosos:
        //
        //  · Regalar 45 minutos nuevos convierte reagendar en una forma de no
        //    pagar nunca.
        //  · Restar `strtotime(columna) - time()` da negativo —la columna está
        //    en UTC y el reloj de PHP en Bogotá— y el `max(1, …)` lo convierte
        //    en un minuto: la reserva reagendada caduca casi al instante y el
        //    cliente pierde el cupo sin entender por qué.
        //
        // Ninguno de los dos se ve como error: se ven como una realidad
        // plausible.
        $reserva = $this->reservar('14:00:00');

        $this->bd->pdo()
            ->prepare('UPDATE consultas SET reserva_expira = DATE_ADD(NOW(), INTERVAL 20 MINUTE) WHERE id = ?')
            ->execute([$reserva->id]);

        $nueva = $this->consultas->reagendar($reserva->id, '2026-08-05', '17:00:00');

        $restan = \App\Soporte\Fechas::minutosHastaUtc((string) $nueva->reservaExpira);

        self::assertGreaterThanOrEqual(18, $restan, 'la ventana no puede colapsar a un minuto');
        self::assertLessThanOrEqual(22, $restan, 'ni reiniciarse a 45');
    }

    #[Test]
    public function reagendarUnaConsultaPagadaLaMantienePagada(): void
    {
        $reserva = $this->reservar('14:00:00');
        $this->consultas->cambiarEstado($reserva->id, 'pagada');

        $nueva = $this->consultas->reagendar($reserva->id, '2026-08-05', '17:00:00');

        self::assertSame('pagada', $this->consultas->porId($nueva->id)?->estado);
        self::assertNull($this->consultas->porId($nueva->id)?->reservaExpira);
    }

    // ── Slots libres ─────────────────────────────────────────────────────

    #[Test]
    public function losSlotsLibresExcluyenLoYaReservado(): void
    {
        $antes = $this->consultas->slotsLibres('2026-08-05', $this->modalidadId);
        self::assertContains('14:00:00', $antes);

        $this->reservar('14:00:00');

        $despues = $this->consultas->slotsLibres('2026-08-05', $this->modalidadId);
        self::assertNotContains('14:00:00', $despues);
        self::assertCount(count($antes) - 1, $despues);
    }

    #[Test]
    public function unBloqueoDeAgendaTambienQuitaElCupo(): void
    {
        $libres = $this->consultas->slotsLibres('2026-08-05', $this->modalidadId);
        self::assertNotEmpty($libres);

        $this->bd->pdo()->prepare(
            'INSERT INTO bloqueos (id, fecha, hora_inicio, hora_fin, motivo)
             VALUES (UUID(), ?, ?, ?, ?)'
        )->execute(['2026-08-05', $libres[0], '23:59:00', 'audiencia']);

        self::assertSame([], $this->consultas->slotsLibres('2026-08-05', $this->modalidadId));
    }
}
