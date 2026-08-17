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

        foreach (['MASTER_KEY'] as $clave) {
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

        // Esta prueba mira el arranque, no la landing: afirmar sobre textos
        // concretos la ataría al contenido editable desde el panel y la haría
        // fallar cada vez que Pedro cambie un titular.
        self::assertSame(200, $respuesta->estado);
        self::assertStringStartsWith('<!doctype html>', trim($respuesta->cuerpo));
        self::assertStringContainsString('lang="es-CO"', $respuesta->cuerpo);
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

    #[Test]
    public function unEnvConBomNoPierdeLaPrimeraVariable(): void
    {
        // En Windows, guardar el .env con Notepad o escribirlo con `Out-File`
        // deja EF BB BF al principio. Sin quitarlo, la clave de la primera
        // línea pasa a llamarse "\u{FEFF}APP_ENV" y **la primera variable
        // desaparece en silencio**.
        //
        // El peor caso es el más probable: si la primera línea es `APP_ENV`,
        // se asume `produccion`, las cookies de sesión se marcan `Secure`, el
        // navegador las descarta sobre http:// y uno entra al panel y vuelve
        // al formulario sin ningún mensaje de error. Pasó en el arranque
        // local del 2026-08-01.
        $ruta = sys_get_temp_dir() . '/padu-env-bom-' . bin2hex(random_bytes(4));

        // La primera clave es sintética a propósito: `Entorno::obtener()` da
        // precedencia al entorno real del proceso, y `APP_ENV` llega ahí desde
        // `phpunit.xml`. Con esa no se vería si el BOM la rompió.
        file_put_contents($ruta, "\xEF\xBB\xBFPRIMERA_DEL_ARCHIVO=desarrollo\nSEGUNDA=ok\n");

        try {
            Entorno::reiniciar();
            Entorno::cargar($ruta);

            self::assertSame('desarrollo', Entorno::obtener('PRIMERA_DEL_ARCHIVO'));
            self::assertSame('ok', Entorno::obtener('SEGUNDA'));
        } finally {
            @unlink($ruta);
            pruebas_cargar_entorno();
        }
    }
}
