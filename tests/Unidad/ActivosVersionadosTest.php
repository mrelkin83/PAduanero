<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\Vista;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ningún `<script>` apunta a una ruta sin versión.
 *
 * Esto existe por lo que pasó en el despliegue del 2026-09-11, que salió
 * «bien» y estuvo mal durante un rato sin que nada lo dijera.
 *
 * Se desplegó un `perfil.js` nuevo. El origen lo servía correctamente. Pero
 * nginx marca los estáticos `max-age=2592000, immutable`, así que Cloudflare
 * tenía permiso para no volver a preguntar en treinta días — y siguió
 * entregando el archivo del 26 de agosto. El HTML sí se renovó, porque la
 * caché de páginas se borra en cada despliegue.
 *
 * El resultado fue lo peor de los dos mundos: una página con los `data-*`
 * nuevos y un script viejo que no sabía leerlos. Media función desaparecida,
 * cero errores en consola, cero errores en el servidor. Se descubrió porque
 * se comprobó el comportamiento en producción, no porque algo fallara.
 *
 * `Vista::activo()` le cuelga a la ruta el mtime del archivo: cuando el
 * archivo cambia, cambia la URL, y una URL nueva es una entrada nueva para
 * cualquier caché. No hay nada que purgar a mano y no hay forma de olvidarlo.
 */
#[Group('critica')]
final class ActivosVersionadosTest extends TestCase
{
    #[Test]
    public function ningunaPlantillaEnlazaUnScriptSinVersion(): void
    {
        $raiz = dirname(__DIR__, 2) . '/plantillas';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));

        $revisadas = 0;

        foreach ($it as $archivo) {
            if (!$archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }

            $revisadas++;

            self::assertDoesNotMatchRegularExpression(
                '#<script[^>]+src="/(js|css)/#u',
                (string) file_get_contents($archivo->getPathname()),
                "«{$archivo->getFilename()}» enlaza un estático por su ruta pelada. "
                . 'Tiene que ser `Vista::activo(\'/js/…\')`: nginx los sirve como '
                . '`immutable` durante 30 días, así que sin el `?v=<mtime>` el '
                . 'siguiente despliegue no llega al navegador de nadie y no avisa.',
            );
        }

        self::assertGreaterThan(10, $revisadas, 'La prueba no encontró plantillas.');
    }

    #[Test]
    public function laVersionCambiaCuandoCambiaElArchivo(): void
    {
        $url = Vista::activo('/js/perfil.js');

        self::assertMatchesRegularExpression('#^/js/perfil\.js\?v=\d+$#u', $url);

        // Y el número es el mtime real, no una constante que alguien dejó fija.
        self::assertStringEndsWith(
            '=' . (string) filemtime(dirname(__DIR__, 2) . '/public/js/perfil.js'),
            $url,
        );
    }

    #[Test]
    public function unArchivoQueNoExisteNoTumbaLaPagina(): void
    {
        // Un parámetro de caché no puede ser la razón de que una página no se
        // pinte. Sin archivo, la ruta sale tal cual.
        self::assertSame('/js/no-existe.js', Vista::activo('/js/no-existe.js'));
    }
}
