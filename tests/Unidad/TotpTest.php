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

    // ── Base32 ───────────────────────────────────────────────────────────
    //
    // Los vectores del RFC 6238 NO cubren base32: usan la semilla ASCII
    // pasada directa como clave HMAC. Pero la ruta que corre en producción
    // es la otra —el secreto llega en base32 desde la app de
    // autenticación—, así que sin estas pruebas la decodificación, que es lo
    // único que se usa de verdad, quedaría sin cubrir.
    //
    // Los valores esperados son los del RFC 4648 §10, que sí es el estándar
    // de base32.

    /** @return list<array{string,string}> */
    public static function vectoresRfc4648(): array
    {
        return [
            ['f', 'MY'],
            ['fo', 'MZXQ'],
            ['foo', 'MZXW6'],
            ['foob', 'MZXW6YQ'],
            ['fooba', 'MZXW6YTB'],
            ['foobar', 'MZXW6YTBOI'],
        ];
    }

    #[Test]
    #[DataProvider('vectoresRfc4648')]
    public function base32CoincideConLosVectoresDelRfc4648(string $claro, string $codificado): void
    {
        self::assertSame($codificado, Base32::codificar($claro));
        self::assertSame($claro, Base32::decodificar($codificado));
    }

    /** @return list<array{string,string}> Formas en que llega el mismo secreto */
    public static function formasDelMismoSecreto(): array
    {
        return [
            'canónica' => ['JBSWY3DPEHPK3PXP', 'JBSWY3DPEHPK3PXP'],
            'minúsculas' => ['jbswy3dpehpk3pxp', 'JBSWY3DPEHPK3PXP'],
            'mayúsculas y minúsculas' => ['JbSwY3dPeHpK3pXp', 'JBSWY3DPEHPK3PXP'],
            'con espacios en bloques' => ['JBSW Y3DP EHPK 3PXP', 'JBSWY3DPEHPK3PXP'],
            'con espacios irregulares' => ['JBS WY3DPE  HPK3PXP', 'JBSWY3DPEHPK3PXP'],
            'con guiones' => ['JBSW-Y3DP-EHPK-3PXP', 'JBSWY3DPEHPK3PXP'],
            'con relleno' => ['MZXW6===', 'MZXW6'],
            'relleno y minúsculas' => ['mzxw6===', 'MZXW6'],
        ];
    }

    #[Test]
    #[DataProvider('formasDelMismoSecreto')]
    public function base32AceptaComoLaGentePegaLosSecretos(string $entrada, string $canonico): void
    {
        // El panel muestra el secreto en bloques de cuatro y la gente lo pega
        // tal cual, a veces en minúsculas, a veces con el relleno de la app
        // anterior. Las tres formas tienen que dar la misma clave.
        self::assertSame(
            Base32::decodificar($canonico),
            Base32::decodificar($entrada),
            "«{$entrada}» debería decodificar igual que «{$canonico}»",
        );
    }

    #[Test]
    public function elMismoSecretoEnDistintasFormasDaElMismoCodigo(): void
    {
        // La comprobación de extremo a extremo: si la normalización fallara,
        // el usuario que pega el secreto con espacios nunca podría entrar.
        $canonico = Totp::codigo('JBSWY3DPEHPK3PXP', 1111111111);

        self::assertSame($canonico, Totp::codigo('jbsw y3dp ehpk 3pxp', 1111111111));
        self::assertSame($canonico, Totp::codigo('JBSW-Y3DP-EHPK-3PXP', 1111111111));
    }

    #[Test]
    public function base32RechazaCaracteresFueraDelAlfabeto(): void
    {
        // 0, 1 y 8 no están en el alfabeto base32 a propósito: se confunden
        // con O, I y B al teclear.
        foreach (['JBSW0PXP', 'JBSW1PXP', 'JBSW8PXP', 'ÑÑÑÑ', 'JBSW+PXP'] as $invalido) {
            try {
                Base32::decodificar($invalido);
                self::fail("«{$invalido}» debió rechazarse");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function base32IdaYVuelta(): void
    {
        foreach (['a', 'hola', self::SECRETO_SHA1, random_bytes(20)] as $original) {
            self::assertSame($original, Base32::decodificar(Base32::codificar($original)));
        }
    }

    #[Test]
    public function unSecretoIlegibleNoRevientaLaVerificacion(): void
    {
        // Si un secreto se corrompiera en base, verificar debe devolver
        // «no» y no una excepción que tumbe el formulario de acceso.
        self::assertNull(Totp::verificarConContador('esto no es base32 !!!', '123456'));
    }

    // ── Antirreplay (RFC 6238 §5.2) ──────────────────────────────────────

    #[Test]
    public function unCodigoNoSePuedeUsarDosVeces(): void
    {
        $secreto = Base32::codificar(self::SECRETO_SHA1);
        $momento = 1111111111;
        $codigo = Totp::codigo($secreto, $momento);

        $contador = Totp::verificarConContador($secreto, $codigo, null, $momento);
        self::assertIsInt($contador, 'la primera vez debe valer');

        // «The verifier MUST NOT accept the second attempt of the OTP after
        // the successful validation has been issued for the first OTP.»
        // Sin esto, un código visto por encima del hombro sirve 30 segundos.
        self::assertNull(
            Totp::verificarConContador($secreto, $codigo, $contador, $momento),
            'el mismo código no puede valer dos veces',
        );
    }

    #[Test]
    public function unCodigoAnteriorAlUltimoAceptadoSeRechaza(): void
    {
        $secreto = Base32::codificar(self::SECRETO_SHA1);
        $momento = 1111111111;
        $contadorActual = intdiv($momento, 30);

        // El código del paso anterior sigue dentro de la ventana de deriva,
        // pero es más viejo que el último aceptado: no vale.
        $anterior = Totp::codigo($secreto, $momento - 30);

        self::assertNull(Totp::verificarConContador($secreto, $anterior, $contadorActual, $momento));
    }

    #[Test]
    public function elCodigoSiguienteSiVale(): void
    {
        $secreto = Base32::codificar(self::SECRETO_SHA1);
        $momento = 1111111111;

        $primero = Totp::verificarConContador($secreto, Totp::codigo($secreto, $momento), null, $momento);

        // Treinta segundos después, con el código nuevo: entra sin problema.
        $siguiente = Totp::verificarConContador(
            $secreto,
            Totp::codigo($secreto, $momento + 30),
            $primero,
            $momento + 30,
        );

        self::assertIsInt($siguiente);
        self::assertGreaterThan((int) $primero, $siguiente);
    }

    // ── Tiempo constante ─────────────────────────────────────────────────

    #[Test]
    public function laComparacionEsEnTiempoConstante(): void
    {
        // No se puede cronometrar de forma fiable en una suite, así que se
        // comprueba la propiedad en el origen: que la comparación use
        // `hash_equals` y que no haya ninguna comparación directa del código
        // recibido. Es una guarda contra la «simplificación» futura de
        // cambiarlo por `===`, que filtraría por tiempo cuántos dígitos
        // iniciales acertó quien prueba.
        $fuente = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Soporte/Totp.php');

        // Se descarta la documentación para no medir sobre los comentarios.
        $codigo = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $fuente) ?? '';

        self::assertStringContainsString('hash_equals(', $codigo);
        self::assertDoesNotMatchRegularExpression(
            '/\$codigo\s*(===|==|!==|!=)/',
            $codigo,
            'el código recibido no puede compararse con operadores: filtra por tiempo',
        );
        self::assertDoesNotMatchRegularExpression(
            '/(===|==|!==|!=)\s*\$codigo/',
            $codigo,
        );
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
