<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Aplicacion;
use App\Core\Peticion;
use App\Excepciones\ConfiguracionFatalException;
use App\Soporte\Entorno;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Criterio de cierre de la Etapa 0: sin MASTER_KEY, la app no arranca.
 *
 * Arrancar sin ella significaría aceptar credenciales y cifrarlas con una
 * clave improvisada que nadie podrá reproducir. Es preferible el 503.
 */
#[Group('critica')]
final class ArranqueTest extends TestCase
{
    private string $raiz;

    /** @var array<string,string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->raiz = dirname(__DIR__, 2);

        foreach (['MASTER_KEY', 'PEPPER_TELEFONO'] as $clave) {
            $this->original[$clave] = getenv($clave);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $clave => $valor) {
            if ($valor === false) {
                putenv($clave);
            } else {
                putenv("{$clave}={$valor}");
            }
        }

        // Dejar el entorno vacío contaminaría a las pruebas siguientes, que
        // fallarían por eso y no por su propio defecto.
        pruebas_cargar_entorno();

        parent::tearDown();
    }

    #[Test]
    #[TestWith(['MASTER_KEY'])]
    #[TestWith(['PEPPER_TELEFONO'])]
    public function sinLaClaveLaAppNoArranca(string $clave): void
    {
        putenv($clave);
        Entorno::reiniciar();

        // Entorno::cargar leería el .env real del proyecto y volvería a
        // encontrar la clave, así que se apunta a una ruta que no existe.
        $this->expectException(ConfiguracionFatalException::class);
        new Aplicacion($this->raiz . '/no-existe-este-directorio');
    }

    #[Test]
    public function conUnaClaveDeLongitudIncorrectaTampocoArranca(): void
    {
        putenv('MASTER_KEY=' . base64_encode('demasiado corta'));
        Entorno::reiniciar();

        $this->expectException(ConfiguracionFatalException::class);
        new Aplicacion($this->raiz . '/no-existe-este-directorio');
    }

    #[Test]
    public function conLasClavesPresentesArrancaYRespondeEnLaRaiz(): void
    {
        $app = new Aplicacion($this->raiz);

        $respuesta = $app->manejar(new Peticion(metodo: 'GET', ruta: '/'));

        self::assertSame(200, $respuesta->estado);
        self::assertStringContainsString('Etapa 0', $respuesta->cuerpo);
    }

    #[Test]
    public function unaRutaDesconocidaDevuelve404(): void
    {
        $respuesta = (new Aplicacion($this->raiz))
            ->manejar(new Peticion(metodo: 'GET', ruta: '/no-existe'));

        self::assertSame(404, $respuesta->estado);
    }

    #[Test]
    public function unMetodoEquivocadoDevuelve405YNo404(): void
    {
        // Distinguirlos ahorra media hora cuando un webhook llega por GET.
        $respuesta = (new Aplicacion($this->raiz))
            ->manejar(new Peticion(metodo: 'POST', ruta: '/salud'));

        self::assertSame(405, $respuesta->estado);
    }

    #[Test]
    public function saludInformaDelEstadoDeLaBase(): void
    {
        $respuesta = (new Aplicacion($this->raiz))
            ->manejar(new Peticion(metodo: 'GET', ruta: '/salud'));

        $cuerpo = json_decode($respuesta->cuerpo, true);

        self::assertIsArray($cuerpo);
        self::assertArrayHasKey('base_datos', $cuerpo);
        // 200 con la base arriba, 503 con la base caída: nunca 200 a ciegas,
        // o el chequeo del cron no serviría de nada.
        self::assertSame($cuerpo['ok'] === true ? 200 : 503, $respuesta->estado);
    }
}
