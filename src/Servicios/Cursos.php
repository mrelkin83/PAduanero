<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Core\Respuesta;

/**
 * El catálogo público de cursos: `/cursos` y `/cursos/{slug}`.
 *
 * A diferencia de la landing y `/perfil`, no usa `CachePagina`: esa clase
 * cachea UNA página fija en UN archivo, y aquí hay tantas páginas como
 * cursos —y tantas variantes del catálogo como categorías de filtro—.
 * Cada visita consulta MySQL directamente: son consultas indexadas y
 * baratas, y cachear mal por slug es peor que no cachear.
 */
final class Cursos
{
    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
        private readonly string $urlBase,
    ) {
    }

    public function catalogo(?string $categoriaSlug): Respuesta
    {
        return Respuesta::vista('cursos/catalogo', [
            'cursos' => $this->publicados($categoriaSlug),
            'categorias' => $this->categoriasActivas(),
            'categoriaActual' => $categoriaSlug,
            'meta' => $this->meta(
                'Cursos',
                'Cursos de derecho aduanero y comercio exterior dictados por Pedro.',
                '/cursos',
            ),
        ]);
    }

    public function ficha(string $slug): Respuesta
    {
        $curso = $this->buscarPorSlug($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        return Respuesta::vista('cursos/ficha', [
            'curso' => $curso,
            'modulos' => $this->temario($curso['id']),
            'meta' => $this->meta($curso['titulo'], $curso['resumen'], '/cursos/' . $slug),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function buscarPorSlug(string $slug): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT c.*, cat.nombre AS categoria_nombre
               FROM cursos c JOIN categorias_curso cat ON cat.id = c.categoria_id
              WHERE c.slug = ?'
        );
        $stmt->execute([$slug]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            return null;
        }

        $fila['lo_que_aprendera'] = json_decode((string) $fila['lo_que_aprendera'], true) ?: [];

        return $fila;
    }

    /** @return list<array{titulo:string,lecciones:list<array<string,mixed>>}> */
    private function temario(string $cursoId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, titulo, orden FROM curso_modulos WHERE curso_id = ? ORDER BY orden'
        );
        $stmt->execute([$cursoId]);
        $modulos = $stmt->fetchAll();

        if ($modulos === []) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($modulos), '?'));
        $idsModulos = array_column($modulos, 'id');

        $stmt = $this->bd->pdo()->prepare(
            "SELECT modulo_id, titulo, duracion_min, orden, vista_previa_gratis
               FROM curso_lecciones WHERE modulo_id IN ({$marcas}) ORDER BY orden"
        );
        $stmt->execute($idsModulos);

        $leccionesPorModulo = [];
        foreach ($stmt->fetchAll() as $leccion) {
            $leccionesPorModulo[$leccion['modulo_id']][] = $leccion;
        }

        return array_map(
            static fn (array $modulo): array => [
                'titulo' => $modulo['titulo'],
                'lecciones' => $leccionesPorModulo[$modulo['id']] ?? [],
            ],
            $modulos,
        );
    }

    /** @return list<array<string,mixed>> */
    private function publicados(?string $categoriaSlug): array
    {
        $sql = "SELECT c.id, c.titulo, c.slug, c.resumen, c.nivel, c.precio_cop,
                       c.imagen_portada, cat.nombre AS categoria_nombre, cat.slug AS categoria_slug
                  FROM cursos c
                  JOIN categorias_curso cat ON cat.id = c.categoria_id
                 WHERE c.estado = 'publicado'";
        $parametros = [];

        if ($categoriaSlug !== null && $categoriaSlug !== '') {
            $sql .= ' AND cat.slug = ?';
            $parametros[] = $categoriaSlug;
        }

        $sql .= ' ORDER BY c.orden, c.titulo';

        $stmt = $this->bd->pdo()->prepare($sql);
        $stmt->execute($parametros);

        return $stmt->fetchAll();
    }

    /** @return list<array{id:string,nombre:string,slug:string}> */
    private function categoriasActivas(): array
    {
        return $this->bd->pdo()->query(
            'SELECT id, nombre, slug FROM categorias_curso WHERE activa = 1 ORDER BY orden, nombre'
        )->fetchAll();
    }

    /** @return array{titulo:string,descripcion:string,indexable:bool,url:string} */
    private function meta(string $titulo, string $descripcion, string $ruta): array
    {
        return [
            'titulo' => $titulo . ' · Pedro, abogado aduanero',
            'descripcion' => $descripcion,
            'indexable' => (bool) $this->config->get('landing_indexable', true),
            'url' => rtrim($this->urlBase, '/') . $ruta,
        ];
    }
}
