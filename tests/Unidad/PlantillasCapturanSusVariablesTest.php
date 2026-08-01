<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Toda variable usada dentro del `$contenido` de una plantilla del panel
 * tiene que estar en su lista `use`.
 *
 * Esta prueba existe por un defecto real: se añadió `$conteoModelos` a
 * `panel/ia.php` sin ponerlo en la lista `use` de la clausura. Dentro valía
 * «indefinido», el `?? 0` de la línea lo convertía en cero, y la columna
 * «Modelos» pintaba «—» para un proveedor que tenía 336 en la base.
 *
 * No hubo error, ni aviso, ni página en blanco: hubo un número creíble y
 * falso. Es la forma de fallar más cara del proyecto, porque nadie va a
 * investigar un cero que parece razonable.
 *
 * Y no es un descuido puntual: la lista `use` de estas plantillas tiene diez
 * o doce nombres, hay que tocarla cada vez que el controlador manda un dato
 * nuevo, y olvidarla no rompe nada visiblemente. El patrón lo invita.
 *
 * El aislamiento que da la clausura vale la pena —impide que la plantilla lea
 * variables que el controlador no le pasó— pero necesita este cinturón.
 */
#[Group('critica')]
final class PlantillasCapturanSusVariablesTest extends TestCase
{
    private const DIRECTORIO = __DIR__ . '/../../plantillas/panel';

    /** Nombres que PHP resuelve sin captura. */
    private const LIBRES = ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_SESSION', '_ENV'];

    /** @return list<array{string,string}> */
    public static function plantillas(): array
    {
        $casos = [];

        foreach (glob(self::DIRECTORIO . '/*.php') ?: [] as $ruta) {
            $fuente = (string) file_get_contents($ruta);

            if (str_contains($fuente, '$contenido = static function')) {
                $casos[basename($ruta)] = [basename($ruta), $fuente];
            }
        }

        return $casos;
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('plantillas')]
    public function ningunaVariableDelContenidoSeQuedaSinCapturar(
        string $nombre,
        string $fuente,
    ): void {
        $tokens = token_get_all($fuente);
        $i = $this->posicionDelContenido($tokens, $nombre);

        [$capturadas, $i] = $this->listaUse($tokens, $i);
        [$cuerpo, ] = $this->cuerpo($tokens, $i);

        $faltan = [];

        foreach ($this->usadas($cuerpo) as $variable) {
            if (
                !in_array($variable, $capturadas, true)
                && !in_array($variable, self::LIBRES, true)
            ) {
                $faltan[$variable] = true;
            }
        }

        self::assertSame(
            [],
            array_keys($faltan),
            "{$nombre}: usa variables que no captura. Dentro de la clausura valen "
            . '«indefinido», y un `?? 0` o un `?? []` las convierte en un valor '
            . 'creíble y falso sin dar error.',
        );
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function posicionDelContenido(array $tokens, string $nombre): int
    {
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$contenido') {
                return $i;
            }
        }

        self::fail("{$nombre}: no se encontró \$contenido.");
    }

    /**
     * @param  list<array{int,string,int}|string> $tokens
     * @return array{list<string>,int}
     */
    private function listaUse(array $tokens, int $desde): array
    {
        $capturadas = [];
        $dentro = false;
        $total = count($tokens);

        for ($i = $desde; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_USE) {
                $dentro = true;
                continue;
            }

            if ($dentro) {
                if (is_array($token) && $token[0] === T_VARIABLE) {
                    $capturadas[] = ltrim($token[1], '$');
                    continue;
                }

                if ($token === ')') {
                    return [$capturadas, $i];
                }
            }

            // Una clausura sin `use` es legítima: no captura nada.
            if ($token === '{') {
                return [[], $i - 1];
            }
        }

        self::fail('lista `use` sin cerrar');
    }

    /**
     * Del `{` de apertura a su `}`.
     *
     * @param  list<array{int,string,int}|string> $tokens
     * @return array{list<array{int,string,int}|string>,int}
     */
    private function cuerpo(array $tokens, int $desde): array
    {
        $total = count($tokens);
        $nivel = 0;
        $cuerpo = [];

        for ($i = $desde; $i < $total; $i++) {
            $token = $tokens[$i];

            // Las llaves de apertura llegan como '{'; las de `${` y las de
            // interpolación tienen su propio token y también hay que contarlas.
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $nivel++;

                if ($nivel === 1) {
                    continue;
                }
            } elseif ($token === '}') {
                $nivel--;

                if ($nivel === 0) {
                    return [$cuerpo, $i];
                }
            }

            if ($nivel >= 1) {
                $cuerpo[] = $token;
            }
        }

        self::fail('cuerpo de la clausura sin cerrar');
    }

    /**
     * Variables que el cuerpo LEE sin haberlas definido antes.
     *
     * Se descarta lo que nace dentro: asignaciones, variables de `foreach`,
     * parámetros y capturas de clausuras anidadas, y los `catch`.
     *
     * @param  list<array{int,string,int}|string> $cuerpo
     * @return list<string>
     */
    private function usadas(array $cuerpo): array
    {
        $definidas = [];
        $leidas = [];
        $total = count($cuerpo);

        // Rangos «declarativos»: entre `function` y su `{`, y tras un `as`.
        // Ahí toda variable se define en vez de leerse.
        $declarando = 0;

        for ($i = 0; $i < $total; $i++) {
            $token = $cuerpo[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_FUNCTION, T_FN, T_CATCH], true)) {
                    $declarando = 1;
                    continue;
                }

                if ($token[0] === T_AS) {
                    $declarando = 2;
                    continue;
                }

                if ($token[0] !== T_VARIABLE) {
                    continue;
                }

                $nombre = ltrim($token[1], '$');

                if ($declarando > 0) {
                    $definidas[$nombre] = true;
                    continue;
                }

                // `$x =` la define; `$x ==`, `$x =>` y `$x .=` no. `.=` lee
                // antes de escribir, así que cuenta como lectura.
                $siguiente = $this->siguienteSignificativo($cuerpo, $i);

                if ($siguiente === '=') {
                    $definidas[$nombre] = true;
                    continue;
                }

                if (!isset($definidas[$nombre])) {
                    $leidas[$nombre] = true;
                }

                continue;
            }

            if ($declarando === 1 && $token === '{') {
                $declarando = 0;
            }

            if ($declarando === 2 && ($token === ')' || $token === ':')) {
                $declarando = 0;
            }
        }

        return array_keys($leidas);
    }

    /** @param list<array{int,string,int}|string> $cuerpo */
    private function siguienteSignificativo(array $cuerpo, int $desde): ?string
    {
        $total = count($cuerpo);

        for ($i = $desde + 1; $i < $total; $i++) {
            $token = $cuerpo[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return null;
    }
}
