<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\AccesoControlador;
use App\Cuenta\AccesoLeccion;
use App\Cuenta\AulaControlador;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\CursoMaterialRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Servicios\ConfigMysql;
use App\Servicios\Cursos;
use App\Soporte\BunnyStream;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class AulaControladorTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private AulaControlador $controlador;
    private CompradorRepo $compradores;
    private CompraCursoRepo $compras;
    private AutenticacionComprador $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->compras = new CompraCursoRepo($this->bd);
        $sesiones = new CompradorSesionRepo($this->bd);
        $this->auth = new AutenticacionComprador($this->compradores, $sesiones, new IntentoAccesoRepo($this->bd));

        // Mismo patrón que tests/Integracion/CursosTest.php::setUp(): ConfigMysql
        // exige rutas de archivo propias por corrida para no chocar con otras
        // pruebas del mismo proceso.
        $sufijo = bin2hex(random_bytes(4));
        $config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-aula-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-aula-cfg-{$sufijo}.json",
        );
        $cursos = new Cursos($this->bd, $config, self::URL);

        $this->controlador = new AulaControlador(
            $this->auth,
            $cursos,
            $this->compras,
            new AccesoLeccion($this->compras),
            $this->bd,
            new BunnyStream('', ''),
            new CursoMaterialRepo($this->bd),
        );
    }

    private function curso(string $slug): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-' . $slug]);
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, 'Curso aula', $slug, 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    private function peticion(): Peticion
    {
        return new Peticion(metodo: 'GET', ruta: '/mis-cursos/x');
    }

    #[Test]
    public function sinSesionRedirigeAEntrar(): void
    {
        $r = $this->controlador->aula($this->peticion(), 'cualquier-curso');

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function conSesionPeroSinHaberPagadoRedirigeAMisCursos(): void
    {
        $this->curso('curso-no-pagado');
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana@ejemplo.com', 'clave123');
        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->aula($this->peticion(), 'curso-no-pagado');

        self::assertSame(302, $r->estado);
        self::assertSame('/mis-cursos', $r->cabeceras['Location']);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function conElCursoPagadoMuestraElTemario(): void
    {
        $cursoId = $this->curso('curso-pagado-aula');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo uno', 0]);
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (UUID(), ?, ?, ?)')
            ->execute([$moduloId, 'Lección uno', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana2@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana2@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->aula($this->peticion(), 'curso-pagado-aula');

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('Módulo uno', $r->cuerpo);
        self::assertStringContainsString('Lección uno', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    private function leccionEnCurso(string $cursoId, bool $preview = false): string
    {
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden, vista_previa_gratis, contenido_texto)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$leccionId, $moduloId, 'Lección con contenido', 0, $preview ? 1 : 0, 'El contenido de la lección.']);

        return $leccionId;
    }

    #[Test]
    public function unaLeccionDePreviewSeVeSinSesion(): void
    {
        $cursoId = $this->curso('curso-preview-leccion');
        $leccionId = $this->leccionEnCurso($cursoId, preview: true);

        $r = $this->controlador->leccion($this->peticion(), 'curso-preview-leccion', $leccionId);

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('El contenido de la lección.', $r->cuerpo);
    }

    #[Test]
    public function unaLeccionNoPreviewSinSesionRedirigeAEntrar(): void
    {
        $cursoId = $this->curso('curso-no-preview-leccion');
        $leccionId = $this->leccionEnCurso($cursoId, preview: false);

        $r = $this->controlador->leccion($this->peticion(), 'curso-no-preview-leccion', $leccionId);

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function unaLeccionQueNoPerteneceAlCursoDeLaUrlDa404(): void
    {
        $cursoId = $this->curso('curso-a-leccion');
        $otroCursoId = $this->curso('curso-b-leccion');
        $leccionDeOtroCurso = $this->leccionEnCurso($otroCursoId, preview: true);

        $r = $this->controlador->leccion($this->peticion(), 'curso-a-leccion', $leccionDeOtroCurso);

        self::assertSame(404, $r->estado);
    }
}
