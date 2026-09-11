<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El despacho tiene un solo número, y vive en `configuraciones`.
 *
 * Esto existe por un defecto encontrado el 2026-09-11:
 * `cuenta/enlace_invalido.php` llevaba `https://wa.me/573159923676` escrito a
 * mano, y la configuración de producción decía otro número. A quien se le
 * vencía el enlace de acceso a su curso se le ofrecía escribir a un teléfono
 * por el que el despacho no responde.
 *
 * No falló nunca. El enlace abría WhatsApp con toda normalidad, la página se
 * veía bien y nadie de este lado podía enterarse: la persona escribía, no le
 * contestaba nadie, y se iba. Es la forma de fallar que este proyecto ya
 * conoce —un valor creíble y falso—, y la única defensa posible es impedir
 * que el número se escriba en una plantilla.
 *
 * Los `placeholder` y los ejemplos del panel no cuentan: son texto de ayuda
 * para quien escribe el número, no un enlace que alguien pueda pulsar. Por
 * eso la prueba busca enlaces —`wa.me/…` y `tel:…`— y no dígitos sueltos.
 */
#[Group('critica')]
final class NumeroDeWhatsappTest extends TestCase
{
    #[Test]
    public function ningunaPlantillaLlevaUnNumeroEscritoAMano(): void
    {
        $raiz = dirname(__DIR__, 2) . '/plantillas';

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));

        $revisadas = 0;

        foreach ($it as $archivo) {
            if (!$archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }

            $revisadas++;
            $fuente = (string) file_get_contents($archivo->getPathname());
            $nombre = $archivo->getFilename();

            self::assertDoesNotMatchRegularExpression(
                '#(wa\.me/|api\.whatsapp\.com/send\?phone=)\d#u',
                $fuente,
                "«{$nombre}» lleva un número de WhatsApp escrito a mano. El del "
                . 'negocio está en `configuraciones.whatsapp_numero_negocio`: si se '
                . 'copia a una plantilla, el día que cambie quedan dos y nadie sabe '
                . 'cuál responde.',
            );

            self::assertDoesNotMatchRegularExpression(
                '#href="tel:\+?\d#u',
                $fuente,
                "«{$nombre}» lleva un teléfono escrito a mano. Los del pie salen del "
                . 'bloque `pie` (migración 0023), que Pedro edita desde el panel.',
            );
        }

        self::assertGreaterThan(
            10,
            $revisadas,
            'La prueba no encontró plantillas: el directorio cambió de sitio y '
            . 'esto dejó de comprobar nada.',
        );
    }
}
