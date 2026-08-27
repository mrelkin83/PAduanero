<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\ComprasControlador;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\ConfigMysql;
use App\Servicios\Cursos;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;
use Pruebas\Dobles\PaymentAdapterFalso;

final class ComprasControladorTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private Cursos $cursosServicio;
    private CompraCursoRepo $compras;
    private PaymentAdapterFalso $wompi;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-compras-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-compras-cfg-{$sufijo}.json",
        );
        $this->cursosServicio = new Cursos($this->bd, $config, self::URL);
        $this->compras = new CompraCursoRepo($this->bd);
        $this->wompi = new PaymentAdapterFalso();
    }

    private function controlador(?\ElkinLinan\WhatsappAiEngine\Payments\PaymentAdapterInterface $wompi = null): ComprasControlador
    {
        // OJO: `?? $this->wompi` no serviría aquí — un `null` explícito y la
        // ausencia del argumento son indistinguibles para `??`, así que la
        // prueba de "Wompi no configurado" nunca conseguiría un null real.
        // `func_num_args()` sí distingue "no me pasaron nada" de "me
        // pasaron null a propósito".
        $usado = func_num_args() === 0 ? $this->wompi : $wompi;

        return new ComprasControlador($this->cursosServicio, $this->compras, $usado, self::URL);
    }

    private function crearCursoPublicado(int $precio = 250000): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);

        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, 'Curso comprable', 'curso-comprable', 'r', 'd', '[]', $precio, 'publicado']);

        return $id;
    }

    private function peticion(string $ruta, array $formulario = [], array $consulta = []): Peticion
    {
        return new Peticion(
            metodo: $formulario === [] ? 'GET' : 'POST',
            ruta: $ruta,
            consulta: $consulta,
            formulario: $formulario,
        );
    }

    #[Test]
    public function unCursoQueNoExisteResponde404EnElFormulario(): void
    {
        $r = $this->controlador()->formulario($this->peticion('/cursos/no-existe/comprar'), 'no-existe');

        self::assertSame(404, $r->estado);
    }

    #[Test]
    public function procesarSinNombreNiCorreoRedirigeConError(): void
    {
        $this->crearCursoPublicado();

        $r = $this->controlador()->procesar(
            $this->peticion('/cursos/curso-comprable/comprar', ['nombre' => '', 'correo' => 'no-es-correo']),
            'curso-comprable',
        );

        self::assertSame(302, $r->estado);
        self::assertStringContainsString('/cursos/curso-comprable/comprar', $r->cabeceras['Location']);
        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM compras_curso')->fetchColumn());
    }

    #[Test]
    public function procesarConDatosValidosCreaLaCompraYRedirigeAWompi(): void
    {
        $this->crearCursoPublicado(250000);

        $r = $this->controlador()->procesar(
            $this->peticion('/cursos/curso-comprable/comprar', ['nombre' => 'Ana Gómez', 'correo' => 'ana@ejemplo.com']),
            'curso-comprable',
        );

        self::assertSame(302, $r->estado);
        self::assertSame('https://checkout.wompi.co/l/falso123', $r->cabeceras['Location']);

        $compra = $this->bd->pdo()->query('SELECT * FROM compras_curso')->fetch();
        self::assertSame('pendiente', $compra['estado']);
        self::assertSame(250000, (int) $compra['precio_cop']);
        self::assertSame('wompi_ref_falsa', $compra['referencia_wompi']);

        // El precio se manda en PESOS, nunca en centavos (ADR-010) — la
        // conversión a centavos vive solo dentro de WompiAdapter.
        self::assertSame(250000.0, $this->wompi->llamadasCrearCobro[0]['monto']);
        self::assertStringContainsString('/cursos/curso-comprable/gracias', $this->wompi->llamadasCrearCobro[0]['redirectUrl']);
    }

    #[Test]
    public function siWompiNoEstaConfiguradoLaCompraQuedaFallidaConError(): void
    {
        $this->crearCursoPublicado();

        $r = $this->controlador(null)->procesar(
            $this->peticion('/cursos/curso-comprable/comprar', ['nombre' => 'Ana', 'correo' => 'ana@ejemplo.com']),
            'curso-comprable',
        );

        self::assertSame(302, $r->estado);
        self::assertSame('fallida', $this->bd->pdo()->query('SELECT estado FROM compras_curso')->fetchColumn());
    }

    #[Test]
    public function siWompiRechazaCrearElCobroLaCompraQuedaFallida(): void
    {
        $this->crearCursoPublicado();
        $this->wompi->respuestaCrearCobro = ['ok' => false, 'enlace' => '', 'referencia' => '', 'estado' => 'ERROR', 'error' => 'boom'];

        $this->controlador()->procesar(
            $this->peticion('/cursos/curso-comprable/comprar', ['nombre' => 'Ana', 'correo' => 'ana@ejemplo.com']),
            'curso-comprable',
        );

        self::assertSame('fallida', $this->bd->pdo()->query('SELECT estado FROM compras_curso')->fetchColumn());
    }

    #[Test]
    public function unCursoEnBorradorNoSePuedeComprar(): void
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$catId, 'Sin publicar', 'sin-publicar', 'r', 'd', '[]', 100000, 'borrador']);

        $r = $this->controlador()->formulario($this->peticion('/cursos/sin-publicar/comprar'), 'sin-publicar');

        self::assertSame(404, $r->estado);
    }

    #[Test]
    public function graciasConsultaElEstadoDeUnaCompraPendienteSoloParaMostrarlo(): void
    {
        $cursoId = $this->crearCursoPublicado();
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->compras->guardarReferencia($compraId, 'ref-1', 'ext-1');
        $this->wompi->respuestaConsultar = ['ok' => true, 'estado' => 'PAYMENT_INITIATED', 'monto' => 0.0, 'transaccion_id' => '', 'metodo' => '', 'error' => ''];

        $r = $this->controlador()->gracias(
            $this->peticion('/cursos/curso-comprable/gracias', [], ['compra' => $compraId]),
            'curso-comprable',
        );

        self::assertSame(200, $r->estado);
        // El estado que se ve viene de consultar(), pero la fila en base
        // sigue en 'pendiente' — la consulta es solo informativa, nunca
        // cambia el estado guardado.
        self::assertSame('pendiente', $this->compras->porId($compraId)['estado']);
    }

    #[Test]
    public function graciasConUnPagoRechazadoMuestraElMensajeDeNoCompletado(): void
    {
        $cursoId = $this->crearCursoPublicado();
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->compras->guardarReferencia($compraId, 'ref-1', 'ext-1');
        $this->wompi->respuestaConsultar = ['ok' => true, 'estado' => 'PAYMENT_REJECTED', 'monto' => 0.0, 'transaccion_id' => '', 'metodo' => '', 'error' => ''];

        $r = $this->controlador()->gracias(
            $this->peticion('/cursos/curso-comprable/gracias', [], ['compra' => $compraId]),
            'curso-comprable',
        );

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('no se completó', $r->cuerpo);
    }
}
