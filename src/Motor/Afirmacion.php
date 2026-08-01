<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * ¿El contacto dijo que sí, que no, o ninguna de las dos?
 *
 * **Tres estados, no dos booleanos**, y esa es toda la clase.
 *
 * De dónde viene. El motor preguntaba primero `pareceAceptacion()` y después
 * `pareceNegativa()`, cada una con su regex. «No autorizo» contiene
 * «autorizo», así que la primera decía que sí y se registraba **un
 * consentimiento donde había un rechazo**. No falla nada visible: queda una
 * fila afirmando que la persona aceptó el tratamiento de sus datos. En un
 * sistema con expedientes bajo secreto profesional eso no es un defecto de
 * programación, es una constancia falsa.
 *
 * Corolario de la regla 1 que esta clase implementa (`CLAUDE.md` §4):
 *
 *   Donde una comprobación positiva y una negativa compitan sobre el mismo
 *   texto libre del contacto, la negativa se evalúa PRIMERO y gana. Ante
 *   ambigüedad, no se registra nada.
 *
 * Devolver `null` en vez de `false` es lo que impide repetir el error: con dos
 * booleanos, «ninguno de los dos» se parece demasiado a «no», y quien escriba
 * el próximo `if` tratará el silencio como una respuesta. Con `?bool`, el
 * `null` obliga a decidir qué hacer con él.
 *
 * Sirve para el consentimiento y para todo lo que venga después con la misma
 * forma: confirmar una hora, cancelar una reserva, aceptar un reagendamiento.
 */
final class Afirmacion
{
    /**
     * Marcas de negación. Se comprueban primero y ganan siempre.
     *
     * Incluyen las formas en que la gente escribe de verdad por WhatsApp, no
     * solo el «no» de manual: «nop», «para nada», «prefiero que no». Un
     * catálogo que solo reconoce el español cuidado deja fuera precisamente a
     * quien escribe rápido desde el celular.
     *
     * @var list<string>
     */
    private const NEGACIONES = [
        'no', 'nop', 'nel', 'jamas', 'nunca', 'niego', 'rechazo',
        'para nada', 'ni loco', 'ni de riesgo', 'negativo', 'mejor no',
        'prefiero que no', 'no gracias', 'olvidelo', 'dejelo asi', 'cancela',
    ];

    /** @var list<string> */
    private const AFIRMACIONES = [
        'si', 'sip', 'sii', 'claro', 'autorizo', 'acepto', 'de acuerdo',
        'dale', 'ok', 'okey', 'okay', 'listo', 'correcto', 'confirmo',
        'por supuesto', 'adelante', 'esta bien', 'vale', 'hagale', 'perfecto',
    ];

    /**
     * `true` sí · `false` no · `null` no se puede saber.
     *
     * El `null` no es un fallo: la mayoría de los mensajes no responden a
     * ninguna pregunta de sí o no, y tratarlos como negativa cerraría
     * conversaciones por escribir «buenas tardes».
     */
    public static function de(string $mensaje): ?bool
    {
        $normal = self::normalizar($mensaje);

        // La negación primero, siempre. Este orden ES la regla.
        if (self::contieneAlguna($normal, self::NEGACIONES)) {
            return false;
        }

        if (self::contieneAlguna($normal, self::AFIRMACIONES)) {
            return true;
        }

        return null;
    }

    public static function esNegativa(string $mensaje): bool
    {
        return self::de($mensaje) === false;
    }

    public static function esAfirmativa(string $mensaje): bool
    {
        return self::de($mensaje) === true;
    }

    /**
     * @param list<string> $marcas
     */
    private static function contieneAlguna(string $normal, array $marcas): bool
    {
        foreach ($marcas as $marca) {
            // Con límites de palabra: sin ellos, «sin» contiene «si» y
            // «notificación» contiene «no». Es el mismo tipo de error que la
            // clase existe para evitar, un nivel más abajo.
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($marca, '/') . '(?![\p{L}\p{N}])/u', $normal) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas y sin tildes: la gente escribe «sí» y «si» indistintamente
     * desde el celular, y una respuesta que no se reconoce por una tilde deja
     * al contacto atrapado repitiendo lo mismo.
     */
    private static function normalizar(string $texto): string
    {
        $minusculas = mb_strtolower(trim($texto), 'UTF-8');

        return strtr($minusculas, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u',
        ]);
    }
}
