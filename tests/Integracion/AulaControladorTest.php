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
        $cursos = new Cursos($this->bd, $config, self::URL, new BunnyStream('', ''));

        $certificados = new \App\Repositorios\CertificadoRepo($this->bd);
        $this->controlador = new AulaControlador(
            $this->auth,
            $cursos,
            $this->compras,
            new AccesoLeccion($this->compras),
            $this->bd,
            new BunnyStream('', ''),
            new CursoMaterialRepo($this->bd),
            new \App\Cuenta\ProgresoCurso($this->bd, $certificados),
            $certificados,
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

    #[Test]
    public function descargarUnMaterialSinAccesoRedirige(): void
    {
        $cursoId = $this->curso('curso-material-sin-acceso');
        $leccionId = $this->leccionEnCurso($cursoId, preview: false);
        $materiales = new \App\Repositorios\CursoMaterialRepo($this->bd);
        $materialId = $materiales->crear($leccionId, 'Plantilla', 'abc', 'pdf', 10);

        $r = $this->controlador->material($this->peticion(), 'curso-material-sin-acceso', $leccionId, $materialId);

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function descargarUnMaterialConAccesoDevuelveElArchivo(): void
    {
        $cursoId = $this->curso('curso-material-con-acceso');
        $leccionId = $this->leccionEnCurso($cursoId, preview: true);
        $carpeta = dirname(__DIR__, 2) . '/storage/cursos/materiales/' . $leccionId;
        @mkdir($carpeta, 0775, true);
        file_put_contents($carpeta . '/abc123.pdf', '%PDF-1.4 contenido');

        $materiales = new \App\Repositorios\CursoMaterialRepo($this->bd);
        $materialId = $materiales->crear($leccionId, 'Plantilla', 'abc123', 'pdf', 19);

        $r = $this->controlador->material($this->peticion(), 'curso-material-con-acceso', $leccionId, $materialId);

        self::assertSame(200, $r->estado);
        self::assertSame('%PDF-1.4 contenido', $r->cuerpo);
        self::assertStringContainsString('Plantilla.pdf', $r->cabeceras['Content-Disposition']);

        unlink($carpeta . '/abc123.pdf');
        rmdir($carpeta);
    }

    #[Test]
    public function descargarUnMaterialDeOtraLeccionDa404(): void
    {
        $cursoId = $this->curso('curso-material-otra-leccion');
        $leccionId = $this->leccionEnCurso($cursoId, preview: true);
        $otraLeccionId = $this->leccionEnCurso($cursoId, preview: true);
        $materiales = new \App\Repositorios\CursoMaterialRepo($this->bd);
        $materialId = $materiales->crear($otraLeccionId, 'Plantilla', 'abc', 'pdf', 10);

        $r = $this->controlador->material($this->peticion(), 'curso-material-otra-leccion', $leccionId, $materialId);

        self::assertSame(404, $r->estado);
    }

    #[Test]
    public function verUnaLeccionRegistraElProgresoDelCompradorQuePago(): void
    {
        $cursoId = $this->curso('curso-progreso-leccion');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId, $moduloId, 'Lección', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-vp@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-vp@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $this->controlador->leccion($this->peticion(), 'curso-progreso-leccion', $leccionId);

        $total = (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM curso_progreso WHERE comprador_id = '{$compradorId}' AND leccion_id = '{$leccionId}'"
        )->fetchColumn();
        self::assertSame(1, $total);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function elAulaMuestraElConteoDeProgreso(): void
    {
        $cursoId = $this->curso('curso-aula-progreso');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (UUID(), ?, ?, ?)')
            ->execute([$moduloId, 'Lección', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-aula-prog@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-aula-prog@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->aula($this->peticion(), 'curso-aula-progreso');

        self::assertStringContainsString('0 de 1', $r->cuerpo);
        self::assertStringNotContainsString('Descargar certificado', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function elAulaMuestraElEnlaceDeDescargaCuandoYaEstaCompleto(): void
    {
        $cursoId = $this->curso('curso-aula-completo');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId, $moduloId, 'Lección', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-aula-comp@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-aula-comp@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $this->controlador->leccion($this->peticion(), 'curso-aula-completo', $leccionId);

        $r = $this->controlador->aula($this->peticion(), 'curso-aula-completo');

        self::assertStringContainsString('Descargar certificado', $r->cuerpo);
        self::assertStringContainsString('/mis-cursos/curso-aula-completo/certificado', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function elCertificadoSigueVisibleAunqueSeAgregueUnaLeccionNueva(): void
    {
        $cursoId = $this->curso('curso-certificado-persiste');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId, $moduloId, 'Lección', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-cert-persiste@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-cert-persiste@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $certificados = new \App\Repositorios\CertificadoRepo($this->bd);
        $certificados->crear($compraId, 'PA-TEST0001');

        // Se agrega una lección nueva DESPUÉS de emitido el certificado: si el
        // aula volviera a calcular estaCompleto() en vez de mirar si ya existe
        // el certificado, el enlace desaparecería porque ahora faltaría una
        // lección por ver.
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (UUID(), ?, ?, ?)')
            ->execute([$moduloId, 'Lección agregada después', 1]);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->aula($this->peticion(), 'curso-certificado-persiste');

        self::assertStringContainsString('Descargar certificado', $r->cuerpo);
        self::assertStringContainsString('/mis-cursos/curso-certificado-persiste/certificado', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function elVideoLocalSinAccesoRedirige(): void
    {
        $cursoId = $this->curso('curso-video-sin-acceso');
        $leccionId = $this->leccionEnCurso($cursoId, preview: false);
        $this->bd->pdo()->prepare('UPDATE curso_lecciones SET video_archivo = ? WHERE id = ?')
            ->execute(['clase.mp4', $leccionId]);

        $r = $this->controlador->video($this->peticion(), 'curso-video-sin-acceso', $leccionId);

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function elVideoLocalConAccesoDelegaEnNginxConXAccelRedirect(): void
    {
        $cursoId = $this->curso('curso-video-con-acceso');
        $leccionId = $this->leccionEnCurso($cursoId, preview: true);
        $this->bd->pdo()->prepare('UPDATE curso_lecciones SET video_archivo = ? WHERE id = ?')
            ->execute(['clase.mp4', $leccionId]);

        $carpeta = dirname(__DIR__, 2) . '/storage/cursos/videos/' . $leccionId;
        @mkdir($carpeta, 0775, true);
        file_put_contents($carpeta . '/clase.mp4', 'FAKEMP4');

        $r = $this->controlador->video($this->peticion(), 'curso-video-con-acceso', $leccionId);

        self::assertSame(200, $r->estado);
        self::assertSame('video/mp4', $r->cabeceras['Content-Type']);
        self::assertSame('/_video_protegido/' . $leccionId . '/clase.mp4', $r->cabeceras['X-Accel-Redirect']);
        // El cuerpo va VACÍO: el archivo lo entrega nginx, no PHP.
        self::assertSame('', $r->cuerpo);

        unlink($carpeta . '/clase.mp4');
        rmdir($carpeta);
    }

    #[Test]
    public function elVideoLocalConAccesoPeroSinElArchivoEnDiscoDa404(): void
    {
        $cursoId = $this->curso('curso-video-sin-archivo');
        $leccionId = $this->leccionEnCurso($cursoId, preview: true);
        $this->bd->pdo()->prepare('UPDATE curso_lecciones SET video_archivo = ? WHERE id = ?')
            ->execute(['noexiste.mp4', $leccionId]);

        $r = $this->controlador->video($this->peticion(), 'curso-video-sin-archivo', $leccionId);

        self::assertSame(404, $r->estado);
    }

    #[Test]
    public function verUnaLeccionDePreviewSinHaberCompradoNoRegistraProgreso(): void
    {
        $cursoId = $this->curso('curso-preview-sin-progreso');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden, vista_previa_gratis) VALUES (?, ?, ?, ?, 1)'
        )->execute([$leccionId, $moduloId, 'Lección preview', 0]);

        $this->controlador->leccion($this->peticion(), 'curso-preview-sin-progreso', $leccionId);

        $total = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_progreso')->fetchColumn();
        self::assertSame(0, $total);
    }
}
