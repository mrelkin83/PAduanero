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
