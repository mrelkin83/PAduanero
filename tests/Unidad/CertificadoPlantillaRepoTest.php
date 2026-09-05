<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Repositorios\CertificadoPlantillaRepo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La normalización de los campos de la plantilla: los defaults rellenan lo que
 * falte y los valores fuera de rango se recortan, para que nunca llegue basura
 * al motor del PDF. `campos()` es estático y puro: no toca base de datos.
 */
final class CertificadoPlantillaRepoTest extends TestCase
{
    #[Test]
    public function unJsonNuloDaLosDefaultsDeTodosLosCampos(): void
    {
        $campos = CertificadoPlantillaRepo::campos(null);

        foreach (['nombre', 'curso', 'fecha', 'documento', 'codigo', 'qr'] as $clave) {
            self::assertArrayHasKey($clave, $campos, "Falta el campo {$clave}");
            self::assertArrayHasKey('y', $campos[$clave]);
            self::assertArrayHasKey('visible', $campos[$clave]);
        }
    }

    #[Test]
    public function losValoresFueraDeRangoSeRecortan(): void
    {
        $json = json_encode([
            'nombre' => ['y' => 999, 'x' => -20, 'tamano' => 500, 'color' => 'noesuncolor', 'alineacion' => 'diagonal'],
        ]);
        $campos = CertificadoPlantillaRepo::campos($json);

        self::assertSame(100, $campos['nombre']['y']);       // tope 100
        self::assertSame(0, $campos['nombre']['x']);         // piso 0
        self::assertSame(96, $campos['nombre']['tamano']);   // tope 96
        self::assertSame('#1a1a1a', $campos['nombre']['color']);      // color inválido → default
        self::assertSame('center', $campos['nombre']['alineacion']);  // alineación inválida → default
    }

    #[Test]
    public function respetaLosValoresValidosGuardados(): void
    {
        $json = json_encode([
            'curso' => ['y' => 55, 'x' => 10, 'tamano' => 24, 'color' => '#abcdef', 'alineacion' => 'left', 'visible' => false],
        ]);
        $campos = CertificadoPlantillaRepo::campos($json);

        self::assertSame(55, $campos['curso']['y']);
        self::assertSame('#abcdef', $campos['curso']['color']);
        self::assertSame('left', $campos['curso']['alineacion']);
        self::assertFalse($campos['curso']['visible']);
    }

    #[Test]
    public function unJsonCorruptoNoRevientaYCaeAlosDefaults(): void
    {
        $campos = CertificadoPlantillaRepo::campos('{no es json valido');

        self::assertSame(45, $campos['nombre']['y']);
        self::assertTrue($campos['nombre']['visible']);
    }
}
