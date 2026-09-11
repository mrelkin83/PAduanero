<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;
use App\Servicios\Landing;
use App\Soporte\SubidaImagen;

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
        // Punto de prueba de SubidaImagen::guardar(): null usa move_uploaded_file
        // (lo real); las pruebas inyectan copy(...), porque move_uploaded_file
        // siempre falla fuera de una petición HTTP genuina.
        private readonly mixed $moverImagen = null,
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
        $enviado = is_array($enviado) ? $enviado : [];

        // Los archivos que se hayan subido ganan sobre lo que haya en el
        // campo de texto de `imagen`/`logo`, ANTES de reconstruir: así
        // reconstruir() no necesita saber que existen — solo ve un texto
        // nuevo, como si el operador lo hubiera tecleado.
        $archivosCrudos = $ctx->peticion->archivos['c'] ?? [];
        $errorSubida = $this->inyectarSubidas(
            $original,
            $enviado,
            self::normalizarArchivos(is_array($archivosCrudos) ? $archivosCrudos : []),
        );
        if ($errorSubida !== null) {
            return $this->redirigirCon(
                '/panel/contenido/editar?clave=' . urlencode((string) $bloque['clave']),
                'error',
                $errorSubida,
            );
        }

        $nuevo = $this->reconstruir($original, $enviado);

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

    /**
     * Camina `$original` en paralelo con `$archivos` (ya normalizado) y, para
     * cada campo `imagen`/`logo` con un archivo subido válido, escribe el
     * nombre generado directo en `$enviado` — en el mismo lugar donde
     * `reconstruir()` esperaría encontrar el texto tecleado.
     *
     * Devuelve el primer error de subida que encuentre (y corta ahí; no
     * tiene sentido guardar la mitad de las fotos de una tarjeta y fallar en
     * la otra), o null si no hubo ningún problema.
     */
    private function inyectarSubidas(mixed $original, array &$enviado, mixed $archivos): ?string
    {
        if (!is_array($original)) {
            return null;
        }

        foreach ($original as $clave => $valor) {
            if (is_array($valor)) {
                if (!is_array($enviado[$clave] ?? null)) {
                    continue;
                }
                $error = $this->inyectarSubidas(
                    $valor,
                    $enviado[$clave],
                    is_array($archivos) && is_array($archivos[$clave] ?? null) ? $archivos[$clave] : [],
                );
                if ($error !== null) {
                    return $error;
                }
                continue;
            }

            if (!in_array($clave, ['imagen', 'logo'], true) || !is_array($archivos)) {
                continue;
            }
            $archivo = $archivos[$clave . '__archivo'] ?? null;
            if (!is_array($archivo)) {
                continue;
            }

            // Subcarpeta propia («subidas/») y no /img/ directo: las fotos
            // de la landing las pone Elkin a mano en el despliegue y viven
            // en root; www-data solo necesita escritura sobre lo que sube
            // el panel, no sobre todo /img/. La ruta guardada sigue siendo
            // relativa a /img/, así que `<img src="/img/{ruta}">` no cambia.
            $subida = SubidaImagen::guardar(
                $archivo,
                dirname(__DIR__, 2) . '/public/img/subidas',
                (string) $clave,
                is_callable($this->moverImagen) ? $this->moverImagen : null,
            );
            if ($subida['error'] !== '') {
                return $subida['error'];
            }
            if ($subida['ok']) {
                $enviado[$clave] = 'subidas/' . $subida['nombre'];
            }
        }

        return null;
    }

    /**
     * $_FILES anida por PROPIEDAD primero (name/type/tmp_name/error/size) y
     * por CAMPO después — al revés de $_POST. Aquí se reacomoda a la forma
     * normal para poder caminarla exactamente igual que $enviado.
     */
    private static function normalizarArchivos(array $files): array
    {
        if (!array_key_exists('name', $files)) {
            $out = [];
            foreach ($files as $clave => $sub) {
                $out[$clave] = is_array($sub) ? self::normalizarArchivos($sub) : $sub;
            }

            return $out;
        }

        if (!is_array($files['name'])) {
            return $files;
        }

        $out = [];
        foreach (array_keys($files['name']) as $clave) {
            $out[$clave] = self::normalizarArchivos([
                'name' => $files['name'][$clave],
                'type' => $files['type'][$clave] ?? '',
                'tmp_name' => $files['tmp_name'][$clave] ?? '',
                'error' => $files['error'][$clave] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$clave] ?? 0,
            ]);
        }

        return $out;
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
