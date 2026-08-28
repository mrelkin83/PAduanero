<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CursoMaterialRepo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CursoMaterialRepoTest extends CasoBaseBd
{
    private CursoMaterialRepo $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CursoMaterialRepo($this->bd);
    }

    private function leccion(): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $slug = 'aduanero-' . uniqid();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', $slug]);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $cursoSlug = 'curso-' . uniqid();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso material', $cursoSlug, 'r', 'd', '[]', 250000, 'publicado']);
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo 1', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId, $moduloId, 'Lección 1', 0]);

        return $leccionId;
    }

    #[Test]
    public function crearYPorIdDevuelvenLosMismosDatos(): void
    {
        $leccionId = $this->leccion();

        $id = $this->repo->crear($leccionId, 'Plantilla de solicitud', 'abc123', 'pdf', 204800);

        $fila = $this->repo->porId($id);
        self::assertNotNull($fila);
        self::assertSame('Plantilla de solicitud', $fila['nombre']);
        self::assertSame('abc123', $fila['archivo']);
        self::assertSame('pdf', $fila['extension']);
        self::assertSame($leccionId, $fila['leccion_id']);
    }

    #[Test]
    public function deLeccionListaSoloLosDeEsaLeccionOrdenados(): void
    {
        $leccionId = $this->leccion();
        $otraLeccionId = $this->leccion();

        $this->repo->crear($leccionId, 'Segundo', 'b', 'pdf', 100);
        $this->repo->crear($leccionId, 'Primero', 'a', 'pdf', 100);
        $this->repo->crear($otraLeccionId, 'De otra lección', 'c', 'pdf', 100);

        $lista = $this->repo->deLeccion($leccionId);

        self::assertCount(2, $lista);
        self::assertSame('Segundo', $lista[0]['nombre']);
        self::assertSame('Primero', $lista[1]['nombre']);
    }

    #[Test]
    public function eliminarBorraLaFila(): void
    {
        $leccionId = $this->leccion();
        $id = $this->repo->crear($leccionId, 'A borrar', 'x', 'pdf', 100);

        $this->repo->eliminar($id);

        self::assertNull($this->repo->porId($id));
    }
}
