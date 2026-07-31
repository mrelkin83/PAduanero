<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Excepciones\CifradoException;
use App\Excepciones\ConfiguracionFatalException;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('critica')]
final class CifradoTest extends TestCase
{
    private const CLAVE = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';   // 32 bytes
    private const PEPPER = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';

    private function cifrado(): Cifrado
    {
        return new Cifrado(self::CLAVE, self::PEPPER);
    }

    #[Test]
    public function cifrarYDescifrarDevuelveElOriginal(): void
    {
        $c = $this->cifrado();
        $secreto = 'prv_prod_SECRETO123';

        self::assertSame($secreto, $c->descifrar($c->cifrar($secreto)));
    }

    #[Test]
    public function elBlobRespetaElLayoutV1(): void
    {
        $blob = $this->cifrado()->cifrar('x');

        // v1 ‖ nonce(12) ‖ tag(16) ‖ ciphertext  (ADR-011)
        self::assertSame("\x01", $blob[0], 'el primer byte es la versión del layout');
        self::assertGreaterThan(1 + 12 + 16, strlen($blob));
    }

    #[Test]
    public function dosCifradosDelMismoValorSonDistintos(): void
    {
        $c = $this->cifrado();

        // Nonce aleatorio por operación: si dos cifrados del mismo texto
        // coincidieran, la base filtraría qué credenciales son iguales.
        self::assertNotSame($c->cifrar('mismo'), $c->cifrar('mismo'));
    }

    #[Test]
    public function unBlobAlteradoNoDescifra(): void
    {
        $c = $this->cifrado();
        $blob = $c->cifrar('valor original');

        // Se toca un byte del ciphertext: el tag GCM debe detectarlo.
        $blob[strlen($blob) - 1] = chr(ord($blob[strlen($blob) - 1]) ^ 0xFF);

        $this->expectException(CifradoException::class);
        $c->descifrar($blob);
    }

    #[Test]
    public function otraClaveMaestraNoDescifra(): void
    {
        $blob = $this->cifrado()->cifrar('secreto');
        $otro = new Cifrado('CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC=', self::PEPPER);

        $this->expectException(CifradoException::class);
        $otro->descifrar($blob);
    }

    #[Test]
    public function unaVersionDeLayoutDesconocidaSeRechaza(): void
    {
        $blob = $this->cifrado()->cifrar('secreto');
        $blob[0] = "\x09";

        $this->expectException(CifradoException::class);
        $this->cifrado()->descifrar($blob);
    }

    #[Test]
    public function sinClaveDe32BytesNoSeConstruye(): void
    {
        $this->expectException(ConfiguracionFatalException::class);
        new Cifrado(base64_encode('corta'), self::PEPPER);
    }

    #[Test]
    public function claveQueNoEsBase64SeRechaza(): void
    {
        $this->expectException(ConfiguracionFatalException::class);
        new Cifrado('esto no es base64 !!!', self::PEPPER);
    }

    #[Test]
    public function elHashDeTelefonoEsDeterministaYDependeDelPepper(): void
    {
        $a = new Cifrado(self::CLAVE, self::PEPPER);
        $b = new Cifrado(self::CLAVE, 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC=');

        self::assertSame($a->hashTelefono('573159923676'), $a->hashTelefono('573159923676'));
        self::assertNotSame(
            $a->hashTelefono('573159923676'),
            $b->hashTelefono('573159923676'),
            'con otro pepper el hash debe cambiar: es lo que impide la fuerza bruta',
        );
    }

    #[Test]
    public function elHashDeTelefonoNormalizaElFormato(): void
    {
        $c = $this->cifrado();

        // El mismo número llega escrito de varias formas según el canal.
        // Si no se normaliza, el mismo contacto se duplica.
        $esperado = $c->hashTelefono('573159923676');

        self::assertSame($esperado, $c->hashTelefono('+573159923676'));
        self::assertSame($esperado, $c->hashTelefono('+57 315 992 3676'));
    }

    #[Test]
    public function elHashNoEsSha256Pelado(): void
    {
        // Si alguien "simplifica" hashTelefono() a hash('sha256', $tel), esta
        // prueba lo detecta: un número de 12 dígitos se rompe por fuerza
        // bruta en segundos (ADR-012).
        self::assertNotSame(
            hash('sha256', '573159923676'),
            $this->cifrado()->hashTelefono('573159923676'),
        );
    }

    #[Test]
    public function laMascaraNoRevelaElSecreto(): void
    {
        $mascara = Cifrado::mascara('prv_prod_SECRETO123');

        self::assertStringNotContainsString('SECRETO', $mascara);
        self::assertStringEndsWith('123', $mascara);
    }

    #[Test]
    public function unSecretoCortoSeOcultaEntero(): void
    {
        // Con valores cortos, revelar el final ya es media pista.
        self::assertSame('••••••••', Cifrado::mascara('abc123'));
    }
}
