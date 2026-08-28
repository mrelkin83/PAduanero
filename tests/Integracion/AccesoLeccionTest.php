<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cuenta\AccesoLeccion;
use App\Modelos\Comprador;
use App\Repositorios\CompraCursoRepo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class AccesoLeccionTest extends CasoBaseBd
{
    private AccesoLeccion $acceso;
    private CompraCursoRepo $compras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = new CompraCursoRepo($this->bd);
        $this->acceso = new AccesoLeccion($this->compras);
    }

    private function comprador(string $id): Comprador
    {
        return new Comprador($id, 'Ana', 'Gómez', 'CC', '3001234567', 'ana@ejemplo.com');
    }

    #[Test]
    public function unaLeccionDeVistaPreviaEsVisibleParaCualquieraSinSesion(): void
    {
        $leccion = ['vista_previa_gratis' => 1];

        self::assertTrue($this->acceso->puedeVer(null, $leccion, 'curso-cualquiera'));
    }

    #[Test]
    public function unaLeccionQueNoEsPreviaNuncaEsVisibleSinSesion(): void
    {
        $leccion = ['vista_previa_gratis' => 0];

        self::assertFalse($this->acceso->puedeVer(null, $leccion, 'curso-cualquiera'));
    }

    #[Test]
    public function unCompradorQuePagoEseCursoVeLaLeccionNoPreview(): void
    {
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-acceso']);
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso acceso', 'curso-acceso-leccion', 'r', 'd', '[]', 250000, 'publicado']);

        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO compradores (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$compradorId, 'Ana', 'Gómez', 'CC', 'x', '3001234567', 'ana@ejemplo.com', 'hash']);

        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $leccion = ['vista_previa_gratis' => 0];

        self::assertTrue($this->acceso->puedeVer($this->comprador($compradorId), $leccion, $cursoId));
    }

    #[Test]
    public function unCompradorQuePagoOtroCursoNoVeEsteAunqueTengaSesion(): void
    {
        $leccion = ['vista_previa_gratis' => 0];
        $compradorSinComprasId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        self::assertFalse($this->acceso->puedeVer($this->comprador($compradorSinComprasId), $leccion, 'curso-que-no-compro'));
    }
}
