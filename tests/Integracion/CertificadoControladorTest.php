<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\AccesoControlador;
use App\Cuenta\CertificadoControlador;
use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Servicios\ConfigMysql;
use App\Servicios\Cursos;
use App\Soporte\BunnyStream;
use App\Soporte\Cifrado;
use App\Cuenta\CertificadoPdf;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CertificadoControladorTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private CertificadoControlador $controlador;
    private CompradorRepo $compradores;
    private CompraCursoRepo $compras;
    private CertificadoRepo $certificados;
    private AutenticacionComprador $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->compras = new CompraCursoRepo($this->bd);
        $this->certificados = new CertificadoRepo($this->bd);
        $sesiones = new CompradorSesionRepo($this->bd);
        $this->auth = new AutenticacionComprador($this->compradores, $sesiones, new IntentoAccesoRepo($this->bd));

        $sufijo = bin2hex(random_bytes(4));
        $config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-cert-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-cert-cfg-{$sufijo}.json",
        );
        $cursos = new Cursos($this->bd, $config, self::URL, new BunnyStream('', ''));

        $this->controlador = new CertificadoControlador(
            $this->auth,
            $cursos,
            $this->compras,
            $this->certificados,
            new CertificadoPdf($this->compradores),
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
        )->execute([$id, $catId, 'Curso certificado descarga', $slug, 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    private function peticion(): Peticion
    {
        return new Peticion(metodo: 'GET', ruta: '/mis-cursos/x/certificado');
    }

    #[Test]
    public function sinSesionRedirigeAEntrar(): void
    {
        $r = $this->controlador->descargar($this->peticion(), 'cualquier-curso');

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function conSesionPeroSinCertificadoRedirigeAlAula(): void
    {
        $cursoId = $this->curso('curso-sin-certificado');
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-desc@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-desc@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->descargar($this->peticion(), 'curso-sin-certificado');

        self::assertSame(302, $r->estado);
        self::assertSame('/mis-cursos/curso-sin-certificado', $r->cabeceras['Location']);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function conCertificadoDevuelveElPdf(): void
    {
        $cursoId = $this->curso('curso-con-certificado');
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-desc2@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-desc2@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);
        $this->certificados->crear($compraId, 'PA-TESTCERT');

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->descargar($this->peticion(), 'curso-con-certificado');

        self::assertSame(200, $r->estado);
        self::assertSame('application/pdf', $r->cabeceras['Content-Type']);
        self::assertStringStartsWith('%PDF-', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function verificarConUnCodigoRealMuestraLosDatos(): void
    {
        $cursoId = $this->curso('curso-verificar-real');
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-verif@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-verif@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);
        $this->certificados->crear($compraId, 'PA-VERIFICA1');

        $r = $this->controlador->verificarBuscar(new Peticion(metodo: 'GET', ruta: '/certificados/verificar/PA-VERIFICA1'), 'PA-VERIFICA1');

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('Ana', $r->cuerpo);
        self::assertStringContainsString('Gómez', $r->cuerpo);
        self::assertStringContainsString('Curso certificado descarga', $r->cuerpo);
        self::assertStringNotContainsString('1010101010', $r->cuerpo);
    }

    #[Test]
    public function verificarConUnCodigoInventadoDaElMismoMensajeNeutral(): void
    {
        $r1 = $this->controlador->verificarBuscar(new Peticion(metodo: 'GET', ruta: '/certificados/verificar/PA-NOEXISTE'), 'PA-NOEXISTE');
        $r2 = $this->controlador->verificarBuscar(new Peticion(metodo: 'GET', ruta: '/certificados/verificar/PA-OTROMAS'), 'PA-OTROMAS');

        self::assertSame($r1->estado, $r2->estado);
        self::assertSame($r1->cuerpo, $r2->cuerpo);
    }
}
