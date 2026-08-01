<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Motor\Afirmacion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El corolario de la regla 1: ante ambigüedad, no se registra nada.
 *
 * La prueba que importa no es que reconozca un «sí»: es que **ninguna forma
 * de decir que no** acabe interpretada como afirmación. Registrar un
 * consentimiento que nadie dio deja una constancia falsa, y no falla nada
 * visible el día que ocurre.
 */
#[Group('critica')]
final class AfirmacionTest extends TestCase
{
    /** @return list<array{string}> */
    public static function negaciones(): array
    {
        return array_map(static fn (string $t): array => [$t], [
            // La trampa original: contiene «autorizo».
            'no autorizo',
            'No, no acepto',
            'no, claro que no',
            // Formas reales de escribir por WhatsApp.
            'nop',
            'no señor',
            'para nada',
            'prefiero que no',
            'no gracias',
            'nel',
            'mejor no',
            'negativo',
            'ni loco',
            'olvídelo',
            'déjelo así',
            'nunca',
            'jamás',
            // Negación con una afirmación dentro, que es el caso peligroso.
            'no, listo, déjelo',
            'ok pero no autorizo',
            'no confirmo nada',
            'ya no quiero, cancela',
        ]);
    }

    /** @return list<array{string}> */
    public static function afirmaciones(): array
    {
        return array_map(static fn (string $t): array => [$t], [
            'sí',
            'si',
            'sip',
            'claro',
            'autorizo',
            'acepto',
            'de acuerdo',
            'dale',
            'ok',
            'listo',
            'por supuesto',
            'está bien',
            'perfecto, adelante',
            'sí, autorizo el tratamiento',
        ]);
    }

    /** @return list<array{string}> */
    public static function ambiguos(): array
    {
        return array_map(static fn (string $t): array => [$t], [
            'buenas tardes',
            'me llegó un requerimiento de la DIAN',
            '¿cuánto cuesta?',
            'necesito ayuda con una aprehensión',
            '',
            '   ',
        ]);
    }

    #[Test]
    #[DataProvider('negaciones')]
    public function ningunaFormaDeDecirQueNoSeInterpretaComoSi(string $mensaje): void
    {
        self::assertFalse(
            Afirmacion::de($mensaje),
            "«{$mensaje}» debe leerse como negativa",
        );
        self::assertFalse(Afirmacion::esAfirmativa($mensaje));
    }

    #[Test]
    #[DataProvider('afirmaciones')]
    public function lasAfirmacionesSeReconocen(string $mensaje): void
    {
        self::assertTrue(Afirmacion::de($mensaje), "«{$mensaje}» debe leerse como afirmación");
    }

    #[Test]
    #[DataProvider('ambiguos')]
    public function loQueNoRespondeSiONoDevuelveNull(string $mensaje): void
    {
        // Tratarlo como negativa cerraría conversaciones por escribir
        // «buenas tardes»; tratarlo como afirmación registraría un
        // consentimiento inexistente. El null obliga a decidir.
        self::assertNull(Afirmacion::de($mensaje), "«{$mensaje}» no responde sí ni no");
    }

    #[Test]
    public function lasPalabrasQueContienenSiONoNoCuentan(): void
    {
        // «sin» contiene «si»; «notificación» contiene «no». Es el mismo error
        // que la clase existe para evitar, un nivel más abajo.
        foreach (['sin problema', 'me llegó una notificación', 'la nota decía otra cosa'] as $mensaje) {
            self::assertNull(Afirmacion::de($mensaje), "«{$mensaje}» no es una respuesta");
        }
    }

    #[Test]
    public function laNegacionGanaSiempreAunqueVayaDespues(): void
    {
        // El orden dentro de la frase no puede cambiar el veredicto: si
        // ganara la primera marca encontrada, «acepto... no, espere» se
        // registraría como aceptación.
        self::assertFalse(Afirmacion::de('acepto, no, espere'));
        self::assertFalse(Afirmacion::de('no, acepto que es complicado pero no autorizo'));
    }
}
