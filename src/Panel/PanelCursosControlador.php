<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;

/**
 * Catálogo de cursos. Mismo patrón que `TarifasControlador`: permisos
 * explícitos por acción, guarda de precio en pesos (ADR-010), y cada
 * escritura queda en `auditoria`.
 */
final class PanelCursosControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }

    public function listar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.ver');

        $cursos = $this->bd->pdo()->query(
            "SELECT c.id, c.titulo, c.slug, c.precio_cop, c.estado, cat.nombre AS categoria_nombre
               FROM cursos c JOIN categorias_curso cat ON cat.id = c.categoria_id
              ORDER BY c.orden, c.titulo"
        )->fetchAll();

        return $this->vista('panel/cursos', [
            'ctx' => $ctx,
            'cursos' => $cursos,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function editar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.ver');

        $id = $ctx->peticion->consulta['id'] ?? null;
        $curso = null;
        $modulos = [];

        if (is_string($id) && $id !== '') {
            $stmt = $this->bd->pdo()->prepare('SELECT * FROM cursos WHERE id = ?');
            $stmt->execute([$id]);
            $curso = $stmt->fetch() ?: null;

            if ($curso !== null) {
                $curso['lo_que_aprendera'] = implode(
                    "\n",
                    json_decode((string) $curso['lo_que_aprendera'], true) ?: [],
                );
                $modulos = $this->modulosConLecciones($id);
            }
        }

        $categorias = $this->bd->pdo()->query(
            'SELECT id, nombre FROM categorias_curso WHERE activa = 1 ORDER BY orden, nombre'
        )->fetchAll();

        return $this->vista('panel/cursos_editar', [
            'ctx' => $ctx,
            'curso' => $curso,
            'modulos' => $modulos,
            'categorias' => $categorias,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $titulo = $ctx->campo('titulo');
        $categoriaId = $ctx->campo('categoria_id');
        $resumen = $ctx->campo('resumen');
        $descripcion = $ctx->campo('descripcion');
        $precio = $ctx->campo('precio_cop');
        $nivel = $ctx->campo('nivel', 'basico');
        $orden = $ctx->campo('orden', '0');
        $imagen = $ctx->campo('imagen_portada');
        $bullets = $this->bullets($ctx->campo('lo_que_aprendera'));

        if ($titulo === '' || $categoriaId === '' || $resumen === '') {
            return $this->redirigirCon($this->rutaEdicion($id), 'error', 'Título, categoría y resumen son obligatorios.');
        }

        if (preg_match('/^\d+$/', $precio) !== 1) {
            return $this->redirigirCon($this->rutaEdicion($id), 'error', 'El precio debe ser un número entero.');
        }

        $precio = (int) $precio;

        // Mismo guardia que TarifasControlador::guardar(): a $400.000 el
        // curso, un cero de más son cuatro millones. El error es fácil
        // porque la pasarela del sub-proyecto 2 SÍ cobrará en centavos.
        if ($precio >= 10_000_000) {
            return $this->redirigirCon(
                $this->rutaEdicion($id),
                'error',
                'El precio va en PESOS, no en centavos. ¿Seguro que son $'
                    . number_format($precio, 0, ',', '.') . '? '
                    . 'Para $400.000 se escribe 400000.',
            );
        }

        if (!in_array($nivel, ['basico', 'intermedio', 'avanzado'], true)) {
            $nivel = 'basico';
        }

        $stmtCategoria = $this->bd->pdo()->prepare('SELECT id FROM categorias_curso WHERE id = ?');
        $stmtCategoria->execute([$categoriaId]);
        if ($stmtCategoria->fetch() === false) {
            return $this->redirigirCon($this->rutaEdicion($id), 'error', 'Esa categoría no existe.');
        }

        $loQueAprendera = json_encode($bullets, JSON_UNESCAPED_UNICODE) ?: '[]';

        if ($id === '') {
            $slug = $this->slugUnico($this->slugificar($titulo), 'cursos');
            $nuevoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

            $this->bd->pdo()->prepare(
                'INSERT INTO cursos
                    (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera,
                     nivel, precio_cop, imagen_portada, orden)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $nuevoId, $categoriaId, $titulo, $slug, $resumen, $descripcion,
                $loQueAprendera, $nivel, $precio, $imagen !== '' ? $imagen : null, (int) $orden,
            ]);

            $this->auditoria->registrar('curso', $nuevoId, 'crear', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

            return $this->redirigirCon($this->rutaEdicion($nuevoId), 'ok', 'Curso creado.');
        }

        $this->bd->pdo()->prepare(
            'UPDATE cursos
                SET categoria_id = ?, titulo = ?, resumen = ?, descripcion = ?, lo_que_aprendera = ?,
                    nivel = ?, precio_cop = ?, imagen_portada = ?, orden = ?
              WHERE id = ?'
        )->execute([
            $categoriaId, $titulo, $resumen, $descripcion, $loQueAprendera,
            $nivel, $precio, $imagen !== '' ? $imagen : null, (int) $orden, $id,
        ]);

        $this->auditoria->registrar('curso', $id, 'actualizar', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($id), 'ok', 'Curso actualizado.');
    }

    public function publicar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM cursos WHERE id = ?');
        $stmt->execute([$id]);
        $curso = $stmt->fetch();

        if ($curso === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Ese curso no existe.');
        }

        if ((int) $curso['precio_cop'] <= 0) {
            return $this->redirigirCon($this->rutaEdicion($id), 'error', 'El curso necesita un precio mayor que cero para publicarse.');
        }

        $tieneLeccion = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cm.curso_id = ?'
        );
        $tieneLeccion->execute([$id]);

        if ((int) $tieneLeccion->fetchColumn() === 0) {
            return $this->redirigirCon(
                $this->rutaEdicion($id),
                'error',
                'El curso necesita al menos un modulo con una leccion para publicarse.',
            );
        }

        $this->bd->pdo()->prepare(
            "UPDATE cursos SET estado = 'publicado', publicado_en = NOW() WHERE id = ?"
        )->execute([$id]);

        $this->auditoria->registrar('curso', $id, 'publicar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($id), 'ok', 'Curso publicado.');
    }

    public function despublicar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare('SELECT id FROM cursos WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->fetch() === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Ese curso no existe.');
        }

        $this->bd->pdo()->prepare("UPDATE cursos SET estado = 'borrador' WHERE id = ?")->execute([$id]);

        $this->auditoria->registrar('curso', $id, 'despublicar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($id), 'ok', 'Curso pasado a borrador.');
    }

    public function categorias(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.ver');

        $categorias = $this->bd->pdo()->query(
            'SELECT * FROM categorias_curso ORDER BY orden, nombre'
        )->fetchAll();

        return $this->vista('panel/cursos_categorias', [
            'ctx' => $ctx,
            'categorias' => $categorias,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardarCategoria(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $nombre = $ctx->campo('nombre');
        $orden = $ctx->campo('orden', '0');
        $activa = (int) ($ctx->campo('activa') === '1');

        if ($nombre === '') {
            return $this->redirigirCon('/panel/cursos/categorias', 'error', 'El nombre es obligatorio.');
        }

        if ($id === '') {
            $slug = $this->slugUnico($this->slugificar($nombre), 'categorias_curso');
            $nuevoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

            $this->bd->pdo()->prepare(
                'INSERT INTO categorias_curso (id, nombre, slug, orden, activa) VALUES (?, ?, ?, ?, ?)'
            )->execute([$nuevoId, $nombre, $slug, (int) $orden, $activa]);

            $this->auditoria->registrar('categoria_curso', $nuevoId, 'crear', $ctx->actor(), ['nombre' => $nombre], $ctx->ip());
        } else {
            $this->bd->pdo()->prepare(
                'UPDATE categorias_curso SET nombre = ?, orden = ?, activa = ? WHERE id = ?'
            )->execute([$nombre, (int) $orden, $activa, $id]);

            $this->auditoria->registrar('categoria_curso', $id, 'actualizar', $ctx->actor(), ['nombre' => $nombre], $ctx->ip());
        }

        return $this->redirigirCon('/panel/cursos/categorias', 'ok', 'Categoría guardada.');
    }

    public function agregarModulo(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $cursoId = $ctx->campo('curso_id');
        $titulo = $ctx->campo('titulo');

        if ($cursoId === '' || $titulo === '') {
            return $this->redirigirCon($this->rutaEdicion($cursoId), 'error', 'El módulo necesita un título.');
        }

        $siguienteOrden = $this->bd->pdo()->prepare(
            'SELECT COALESCE(MAX(orden), 0) + 1 FROM curso_modulos WHERE curso_id = ?'
        );
        $siguienteOrden->execute([$cursoId]);
        $orden = (int) $siguienteOrden->fetchColumn();

        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$id, $cursoId, $titulo, $orden]);

        $this->auditoria->registrar('curso_modulo', $id, 'crear', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($cursoId), 'ok', 'Módulo agregado.');
    }

    public function eliminarModulo(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare('SELECT curso_id FROM curso_modulos WHERE id = ?');
        $stmt->execute([$id]);
        $cursoId = $stmt->fetchColumn();

        if ($cursoId === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Ese módulo no existe.');
        }

        // ON DELETE CASCADE en curso_lecciones se encarga de sus lecciones.
        $this->bd->pdo()->prepare('DELETE FROM curso_modulos WHERE id = ?')->execute([$id]);

        $this->auditoria->registrar('curso_modulo', $id, 'eliminar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion((string) $cursoId), 'ok', 'Módulo eliminado.');
    }

    public function agregarLeccion(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $moduloId = $ctx->campo('modulo_id');
        $titulo = $ctx->campo('titulo');
        $duracion = $ctx->campo('duracion_min');
        $vistaPrevia = (int) ($ctx->campo('vista_previa_gratis') === '1');

        $stmt = $this->bd->pdo()->prepare('SELECT curso_id FROM curso_modulos WHERE id = ?');
        $stmt->execute([$moduloId]);
        $cursoId = $stmt->fetchColumn();

        if ($cursoId === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Ese módulo no existe.');
        }

        if ($titulo === '') {
            return $this->redirigirCon($this->rutaEdicion((string) $cursoId), 'error', 'La lección necesita un titulo.');
        }

        $duracionMin = preg_match('/^\d+$/', $duracion) === 1 ? (int) $duracion : null;

        $siguienteOrden = $this->bd->pdo()->prepare(
            'SELECT COALESCE(MAX(orden), 0) + 1 FROM curso_lecciones WHERE modulo_id = ?'
        );
        $siguienteOrden->execute([$moduloId]);
        $orden = (int) $siguienteOrden->fetchColumn();

        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, duracion_min, orden, vista_previa_gratis)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $moduloId, $titulo, $duracionMin, $orden, $vistaPrevia]);

        $this->auditoria->registrar('curso_leccion', $id, 'crear', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion((string) $cursoId), 'ok', 'Lección agregada.');
    }

    public function eliminarLeccion(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare(
            'SELECT cm.curso_id FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cl.id = ?'
        );
        $stmt->execute([$id]);
        $cursoId = $stmt->fetchColumn();

        if ($cursoId === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Esa lección no existe.');
        }

        $this->bd->pdo()->prepare('DELETE FROM curso_lecciones WHERE id = ?')->execute([$id]);

        $this->auditoria->registrar('curso_leccion', $id, 'eliminar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion((string) $cursoId), 'ok', 'Lección eliminada.');
    }

    private function rutaEdicion(string $id): string
    {
        return $id === '' ? '/panel/cursos/editar' : '/panel/cursos/editar?id=' . urlencode($id);
    }

    /** @return list<string> */
    private function bullets(string $texto): array
    {
        $lineas = preg_split('/\r\n|\r|\n/', $texto) ?: [];
        $lineas = array_map('trim', $lineas);

        return array_values(array_filter($lineas, static fn (string $l): bool => $l !== ''));
    }

    private function slugificar(string $texto): string
    {
        // No usa iconv('UTF-8', 'ASCII//TRANSLIT', ...): en el build de
        // Windows disponible aquí, esa función no quita los acentos sino que
        // los sustituye por marcas ('a, ~n) que el regex de abajo convertía
        // en guiones de más («protecci'on» → «protecci-on»). El mapa
        // explícito es portable entre builds de PHP/iconv.
        $mapa = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ];

        $slug = strtolower(strtr($texto, $mapa));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'curso';
    }

    private function slugUnico(string $base, string $tabla): string
    {
        $slug = $base;
        $sufijo = 2;
        $stmt = $this->bd->pdo()->prepare("SELECT COUNT(*) FROM {$tabla} WHERE slug = ?");

        while (true) {
            $stmt->execute([$slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $sufijo;
            $sufijo++;
        }
    }

    /** @return list<array{id:string,titulo:string,orden:int,lecciones:list<array<string,mixed>>}> */
    private function modulosConLecciones(string $cursoId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, titulo, orden FROM curso_modulos WHERE curso_id = ? ORDER BY orden'
        );
        $stmt->execute([$cursoId]);
        $modulos = $stmt->fetchAll();

        foreach ($modulos as &$modulo) {
            $stmtL = $this->bd->pdo()->prepare(
                'SELECT id, titulo, duracion_min, orden, vista_previa_gratis
                   FROM curso_lecciones WHERE modulo_id = ? ORDER BY orden'
            );
            $stmtL->execute([$modulo['id']]);
            $modulo['lecciones'] = $stmtL->fetchAll();
        }
        unset($modulo);

        return $modulos;
    }
}
