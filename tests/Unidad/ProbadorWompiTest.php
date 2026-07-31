<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Servicios\Probadores\ProbadorWompi;
use App\Soporte\Http;
use App\Soporte\RespuestaHttp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El probador de Wompi, sin credenciales reales.
 *
 * Los códigos que se simulan aquí están verificados contra el sandbox público
 * de Wompi: 422 con llave inventada en `/merchants`, 401 sin token y 422 con
 * token inválido en `/transactions`, y 404 en una ruta inexistente. No son
 * códigos supuestos.
 */
#[Group('critica')]
final class ProbadorWompiTest extends TestCase
{
    private const PUB_PROD = 'pub_prod_Kw4aC0rZVgLZQn209NbEKPuXLzBD28Zx';
    private const PRV_PROD = 'prv_prod_434092Xa65F54dd6a181D1f87DFa03CzS';
    private const PUB_TEST = 'pub_test_Kw4aC0rZVgLZQn209NbEKPuXLzBD28Zx';
    private const PRV_TEST = 'prv_test_434092Xa65F54dd6a181D1f87DFa03CzS';

    /** @param list<RespuestaHttp> $respuestas en orden de llamada */
    private function http(array $respuestas): Http
    {
        return new class ($respuestas) extends Http {
            /** @param list<RespuestaHttp> $respuestas */
            public function __construct(private array $respuestas)
            {
                parent::__construct();
            }

            public function pedir(string $metodo, string $url, array $cabeceras = [], ?array $json = null): RespuestaHttp
            {
                return array_shift($this->respuestas)
                    ?? new RespuestaHttp(500, '', 'sin respuesta preparada', 0);
            }
        };
    }

    private function respuesta(int $estado, string $cuerpo = '{}'): RespuestaHttp
    {
        return new RespuestaHttp($estado, $cuerpo, null, 40);
    }

    #[Test]
    public function conAmbasLlavesValidasDaVerde(): void
    {
        $probador = new ProbadorWompi($this->http([
            $this->respuesta(200, '{"data":{"name":"Despacho Pedro","accepted_payment_methods":["PSE","CARD"]}}'),
            $this->respuesta(200, '{"data":[]}'),
        ]));

        $r = $probador->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertTrue($r['ok']);
        self::assertStringContainsString('Despacho Pedro', $r['mensaje']);
        self::assertSame(['PSE', 'CARD'], $r['detalle']['metodos_aceptados']);
    }

    #[Test]
    public function detectaLlavesDePruebasEnEntornoDeProduccion(): void
    {
        // Es la causa más común de «el webhook no valida la firma»
        // (RUNBOOK §3.3). Se caza SIN salir a red: si el probador hiciera la
        // petición, Wompi devolvería un 422 genérico y el mensaje no diría
        // qué arreglar.
        $probador = new ProbadorWompi($this->http([]));

        $r = $probador->probar(
            ['llave_publica' => self::PUB_TEST, 'llave_privada' => self::PRV_TEST],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('pub_prod_', $r['mensaje']);
        self::assertStringContainsString('pruebas', $r['mensaje']);
    }

    #[Test]
    public function detectaLlavesDeProduccionEnSandbox(): void
    {
        $r = (new ProbadorWompi($this->http([])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'pruebas',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('pub_test_', $r['mensaje']);
    }

    #[Test]
    public function unaLlavePublicaDesconocidaDa422YSeExplica(): void
    {
        $r = (new ProbadorWompi($this->http([$this->respuesta(422)])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('llave pública', $r['mensaje']);
    }

    #[Test]
    public function laPrivadaSeCompruebaAparteDeLaPublica(): void
    {
        // La llave pública es, por definición, pública: que valide no dice
        // nada sobre si tenemos permiso de cobrar.
        $r = (new ProbadorWompi($this->http([
            $this->respuesta(200, '{"data":{"name":"Despacho"}}'),
            $this->respuesta(422),
        ])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('rechaza la privada', $r['mensaje']);
    }

    #[Test]
    public function sinRedLoDiceEnVezDeCulparALasCredenciales(): void
    {
        $probador = new ProbadorWompi($this->http([
            new RespuestaHttp(0, '', 'Could not resolve host: production.wompi.co', 0),
        ]));

        $r = $probador->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('contactar con Wompi', $r['mensaje']);
    }

    #[Test]
    public function un404AvisaDeQueCambioLaApi(): void
    {
        $r = (new ProbadorWompi($this->http([$this->respuesta(404)])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('cambió la ruta', $r['mensaje']);
    }

    #[Test]
    public function unaLlaveVaciaSeDetectaAntesDeSalirARed(): void
    {
        $r = (new ProbadorWompi($this->http([])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => ''],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Falta la llave privada', $r['mensaje']);
    }

    #[Test]
    public function unEntornoDesconocidoSeRechaza(): void
    {
        $r = (new ProbadorWompi($this->http([])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'certificacion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Entorno desconocido', $r['mensaje']);
    }

    #[Test]
    public function un500DeWompiSeDistingueDeUnaCredencialMala(): void
    {
        // Que la pasarela esté caída no es lo mismo que tener mal las llaves,
        // y el mensaje no debe mandar a nadie a revisar credenciales que
        // están bien.
        $r = (new ProbadorWompi($this->http([$this->respuesta(500)])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('HTTP 500', $r['mensaje']);
        self::assertStringContainsString('pública', $r['mensaje']);
    }

    #[Test]
    public function un500AlValidarLaPrivadaTambienSeDistingue(): void
    {
        $r = (new ProbadorWompi($this->http([
            $this->respuesta(200, '{"data":{"name":"Despacho"}}'),
            $this->respuesta(503),
        ])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('HTTP 503', $r['mensaje']);
        self::assertStringContainsString('privada', $r['mensaje']);
    }

    #[Test]
    public function laRedCaidaAlValidarLaPrivadaSeDistingue(): void
    {
        $r = (new ProbadorWompi($this->http([
            $this->respuesta(200, '{"data":{"name":"Despacho"}}'),
            new RespuestaHttp(0, '', 'Operation timed out', 0),
        ])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertFalse($r['ok']);
        self::assertStringContainsString('se perdió la conexión', $r['mensaje']);
    }

    #[Test]
    public function sinNombreDeComercioElMensajeSigueSiendoUtil(): void
    {
        $r = (new ProbadorWompi($this->http([
            $this->respuesta(200, '{"data":{}}'),
            $this->respuesta(200, '{"data":[]}'),
        ])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        self::assertTrue($r['ok']);
        self::assertStringContainsString('ambas llaves validan', $r['mensaje']);
    }

    #[Test]
    public function declaraQueServicioAtiendeYQueClavesNecesita(): void
    {
        $probador = new ProbadorWompi($this->http([]));

        self::assertSame('wompi', $probador->servicio());
        self::assertSame(['llave_publica', 'llave_privada'], $probador->clavesRequeridas());
    }

    #[Test]
    public function elResultadoNuncaLlevaLaCredencialDentro(): void
    {
        // Este resultado se serializa hacia el navegador.
        $r = (new ProbadorWompi($this->http([
            $this->respuesta(200, '{"data":{"name":"Despacho"}}'),
            $this->respuesta(200, '{"data":[]}'),
        ])))->probar(
            ['llave_publica' => self::PUB_PROD, 'llave_privada' => self::PRV_PROD],
            'produccion',
        );

        $json = json_encode($r);

        self::assertIsString($json);
        self::assertStringNotContainsString(self::PRV_PROD, $json);
        self::assertStringNotContainsString('434092', $json);
    }
}
