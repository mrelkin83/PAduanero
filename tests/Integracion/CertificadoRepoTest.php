<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CertificadoRepoTest extends CasoBaseBd
{
    private CertificadoRepo $repo;
    private CompraCursoRepo $compras;
    private CompradorRepo $compradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CertificadoRepo($this->bd);
        $this->compras = new CompraCursoRepo($this->bd);
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
    }

    /** @return array{compraId:string,compradorId:string} */
    private function compraDePrueba(): array
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-cert']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso certificado', 'curso-certificado', 'r', 'd', '[]', 250000, 'publicado']);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-cert@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-cert@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        return ['compraId' => $compraId, 'compradorId' => $compradorId];
    }

    #[Test]
    public function crearYPorCompraDevuelvenLaMismaFila(): void
    {
        $datos = $this->compraDePrueba();

        $this->repo->crear($datos['compraId'], 'PA-ABCD1234');

        $fila = $this->repo->porCompra($datos['compraId']);
        self::assertNotNull($fila);
        self::assertSame('PA-ABCD1234', $fila['codigo_verificacion']);
    }

    #[Test]
    public function porCompraEsNullSiNoSeHaEmitido(): void
    {
        $datos = $this->compraDePrueba();

        self::assertNull($this->repo->porCompra($datos['compraId']));
    }

    #[Test]
    public function porCodigoTraeElNombreDelCompradorYElTituloDelCurso(): void
    {
        $datos = $this->compraDePrueba();
        $this->repo->crear($datos['compraId'], 'PA-XYZ98765');

        $fila = $this->repo->porCodigo('PA-XYZ98765');

        self::assertNotNull($fila);
        self::assertSame('Ana', $fila['nombres']);
        self::assertSame('Gómez', $fila['apellidos']);
        self::assertSame('Curso certificado', $fila['curso_titulo']);
    }

    #[Test]
    public function porCodigoEsNullParaUnCodigoQueNoExiste(): void
    {
        self::assertNull($this->repo->porCodigo('PA-NOEXISTE'));
    }
}
