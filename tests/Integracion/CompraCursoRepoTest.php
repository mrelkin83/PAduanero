<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompraCursoRepo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CompraCursoRepoTest extends CasoBaseBd
{
    private CompraCursoRepo $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CompraCursoRepo($this->bd);
    }

    private function categoria(): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$id, 'Aduanero', 'aduanero']);

        return $id;
    }

    private function curso(): string
    {
        $catId = $this->categoria();
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, 'Curso de prueba', 'curso-de-prueba', 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    #[Test]
    public function crearYConsultarPorId(): void
    {
        $cursoId = $this->curso();

        $id = $this->repo->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
        $fila = $this->repo->porId($id);

        self::assertNotNull($fila);
        self::assertSame('pendiente', $fila['estado']);
        self::assertSame(250000, (int) $fila['precio_cop']);
    }

    #[Test]
    public function pendientePorReferenciaEncuentraPorReferenciaExacta(): void
    {
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->guardarReferencia($id, 'test_ABC123_1787000000_xyz', 'test_ABC123');

        $fila = $this->repo->pendientePorReferencia('test_ABC123_1787000000_xyz');

        self::assertNotNull($fila);
        self::assertSame($id, $fila['id']);
    }

    #[Test]
    public function pendientePorReferenciaCaeAlPaymentLinkIdSiLaReferenciaRoto(): void
    {
        // La trampa documentada en WompiAdapter: la referencia cambia en
        // cada sesión de checkout, pero el payment_link_id que trae el
        // webhook es estable y coincide con el externo_id que guardó el
        // checkout (Task 8).
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->guardarReferencia($id, 'test_ABC123_1787000000_xyz', 'test_ABC123');

        $fila = $this->repo->pendientePorReferencia('test_ABC123_9999999999_otraRandom', 'test_ABC123');

        self::assertNotNull($fila);
        self::assertSame($id, $fila['id']);
    }

    #[Test]
    public function sinPaymentLinkIdUnaReferenciaQueNoCoincideNoEncuentraNada(): void
    {
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->guardarReferencia($id, 'test_ABC123_1787000000_xyz', 'test_ABC123');

        self::assertNull($this->repo->pendientePorReferencia('otra-referencia-cualquiera'));
    }

    #[Test]
    public function unaCompraYaPagadaNoApareceComoPendiente(): void
    {
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->guardarReferencia($id, 'test_XYZ_1787000000_abc', 'test_XYZ');
        $this->repo->marcarPagada($id);

        self::assertNull($this->repo->pendientePorReferencia('test_XYZ_1787000000_abc'));
    }

    #[Test]
    public function vincularCompradorYListarSusComprasPagadas(): void
    {
        $cursoId = $this->curso();
        $id = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->marcarPagada($id);

        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO compradores (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$compradorId, 'Ana', 'Gómez', 'CC', 'x', '300', 'ana@ejemplo.com', 'hash']);

        $this->repo->vincularComprador($id, $compradorId);

        $compras = $this->repo->pagadasDeComprador($compradorId);

        self::assertCount(1, $compras);
        self::assertSame('Curso de prueba', $compras[0]['titulo']);
    }

    #[Test]
    public function marcarFallidaCambiaElEstado(): void
    {
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);

        $this->repo->marcarFallida($id);

        self::assertSame('fallida', $this->repo->porId($id)['estado']);
    }

    #[Test]
    public function tienePagadaEsTrueSoloSiEseCompradorPagoEseCurso(): void
    {
        $cursoId = $this->curso();

        $stmt = $this->bd->pdo()->prepare('SELECT categoria_id FROM cursos WHERE id = ?');
        $stmt->execute([$cursoId]);
        $catId = (string) $stmt->fetchColumn();
        $otroCursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$otroCursoId, $catId, 'Otro curso', 'otro-curso-tiene-pagada', 'r', 'd', '[]', 250000, 'publicado']);

        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $otroCompradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO compradores (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$compradorId, 'Ana', 'Gómez', 'CC', 'x', '300', 'ana@ejemplo.com', 'hash']);
        $this->bd->pdo()->prepare(
            'INSERT INTO compradores (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$otroCompradorId, 'Carlos', 'López', 'CC', 'y', '301', 'carlos@ejemplo.com', 'hash']);

        $compraId = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->marcarPagada($compraId);
        $this->repo->vincularComprador($compraId, $compradorId);

        self::assertTrue($this->repo->tienePagada($compradorId, $cursoId));
        self::assertFalse($this->repo->tienePagada($compradorId, $otroCursoId));
        self::assertFalse($this->repo->tienePagada($otroCompradorId, $cursoId));
    }

    #[Test]
    public function tienePagadaEsFalseSiLaCompraNoEstaPagada(): void
    {
        $cursoId = $this->curso();
        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO compradores (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$compradorId, 'Ana', 'Gómez', 'CC', 'x', '300', 'ana@ejemplo.com', 'hash']);

        $compraId = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->vincularComprador($compraId, $compradorId);
        // Deliberadamente sin marcarPagada(): sigue en 'pendiente'.

        self::assertFalse($this->repo->tienePagada($compradorId, $cursoId));
    }

    #[Test]
    public function idDePagadaPorCompradorDevuelveElIdSoloSiEstaPagada(): void
    {
        $cursoId = $this->curso();
        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO compradores (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$compradorId, 'Ana', 'Gómez', 'CC', 'x', '300', 'ana@ejemplo.com', 'hash']);

        $compraId = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->vincularComprador($compraId, $compradorId);

        self::assertNull($this->repo->idDePagadaPorComprador($compradorId, $cursoId));

        $this->repo->marcarPagada($compraId);

        self::assertSame($compraId, $this->repo->idDePagadaPorComprador($compradorId, $cursoId));
    }

    #[Test]
    public function idDePagadaPorCompradorEsNullParaOtroComprador(): void
    {
        $cursoId = $this->curso();
        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $otroCompradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO compradores (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$compradorId, 'Ana', 'Gómez', 'CC', 'x', '300', 'ana@ejemplo.com', 'hash']);

        $compraId = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->marcarPagada($compraId);
        $this->repo->vincularComprador($compraId, $compradorId);

        self::assertNull($this->repo->idDePagadaPorComprador($otroCompradorId, $cursoId));
    }
}
