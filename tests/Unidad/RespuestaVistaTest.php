<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Core\Respuesta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El renderizador no puede pisar los datos de la plantilla.
 *
 * Esta prueba nace de un fallo real: `Respuesta::vista()` hacía el
 * `extract()` en su propio ámbito, donde ya existían `$plantilla`, `$datos` y
 * `$estado`. Con `EXTR_SKIP`, una plantilla que esperaba `$estado` recibía el
 * código HTTP (200) y pintaba la sección vacía **sin un solo error**: la
 * pantalla de credenciales decía «sin guardar» sobre credenciales que sí
 * estaban guardadas.
 */
#[Group('critica')]
final class RespuestaVistaTest extends TestCase
{
    private string $directorio;

    protected function setUp(): void
    {
        $this->directorio = dirname(__DIR__, 2) . '/plantillas/pruebas';

        if (!is_dir($this->directorio)) {
            mkdir($this->directorio, 0o777, true);
        }

        file_put_contents(
            $this->directorio . '/eco.php',
            '<?php foreach ($esperados as $nombre) { echo $nombre, "=", var_export($$nombre ?? null, true), ";"; }',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->directorio . '/eco.php');
        @rmdir($this->directorio);
    }

    /** @return list<array{string,mixed}> */
    public static function nombresPeligrosos(): array
    {
        // Exactamente los nombres de los parámetros y locales de vista().
        return [
            ['estado', ['una', 'lista']],
            ['plantilla', 'valor de la plantilla'],
            ['datos', ['clave' => 'valor']],
            ['ruta', '/una/ruta'],
        ];
    }

    #[Test]
    #[DataProvider('nombresPeligrosos')]
    public function unaVariableConNombreInternoLlegaIntacta(string $nombre, mixed $valor): void
    {
        $respuesta = Respuesta::vista('pruebas/eco', [
            'esperados' => [$nombre],
            $nombre => $valor,
        ]);

        self::assertStringContainsString(
            $nombre . '=' . var_export($valor, true),
            $respuesta->cuerpo,
            "la plantilla no recibió «{$nombre}» tal cual",
        );
    }

    #[Test]
    public function elCodigoHttpSigueSiendoElDelArgumento(): void
    {
        // Que la plantilla reciba su propio `$estado` no debe alterar el
        // código HTTP de la respuesta.
        $respuesta = Respuesta::vista('pruebas/eco', ['esperados' => [], 'estado' => 'otra cosa'], 403);

        self::assertSame(403, $respuesta->estado);
    }

    #[Test]
    public function unaPlantillaQueRevientaNoDejaElBuferAbierto(): void
    {
        file_put_contents($this->directorio . '/rota.php', '<?php echo "a mitad"; throw new \RuntimeException("boom");');

        $nivel = ob_get_level();

        try {
            Respuesta::vista('pruebas/rota', []);
            self::fail('debió propagar la excepción');
        } catch (\RuntimeException) {
            // Sin el ob_end_clean, la salida a medias se mezclaría con la de
            // la página de error.
            self::assertSame($nivel, ob_get_level());
        } finally {
            @unlink($this->directorio . '/rota.php');
        }
    }

    #[Test]
    public function unNombreReservadoDelRenderizadorFallaEnVozAlta(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Respuesta::vista('pruebas/eco', ['esperados' => [], '__ruta' => 'x']);
    }
}
