<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class AutenticacionCompradorTest extends CasoBaseBd
{
    private AutenticacionComprador $auth;
    private CompradorRepo $compradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->auth = new AutenticacionComprador(
            $this->compradores,
            new CompradorSesionRepo($this->bd),
            new IntentoAccesoRepo($this->bd),
        );
    }

    private function crearComprador(string $correo, string $password): void
    {
        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', $correo, $password);
    }

    #[Test]
    public function credencialesCorrectasDevuelvenElComprador(): void
    {
        $this->crearComprador('ok@ejemplo.com', 'claveSegura123');

        $r = $this->auth->verificarCredenciales('ok@ejemplo.com', 'claveSegura123', '190.85.1.1');

        self::assertTrue($r['ok']);
        self::assertSame('ok@ejemplo.com', $r['comprador']->correo);
    }

    #[Test]
    public function claveIncorrectaFallaConMensajeGenerico(): void
    {
        $this->crearComprador('mal@ejemplo.com', 'claveSegura123');

        $r = $this->auth->verificarCredenciales('mal@ejemplo.com', 'claveEquivocada', '190.85.1.1');

        self::assertFalse($r['ok']);
        self::assertSame('Credenciales incorrectas.', $r['motivo']);
    }

    #[Test]
    public function unCorreoQueNoExisteFallaConElMismoMensajeGenerico(): void
    {
        // Mismo mensaje que clave incorrecta: no se puede distinguir "no
        // existe" de "existe con clave mala" desde afuera.
        $r = $this->auth->verificarCredenciales('nadie@ejemplo.com', 'cualquiera', '190.85.1.1');

        self::assertFalse($r['ok']);
        self::assertSame('Credenciales incorrectas.', $r['motivo']);
    }

    #[Test]
    public function abrirYConsultarUnaSesion(): void
    {
        $this->crearComprador('sesion@ejemplo.com', 'clave123');
        $comprador = $this->compradores->porCorreo('sesion@ejemplo.com');

        $token = $this->auth->abrirSesion($comprador, '190.85.1.1', 'PHPUnit');

        $recuperado = $this->auth->compradorDeSesion($token);
        self::assertNotNull($recuperado);
        self::assertSame($comprador->id, $recuperado->id);
    }

    #[Test]
    public function cerrarSesionInvalidaElToken(): void
    {
        $this->crearComprador('salir@ejemplo.com', 'clave123');
        $comprador = $this->compradores->porCorreo('salir@ejemplo.com');
        $token = $this->auth->abrirSesion($comprador, null, null);

        $this->auth->cerrarSesion($token);

        self::assertNull($this->auth->compradorDeSesion($token));
    }

    #[Test]
    public function demasiadosIntentosDesdeUnaIpBloqueaTemporalmente(): void
    {
        $this->crearComprador('rate@ejemplo.com', 'claveBuena');

        for ($i = 0; $i < 20; $i++) {
            $this->auth->verificarCredenciales('rate@ejemplo.com', 'claveMala', '190.85.9.9');
        }

        $r = $this->auth->verificarCredenciales('rate@ejemplo.com', 'claveBuena', '190.85.9.9');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Demasiados intentos', $r['motivo']);
    }
}
