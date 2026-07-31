<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\Base32;
use App\Soporte\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Vectores de prueba OFICIALES del RFC 6238, apéndice B.
 *
 * Son la razón por la que el TOTP va escrito a mano en vez de con una
 * librería: la correctitud no se argumenta, se demuestra contra el propio
 * estándar. Si alguien toca `Totp` y rompe el algoritmo, estos diez casos lo
 * cazan antes de que Pedro se quede sin poder entrar al panel.
 */
#[Group('critica')]
final class TotpTest extends TestCase
{
    // El RFC define el secreto en ASCII; aquí en Base32, que es como lo
    // guardamos y como lo leen las aplicaciones de autenticación.
    private const SECRETO_SHA1 = '12345678901234567890';

    /** @return list<array{int,string}> */
    public static function vectoresRfc6238(): array
    {
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
            [20000000000, '353130'],
        ];
    }

    #[Test]
    #[DataProvider('vectoresRfc6238')]
    public function coincideConLosVectoresOficialesDelRfc(int $momento, string $esperado): void
    {
        self::assertSame(
            $esperado,
            Totp::codigo(Base32::codificar(self::SECRETO_SHA1), $momento),
        );
    }

    #[Test]
    public function elCodigoSeAceptaDentroDeLaVentanaDeTolerancia(): void
    {
        $secreto = Base32::codificar(self::SECRETO_SHA1);
        $ahora = 1111111111;

        // El reloj del teléfono va desajustado, o la persona teclea justo al
        // cambiar el código. ±30 s es la tolerancia.
        self::assertTrue(Totp::verificar($secreto, '050471', $ahora));
        self::assertTrue(Totp::verificar($secreto, '050471', $ahora + 25));
        self::assertTrue(Totp::verificar($secreto, '050471', $ahora - 25));
    }

    #[Test]
    public function fueraDeLaVentanaSeRechaza(): void
    {
        $secreto = Base32::codificar(self::SECRETO_SHA1);

        // Un código de hace dos minutos ya no vale: si valiera, un código
        // leído por encima del hombro serviría demasiado tiempo.
        self::assertFalse(Totp::verificar($secreto, '050471', 1111111111 + 120));
    }

    #[Test]
    public function unCodigoMalFormadoSeRechazaSinReventar(): void
    {
        $secreto = Totp::generarSecreto();

        foreach (['', '12345', '1234567', 'abcdef', '<script>', '000 000'] as $basura) {
            self::assertFalse(Totp::verificar($secreto, $basura, 1111111111));
        }
    }

    #[Test]
    public function elSecretoGeneradoTiene160Bits(): void
    {
        // Lo que recomienda el RFC 4226 §4.
        self::assertSame(20, strlen(Base32::decodificar(Totp::generarSecreto())));
    }

    #[Test]
    public function dosSecretosNuncaCoinciden(): void
    {
        self::assertNotSame(Totp::generarSecreto(), Totp::generarSecreto());
    }

    #[Test]
    public function laUriLlevaLoQueLasAppsNecesitan(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'pedro@ejemplo.com');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=Pedro%20Abogado', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    #[Test]
    public function base32IdaYVuelta(): void
    {
        foreach (['a', 'hola', self::SECRETO_SHA1, random_bytes(20)] as $original) {
            self::assertSame($original, Base32::decodificar(Base32::codificar($original)));
        }
    }

    #[Test]
    public function unSecretoVacioSeRechaza(): void
    {
        // Deliberadamente asimétrico con codificar(''): aceptar un secreto
        // vacío sería activar 2FA sin secreto, y entonces cualquier código
        // de seis dígitos entraría. Mejor reventar.
        $this->expectException(\InvalidArgumentException::class);
        Base32::decodificar('');
    }

    #[Test]
    public function base32AceptaElFormatoQueMuestranLasApps(): void
    {
        // Las apps lo enseñan en grupos con espacios y la gente lo pega tal cual.
        self::assertSame(
            Base32::decodificar('JBSWY3DPEHPK3PXP'),
            Base32::decodificar('jbsw y3dp ehpk 3pxp'),
        );
    }
}
