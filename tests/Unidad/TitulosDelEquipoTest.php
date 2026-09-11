<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Nadie del despacho lleva un título que no tiene.
 *
 * Hoy eso significa una cosa concreta: **Erika Duarte Ruiz no es abogada.**
 * Es Profesional en Negocios Internacionales con especialización en Derecho
 * Aduanero y Comercio Exterior — un posgrado abierto a no abogados, que no
 * habilita para ejercer el derecho. Llamarla abogada en la página, en una
 * migración o en el prompt del bot no es una imprecisión de redacción: es
 * una afirmación sobre la habilitación profesional de una persona, publicada
 * por un despacho de abogados, y en Colombia eso expone al despacho.
 *
 * La prueba es del mismo tipo que `ArquitecturaTest`: se lee el código
 * fuente porque el fallo no se ve. Una landing que dice «nuestras abogadas»
 * se pinta igual de bien, pasa igual de verde y nadie lo nota hasta que
 * alguien de fuera lo lee.
 *
 * Se revisan las tres superficies donde el texto llega a un tercero: las
 * plantillas, las migraciones —que es donde vive el copy sembrado— y `src/`,
 * que incluye las reglas de dominio del bot.
 *
 * `tests/golden/conversaciones.json` queda fuera y no por descuido: su
 * trabajo es justamente contener estos patrones, escritos como lo que el bot
 * NO debe responder (`equipo-01` a `equipo-05`). Prohibirlos ahí sería
 * prohibir la prueba que los prohíbe.
 */
#[Group('critica')]
final class TitulosDelEquipoTest extends TestCase
{
    /**
     * Lo que no puede aparecer en ninguna parte.
     *
     * El segundo patrón es el que de verdad hace falta: el primero caza la
     * forma obvia («la abogada Erika») y el segundo la que se cuela sin
     * querer, con el título a cuarenta caracteres del nombre, en una frase
     * que empezó hablando de otra cosa.
     */
    private const PROHIBIDOS = [
        'título delante del nombre' => '/(la\s+)?abogad[ao]s?\s+(erika|duarte)/iu',
        'título cerca del nombre' => '/erika[^.\n]{0,40}\b(abogada|doctora|jurista|litigante)\b/iu',
        'el despacho en femenino plural' => '/\b(nuestras|las)\s+abogadas\b/iu',
    ];

    /** @return array<string,string> ruta relativa => contenido */
    private function superficies(): array
    {
        $raiz = dirname(__DIR__, 2);
        $archivos = [];

        foreach ([
            'plantillas' => ['php'],
            'db/migraciones' => ['sql'],
            'src' => ['php'],
        ] as $directorio => $extensiones) {
            $ruta = $raiz . '/' . $directorio;

            if (!is_dir($ruta)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($ruta));

            foreach ($it as $archivo) {
                if (!$archivo->isFile()) {
                    continue;
                }

                $extension = $archivo->getExtension();

                if (!in_array($extension, $extensiones, true)) {
                    continue;
                }

                $archivos[$directorio . '/' . $archivo->getFilename()] = $this->sinComentarios(
                    (string) file_get_contents($archivo->getPathname()),
                    $extension,
                );
            }
        }

        return $archivos;
    }

    /**
     * Quita los comentarios.
     *
     * No es cosmética: lo que esta prueba vigila es el texto que llega a un
     * tercero, y un comentario que explica *por qué* no se la puede llamar
     * abogada tiene que poder escribir la palabra. Sin este filtro, la única
     * forma de documentar la regla sería no documentarla.
     */
    private function sinComentarios(string $fuente, string $extension): string
    {
        if ($extension === 'sql') {
            return (string) preg_replace('/--[^\n]*/u', '', $fuente);
        }

        if ($extension !== 'php') {
            return $fuente;
        }

        $salida = '';

        foreach (token_get_all($fuente) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $salida .= is_array($token) ? $token[1] : $token;
        }

        return $salida;
    }

    #[Test]
    public function nadieLlamaAbogadaALaConsultora(): void
    {
        foreach ($this->superficies() as $ruta => $fuente) {
            foreach (self::PROHIBIDOS as $que => $patron) {
                self::assertDoesNotMatchRegularExpression(
                    $patron,
                    $fuente,
                    "«{$ruta}» usa el {$que}. Erika Duarte Ruiz es consultora "
                    . 'aduanera, no abogada: su especialización es un posgrado que '
                    . 'no habilita el ejercicio del derecho.',
                );
            }
        }
    }

    #[Test]
    public function elRolCorrectoSigueEnLaPagina(): void
    {
        // Prohibir el título equivocado no basta: si el correcto desaparece
        // del bloque de la landing, la sección queda con un nombre, una foto
        // y ninguna indicación de qué hace esa persona ahí — que es
        // exactamente la ambigüedad que la prohibición quería evitar.
        $migracion = (string) file_get_contents(
            dirname(__DIR__, 2) . '/db/migraciones/0041_equipo_erika.sql',
        );

        self::assertStringContainsString(
            'Consultora Aduanera y de Comercio Exterior',
            $migracion,
            'El bloque `equipo` ya no siembra el rol de la consultora.',
        );
    }
}
