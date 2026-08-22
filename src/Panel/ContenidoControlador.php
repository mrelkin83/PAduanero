<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;
use App\Servicios\Landing;

/**
 * El contenido de la página pública, editable al fin desde el panel.
 *
 * Era la promesa original del panel («contenido editable desde el panel»,
 * CLAUDE.md §0) y la pantalla nunca existió: los bloques solo se tocaban por
 * SQL. Observación del PO, 2026-08-22.
 *
 * El formulario NO está escrito a mano por bloque: se GENERA de la estructura
 * del JSON guardado, y al guardar se reconstruye caminando esa misma
 * estructura. Dos consecuencias buscadas:
 *
 *   · Todo bloque presente y futuro es editable sin tocar este código.
 *   · La estructura no puede cambiar desde el panel — las claves y los tipos
 *     quedan como están, que es lo que las plantillas esperan. Un campo
 *     renombrado aquí sería una sección invisible allá (la trampa conocida
 *     del `continue` silencioso).
 *
 * La marca `pendiente` (migración 0015) recibe trato propio: la casilla
 * desmarcada QUITA la clave en vez de ponerla en false, porque la plantilla
 * y el tablero preguntan por su existencia — es la diferencia entre un
 * relleno que se ve como relleno y una constancia falsa.
 *
 * Al guardar se toca el centinela compartido: la landing y /perfil se
 * repintan en la siguiente visita, sin esperar el TTL.
 */
final class ContenidoControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly AuditoriaRepo $auditoria,
        private readonly Landing $landing,
    ) {
    }

    /* ── Lista ────────────────────────────────────────────────────────── */

    public function listar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'contenido.editar');

        $bloques = $this->bd->pdo()->query(
            'SELECT clave, titulo, orden, visible, actualizado_en, contenido
               FROM landing_bloques ORDER BY orden'
        )->fetchAll();

        foreach ($bloques as &$b) {
            // Cuántos datos siguen en relleno: es el aviso que evita que lo
            // provisional se quede para siempre (0015).
            $b['pendientes'] = substr_count((string) $b['contenido'], '"pendiente": true')
                + substr_count((string) $b['contenido'], '"pendiente":true');
            unset($b['contenido']);
        }

        return $this->vista('panel/contenido', [
            'ctx' => $ctx,
            'bloques' => $bloques,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /* ── Edición ──────────────────────────────────────────────────────── */

    public function editar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'contenido.editar');

        $bloque = $this->bloque((string) ($ctx->peticion->consulta['clave'] ?? ''));
        if ($bloque === null) {
            return $this->redirigirCon('/panel/contenido', 'error', 'Ese bloque no existe.');
        }

        return $this->vista('panel/contenido_editar', [
            'ctx' => $ctx,
            'bloque' => $bloque,
            // «datos» y no «contenido»: la disposición del panel usa
            // $contenido para el callable que pinta la pantalla.
            'datos' => json_decode((string) $bloque['contenido'], true) ?: [],
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'contenido.editar');

        $bloque = $this->bloque($ctx->campo('clave'));
        if ($bloque === null) {
            return $this->redirigirCon('/panel/contenido', 'error', 'Ese bloque no existe.');
        }

        $original = json_decode((string) $bloque['contenido'], true) ?: [];
        $enviado = $ctx->peticion->formulario['c'] ?? [];
        $nuevo = $this->reconstruir($original, is_array($enviado) ? $enviado : []);

        // Añadir o quitar elementos de una lista pasa POR AQUÍ y no por una
        // ruta aparte: así la operación guarda también lo que estuviera a
        // medio editar en el formulario, en vez de descartarlo.
        $mensaje = 'Bloque guardado. La página se repinta en la próxima visita.';

        $agregar = $ctx->campo('agregar');
        $quitar = $ctx->campo('quitar');
        if ($agregar !== '') {
            $lista = &$this->listaEn($nuevo, $agregar);
            if ($lista === null || $lista === []) {
                return $this->redirigirCon(
                    '/panel/contenido/editar?clave=' . urlencode((string) $bloque['clave']),
                    'error',
                    'Esa lista está vacía: no hay un elemento del que copiar la forma.',
                );
            }
            // El nuevo se clona del primero con los textos en blanco y nace
            // marcado como pendiente: lo recién añadido nunca es dato real.
            $lista[] = $this->plantillaDe($lista[0]);
            $mensaje = 'Elemento añadido al final, marcado como pendiente. Llénelo y guarde.';
        } elseif (preg_match('/^([a-z_]+):(\d+)$/', $quitar, $m) === 1) {
            $lista = &$this->listaEn($nuevo, $m[1]);
            if ($lista !== null && array_key_exists((int) $m[2], $lista)) {
                array_splice($lista, (int) $m[2], 1);
                $mensaje = 'Elemento eliminado y bloque guardado.';
            }
        }

        $orden = $ctx->campo('orden', (string) $bloque['orden']);
        if (preg_match('/^\d+$/', $orden) !== 1) {
            $orden = (string) $bloque['orden'];
        }

        $this->bd->pdo()->prepare(
            'UPDATE landing_bloques
                SET titulo = ?, subtitulo = ?, contenido = ?, orden = ?, visible = ?, actualizado_por = ?
              WHERE clave = ?'
        )->execute([
            $ctx->campo('titulo') !== '' ? $ctx->campo('titulo') : null,
            $ctx->campo('subtitulo') !== '' ? $ctx->campo('subtitulo') : null,
            json_encode($nuevo, JSON_UNESCAPED_UNICODE),
            (int) $orden,
            (int) ($ctx->campo('visible') === '1'),
            $ctx->usuario?->id,
            $bloque['clave'],
        ]);

        $this->publicar($ctx, (string) $bloque['clave'], 'actualizar', [
            'titulo' => $ctx->campo('titulo'),
        ]);

        return $this->redirigirCon(
            '/panel/contenido/editar?clave=' . urlencode((string) $bloque['clave']),
            'ok',
            $mensaje,
        );
    }

    /* ── Interno ──────────────────────────────────────────────────────── */

    private function bloque(string $clave): ?array
    {
        if ($clave === '') {
            return null;
        }
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM landing_bloques WHERE clave = ?');
        $stmt->execute([$clave]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /** Auditoría + invalidación de caché: guardar sin repintar es «el panel no guarda». */
    private function publicar(Contexto $ctx, string $clave, string $accion, array $datos): void
    {
        $this->auditoria->registrar('contenido', $clave, $accion, $ctx->actor(), $datos, $ctx->ip());
        $this->landing->invalidarCache();
    }

    /**
     * Reconstruye el contenido: la ESTRUCTURA la pone el original, los
     * VALORES los pone el formulario. Una clave que el formulario no mandó
     * conserva su valor; una clave nueva inventada en el POST no entra.
     */
    private function reconstruir(mixed $original, mixed $enviado): mixed
    {
        if (!is_array($original)) {
            return $this->escalar($original, $enviado);
        }

        $resultado = [];
        foreach ($original as $clave => $valor) {
            // `pendiente` desmarcado se QUITA, no se pone en false: plantilla
            // y tablero preguntan por su existencia (0015). Y solo lo quita
            // el «0» EXPLÍCITO del formulario (el campo oculto de la casilla):
            // una petición que no mande la casilla conserva la marca — quitar
            // el aviso de «no confirmado» por accidente es publicar como real
            // un dato que no lo es.
            if ($clave === 'pendiente') {
                if (!is_array($enviado) || !array_key_exists($clave, $enviado)) {
                    $resultado[$clave] = $valor;
                } elseif ($enviado[$clave] === '1') {
                    $resultado[$clave] = true;
                }
                continue;
            }

            $resultado[$clave] = array_key_exists($clave, $enviado ?: [])
                ? $this->reconstruir($valor, $enviado[$clave])
                : $valor;
        }

        return array_is_list($original) ? array_values($resultado) : $resultado;
    }

    private function escalar(mixed $original, mixed $enviado): mixed
    {
        if (is_array($enviado)) {
            return $original; // el tipo no cuadra: se conserva lo guardado
        }
        $texto = trim((string) $enviado);

        return match (true) {
            is_bool($original) => $texto === '1',
            is_int($original) => preg_match('/^-?\d+$/', $texto) === 1 ? (int) $texto : $original,
            is_float($original) => is_numeric($texto) ? (float) $texto : $original,
            default => $texto,
        };
    }

    /** @return array|null referencia a la lista en `ruta` (hoy: una clave de primer nivel) */
    private function &listaEn(array &$contenido, string $ruta): ?array
    {
        $nulo = null;
        if (!preg_match('/^[a-z_]+$/', $ruta) || !isset($contenido[$ruta]) || !is_array($contenido[$ruta])
            || !array_is_list($contenido[$ruta])) {
            return $nulo;
        }

        return $contenido[$ruta];
    }

    /** La forma de un elemento, con los valores en blanco y pendiente en alto. */
    private function plantillaDe(mixed $modelo): mixed
    {
        if (!is_array($modelo)) {
            return is_string($modelo) ? '' : (is_bool($modelo) ? false : 0);
        }

        $nuevo = [];
        foreach ($modelo as $clave => $valor) {
            $nuevo[$clave] = $clave === 'pendiente' ? true : $this->plantillaDe($valor);
        }
        if (!array_key_exists('pendiente', $nuevo) && !array_is_list($modelo)) {
            // Aunque la lista no lo usara, lo nuevo nace sin confirmar.
            $nuevo['pendiente'] = true;
        }

        return $nuevo;
    }
}
