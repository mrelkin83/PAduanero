<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\AccesoControlador;
use App\Cuenta\MisCursosControlador;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class MisCursosControladorTest extends CasoBaseBd
{
    private MisCursosControlador $controlador;
    private AutenticacionComprador $auth;
    private CompradorRepo $compradores;
    private CompraCursoRepo $compras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->compras = new CompraCursoRepo($this->bd);
        $this->auth = new AutenticacionComprador($this->compradores, new CompradorSesionRepo($this->bd), new IntentoAccesoRepo($this->bd));
        $this->controlador = new MisCursosControlador($this->auth, $this->compras);
    }

    private function curso(string $titulo, string $slug): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-' . $slug]);
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, $titulo, $slug, 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    private function peticion(): Peticion
    {
        return new Peticion(metodo: 'GET', ruta: '/mis-cursos');
    }

    #[Test]
    public function sinSesionRedirigeAEntrar(): void
    {
        $r = $this->controlador->mostrar($this->peticion());

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function conSesionMuestraSoloLosCursosPagadosDeEseComprador(): void
    {
        $cursoId = $this->curso('Curso propio', 'curso-propio');
        $otroCursoId = $this->curso('Curso de otro', 'curso-de-otro');

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana@ejemplo.com', 'clave123');
        $otroCompradorId = $this->compradores->crear('Beto', 'Ruiz', 'CC', '2020202020', '3009999999', 'beto@ejemplo.com', 'clave123');

        $compraPropia = $this->compras->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraPropia);
        $this->compras->vincularComprador($compraPropia, $compradorId);

        $compraAjena = $this->compras->crear($otroCursoId, 'Beto', 'beto@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraAjena);
        $this->compras->vincularComprador($compraAjena, $otroCompradorId);

        $comprador = $this->compradores->porId($compradorId);
        $token = $this->auth->abrirSesion($comprador, null, null);
        $_COOKIE[AccesoControlador::COOKIE] = $token;

        $r = $this->controlador->mostrar($this->peticion());

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('Curso propio', $r->cuerpo);
        self::assertStringNotContainsString('Curso de otro', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }
}
