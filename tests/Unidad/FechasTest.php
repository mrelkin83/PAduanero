<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\Fechas;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FechasTest extends TestCase
{
    protected function tearDown(): void
    {
        Fechas::congelar(null);
    }

    #[Test]
    public function laFechaNaturalVaEnEspanol(): void
    {
        // 2026-08-04 es martes.
        self::assertSame('martes 4 de agosto', Fechas::fechaNatural('2026-08-04'));
        self::assertSame('domingo 1 de marzo', Fechas::fechaNatural('2026-03-01'));
    }

    #[Test]
    public function laHoraNaturalUsaElFormatoColombiano(): void
    {
        self::assertSame('2:30 p. m.', Fechas::horaNatural('14:30:00'));
        self::assertSame('8 a. m.', Fechas::horaNatural('08:00:00'));
        self::assertSame('12 p. m.', Fechas::horaNatural('12:00:00'));
        self::assertSame('12 a. m.', Fechas::horaNatural('00:00:00'));
    }

    #[Test]
    public function aceptaHoraSinSegundos(): void
    {
        // El LLM y los formularios mandan 'HH:mm' tan a menudo como 'HH:mm:ss'.
        self::assertSame('2:30 p. m.', Fechas::horaNatural('14:30'));
    }

    #[Test]
    public function sumarMinutosCruzaLaHora(): void
    {
        self::assertSame('15:00:00', Fechas::sumarMinutos('14:00:00', 60));
        self::assertSame('09:15:00', Fechas::sumarMinutos('08:45:00', 30));
    }

    #[Test]
    public function laConversionAUtcSumaLasCincoHorasDeBogota(): void
    {
        // Colombia es UTC-5 todo el año, sin horario de verano. Una asesoría
        // de las 2 p. m. se guarda como 19:00 UTC.
        $bogota = Fechas::combinar('2026-08-04', '14:00:00');

        self::assertSame('2026-08-04 19:00:00', Fechas::paraBd($bogota));
    }

    #[Test]
    public function loLeidoDeLaBaseVuelveAHoraDeBogota(): void
    {
        $local = Fechas::deUtc('2026-08-04 19:00:00');

        self::assertSame('14:00:00', $local->format('H:i:s'));
        self::assertSame(Fechas::ZONA, $local->getTimezone()->getName());
    }

    #[Test]
    public function restarHorasDevuelveElMomentoAbsoluto(): void
    {
        // Recordatorio 24 h antes de una asesoría del martes a las 2 p. m.
        $recordatorio = Fechas::restarHoras('2026-08-04', '14:00:00', 24);

        self::assertSame('2026-08-03 14:00:00', $recordatorio->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function elRelojSePuedeCongelarParaLasPruebasDeAgenda(): void
    {
        Fechas::congelar(new DateTimeImmutable('2026-08-04 09:00:00', new DateTimeZone(Fechas::ZONA)));

        self::assertSame('2026-08-04', Fechas::hoy());
        self::assertSame('09:00:00', Fechas::ahora()->format('H:i:s'));
    }

    #[Test]
    public function unaFechaInvalidaSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Fechas::fechaNatural('04/08/2026');
    }
}
