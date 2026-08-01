<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reglas estructurales que ninguna prueba de comportamiento puede defender.
 *
 * Son las de `docs/CONTRATOS.md` §Errores que no producen un fallo visible
 * cuando se violan: el código sigue funcionando, y lo que se pierde es la
 * garantía. Nadie ve un rojo el día que alguien llama a `responder()` desde
 * el motor — lo que se ve es que un cliente recibió un borrador.
 *
 * Se comprueban leyendo el código fuente. Es tosco a propósito: una prueba
 * que se puede escribir en veinte líneas y que se ejecuta en milisegundos
 * vale más que un documento que nadie relee.
 */
#[Group('critica')]
final class ArquitecturaTest extends TestCase
{
    /** @return array<string,string> ruta relativa => contenido */
    private function fuentes(string $subdirectorio): array
    {
        $raiz = dirname(__DIR__, 2) . '/src/' . $subdirectorio;

        if (!is_dir($raiz)) {
            return [];
        }

        $archivos = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));

        foreach ($it as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php') {
                $archivos[$subdirectorio . '/' . $archivo->getFilename()]
                    = (string) file_get_contents($archivo->getPathname());
            }
        }

        return $archivos;
    }

    /** Quita comentarios: lo que importa es el código, no lo que se explica. */
    private function sinComentarios(string $codigo): string
    {
        $salida = '';

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $salida .= is_array($token) ? $token[1] : $token;
        }

        return $salida;
    }

    #[Test]
    public function elMotorNoTieneConQueHablarleAUnCliente(): void
    {
        // Esta es la garantía estructural, y es más fuerte que la de abajo: el
        // motor no depende de `Chatwoot` en absoluto. Lo que dice sale por el
        // outbox, y el manejador lo entrega con `entregar()`, que consulta
        // `motor_modo_sombra`.
        //
        // No es una convención que alguien pueda olvidar: es que dentro de
        // `src/Motor/` no existe el objeto con el que se enviaría un mensaje
        // directo a un contacto. Con `motor_modo_sombra` en true, la única
        // salida que hay es la nota privada.
        foreach ($this->fuentes('Motor') as $ruta => $codigo) {
            self::assertDoesNotMatchRegularExpression(
                '/\bChatwoot\b/',
                $this->sinComentarios($codigo),
                "{$ruta} conoce Chatwoot. El motor habla por Outbox; el modo sombra "
                . 'se decide al entregar, no aquí.',
            );
        }
    }

    #[Test]
    public function elMotorNoPuedeSaltarseElModoSombra(): void
    {
        // El motor habla por `entregar()`, que consulta `motor_modo_sombra`.
        // Llamar a `responder()` directamente enviaría al contacto un
        // borrador sin revisar, y no habría ningún síntoma hasta que un
        // cliente contestara a algo que Pedro nunca aprobó.
        //
        // Esta prueba existe ANTES que el motor conversacional a propósito:
        // si llegara después, la primera versión ya tendría la llamada
        // directa y habría que quitarla en vez de no escribirla.
        foreach ($this->fuentes('Motor') as $ruta => $codigo) {
            self::assertDoesNotMatchRegularExpression(
                '/->\s*responder\s*\(/',
                $this->sinComentarios($codigo),
                "{$ruta} llama a responder() directamente. El motor habla por entregar(), "
                . 'que es donde vive la decisión del modo sombra.',
            );
        }
    }

    #[Test]
    public function elSqlViveSoloEnLosRepositorios(): void
    {
        // Error 1 de CONTRATOS.md §Errores. Se comprueban las capas donde el
        // SQL suelto es un defecto de diseño y no una decisión: el motor no
        // debe tocar la base directamente.
        //
        // `src/Panel/` queda fuera a conciencia: sus controladores consultan
        // para pintar pantallas y eso ya es así desde la Etapa 3. Ampliarlo
        // ahí sería una refactorización, no una prueba.
        $sql = '/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b/i';

        foreach ($this->fuentes('Motor') as $ruta => $codigo) {
            self::assertDoesNotMatchRegularExpression(
                $sql,
                $this->sinComentarios($codigo),
                "{$ruta} tiene SQL. Todo el SQL vive en src/Repositorios/.",
            );
        }
    }

    /*
     * NO hay prueba de «los repositorios usan sentencias preparadas», y la
     * ausencia es una decisión.
     *
     * Se escribió y detectaba tres cosas legítimas: un `LIMIT` concatenado
     * con un entero ya acotado por `max(1, min(500, …))`, una interpolación
     * de nombre de columna con lista blanca inmediatamente encima —un
     * identificador no se puede vincular como parámetro— y la lista de `?`
     * generada para un `IN`. Mantenerla exigía una lista de excepciones que
     * habría que ampliar cada vez que alguien escribiera una consulta nueva.
     *
     * Una prueba con lista de excepciones creciente termina desactivada por
     * alguien con prisa, y ese día deja de proteger sin que nadie lo note:
     * el mismo modo de falla que se describe arriba. Prefiero no tenerla a
     * tenerla desactivada. La regla sigue en CONTRATOS.md §Errores 1 y se
     * comprueba leyendo el código.
     */

    #[Test]
    public function elMotorNoLlamaAEvolutionDirectamente(): void
    {
        // ADR-001: todo lo que el motor dice sale por Chatwoot, para que
        // quede en el hilo y el traspaso a humano sea instantáneo. Las
        // alertas internas a Pedro son la única excepción y no viven aquí.
        foreach ($this->fuentes('Motor') as $ruta => $codigo) {
            self::assertDoesNotMatchRegularExpression(
                '/evolution/i',
                $this->sinComentarios($codigo),
                "{$ruta} menciona Evolution. El motor habla por Chatwoot (ADR-001).",
            );
        }
    }
}
