<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\AccesoControlador;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorEnlaceRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class AccesoControladorTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private AccesoControlador $controlador;
    private CompradorRepo $compradores;
    private CompradorEnlaceRepo $enlaces;
    private CompraCursoRepo $compras;
    private CompradorSesionRepo $sesiones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->sesiones = new CompradorSesionRepo($this->bd);
        $this->enlaces = new CompradorEnlaceRepo($this->bd);
        $this->compras = new CompraCursoRepo($this->bd);

        $this->controlador = new AccesoControlador(
            new AutenticacionComprador($this->compradores, $this->sesiones, new IntentoAccesoRepo($this->bd)),
            $this->compradores,
            $this->sesiones,
            $this->enlaces,
            $this->compras,
            null, // Smtp
            self::URL,
        );
    }

    private function cursoYCompra(string $correo): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso acceso', 'curso-acceso', 'r', 'd', '[]', 250000, 'publicado']);

        return $this->compras->crear($cursoId, 'Ana Gómez', $correo, 250000);
    }

    private function peticion(string $ruta, array $formulario = [], array $consulta = []): Peticion
    {
        return new Peticion(metodo: $formulario === [] ? 'GET' : 'POST', ruta: $ruta, consulta: $consulta, formulario: $formulario, ip: '190.85.1.1');
    }

    #[Test]
    public function unTokenInvalidoMuestraElEnlaceInvalido(): void
    {
        $r = $this->controlador->completarMostrar($this->peticion('/mis-cursos/completar', [], ['token' => 'no-existe']));

        self::assertSame(410, $r->estado);
    }

    #[Test]
    public function completarRegistroConCorreoNuevoCreaLaCuentaYVinculaLaCompra(): void
    {
        $compraId = $this->cursoYCompra('nueva@ejemplo.com');
        $token = $this->enlaces->crear('completar_registro', null, $compraId, 60);

        $r = $this->controlador->completarProcesar($this->peticion('/mis-cursos/completar', [
            'token' => $token, 'modo' => 'registro',
            'nombres' => 'Ana', 'apellidos' => 'Gómez', 'tipo_documento' => 'CC',
            'numero_documento' => '1010101010', 'celular' => '3001234567', 'password' => 'claveSegura123',
        ]));

        self::assertSame(302, $r->estado);
        self::assertSame('/mis-cursos', $r->cabeceras['Location']);

        $comprador = $this->compradores->porCorreo('nueva@ejemplo.com');
        self::assertNotNull($comprador);
        self::assertSame($comprador->id, $this->compras->porId($compraId)['comprador_id']);
    }

    #[Test]
    public function elEnlaceDeRegistroQuedaMarcadoUsadoTrasCompletarlo(): void
    {
        $compraId = $this->cursoYCompra('usado@ejemplo.com');
        $token = $this->enlaces->crear('completar_registro', null, $compraId, 60);

        $this->controlador->completarProcesar($this->peticion('/mis-cursos/completar', [
            'token' => $token, 'modo' => 'registro',
            'nombres' => 'Ana', 'apellidos' => 'Gómez', 'tipo_documento' => 'CC',
            'numero_documento' => '1010101010', 'celular' => '3001234567', 'password' => 'claveSegura123',
        ]));

        self::assertNull($this->enlaces->vigente($token, 'completar_registro'));
    }

    #[Test]
    public function completarConCorreoQueYaTieneCuentaVinculaPorLogin(): void
    {
        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'existente@ejemplo.com', 'claveVieja123');
        $compraId = $this->cursoYCompra('existente@ejemplo.com');
        $token = $this->enlaces->crear('completar_registro', null, $compraId, 60);

        $r = $this->controlador->completarProcesar($this->peticion('/mis-cursos/completar', [
            'token' => $token, 'modo' => 'login', 'password' => 'claveVieja123',
        ]));

        self::assertSame(302, $r->estado);
        $comprador = $this->compradores->porCorreo('existente@ejemplo.com');
        self::assertSame($comprador->id, $this->compras->porId($compraId)['comprador_id']);
    }

    #[Test]
    public function completarConClaveEquivocadaEnModoLoginNoVinculaNada(): void
    {
        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'existente2@ejemplo.com', 'claveVieja123');
        $compraId = $this->cursoYCompra('existente2@ejemplo.com');
        $token = $this->enlaces->crear('completar_registro', null, $compraId, 60);

        $this->controlador->completarProcesar($this->peticion('/mis-cursos/completar', [
            'token' => $token, 'modo' => 'login', 'password' => 'claveEquivocada',
        ]));

        self::assertNull($this->compras->porId($compraId)['comprador_id']);
    }

    #[Test]
    public function entrarConCredencialesCorrectasAbreSesion(): void
    {
        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'login@ejemplo.com', 'clave123');

        $r = $this->controlador->entrarProcesar($this->peticion('/entrar', [
            'correo' => 'login@ejemplo.com', 'password' => 'clave123',
        ]));

        self::assertSame(302, $r->estado);
        self::assertSame('/mis-cursos', $r->cabeceras['Location']);
    }

    #[Test]
    public function recuperarSiempreMuestraElMismoMensajeExistaOnoLaCuenta(): void
    {
        $r1 = $this->controlador->recuperarProcesar($this->peticion('/recuperar', ['correo' => 'no-existe@ejemplo.com']));

        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'si-existe@ejemplo.com', 'clave123');
        $r2 = $this->controlador->recuperarProcesar($this->peticion('/recuperar', ['correo' => 'si-existe@ejemplo.com']));

        self::assertSame($r1->estado, $r2->estado);
        self::assertSame($r1->cuerpo, $r2->cuerpo);
    }

    #[Test]
    public function recuperarConCorreoExistenteCreaUnEnlaceDeReset(): void
    {
        $id = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'reset@ejemplo.com', 'claveVieja');

        $this->controlador->recuperarProcesar($this->peticion('/recuperar', ['correo' => 'reset@ejemplo.com']));

        $fila = $this->bd->pdo()->prepare("SELECT COUNT(*) FROM compradores_enlaces WHERE comprador_id = ? AND tipo = 'reset_password'");
        $fila->execute([$id]);
        self::assertSame(1, (int) $fila->fetchColumn());
    }

    #[Test]
    public function confirmarResetCambiaLaClaveYRevocaLasSesionesVivas(): void
    {
        $id = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'reset2@ejemplo.com', 'claveVieja');
        $comprador = $this->compradores->porId($id);
        $tokenSesion = $this->sesiones->crear($id, 60, null, null);
        $tokenReset = $this->enlaces->crear('reset_password', $id, null, 60);

        $r = $this->controlador->recuperarConfirmarProcesar($this->peticion('/recuperar/confirmar', [
            'token' => $tokenReset, 'password' => 'claveNueva123',
        ]));

        self::assertSame(302, $r->estado);
        self::assertTrue($this->compradores->verificarPassword('reset2@ejemplo.com', 'claveNueva123'));
        self::assertFalse($this->compradores->verificarPassword('reset2@ejemplo.com', 'claveVieja'));
        self::assertNull($this->sesiones->vigente($tokenSesion));
        self::assertNull($this->enlaces->vigente($tokenReset, 'reset_password'));
    }
}
