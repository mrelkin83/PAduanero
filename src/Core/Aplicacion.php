<?php

declare(strict_types=1);

namespace App\Core;

use App\Excepciones\ConfiguracionFatalException;
use App\Servicios\Config;
use App\Servicios\ConfigMysql;
use App\Servicios\Credenciales;
use App\Servicios\CredencialesAes;
use App\Soporte\Cifrado;
use App\Soporte\Entorno;
use App\Soporte\Fechas;
use App\Soporte\Logger;

/**
 * Arranque de la aplicación.
 *
 * El constructor valida el entorno y falla si falta algo esencial. Es
 * deliberado y está probado: sin `MASTER_KEY` la aplicación NO arranca
 * (docs/PLAN_BUILD.md, criterio de cierre de la Etapa 0). Arrancar sin ella
 * significaría escribir credenciales que nadie podrá volver a descifrar.
 */
final class Aplicacion
{
    public readonly Contenedor $contenedor;
    private readonly Router $router;

    public function __construct(private readonly string $raiz)
    {
        Entorno::cargar($this->raiz . '/.env');

        date_default_timezone_set(Fechas::ZONA);

        $this->verificarEntorno();

        $this->contenedor = new Contenedor();
        $this->registrarServicios();

        $this->router = new Router();
        $this->registrarRutas();
    }

    /**
     * Lo que se comprueba aquí es lo que, de faltar, produce corrupción
     * silenciosa más adelante en vez de un error claro ahora.
     */
    private function verificarEntorno(): void
    {
        // Construir el Cifrado valida de una vez que MASTER_KEY y
        // PEPPER_TELEFONO existan, sean base64 y midan 32 bytes.
        Cifrado::desdeEntorno();

        Entorno::exigir('DB_NAME');
        Entorno::exigir('DB_USER');

        if (!extension_loaded('pdo_mysql')) {
            throw new ConfiguracionFatalException('Falta la extensión pdo_mysql.');
        }

        if (!extension_loaded('openssl')) {
            throw new ConfiguracionFatalException('Falta la extensión openssl.');
        }
    }

    private function registrarServicios(): void
    {
        $raiz = $this->raiz;

        $this->contenedor->registrar(BD::class, static fn (): BD => BD::desdeEntorno());

        $this->contenedor->registrar(Cifrado::class, static fn (): Cifrado => Cifrado::desdeEntorno());

        $this->contenedor->registrar(Logger::class, static fn (): Logger => Logger::desdeEntorno());

        $this->contenedor->registrar(
            Config::class,
            static fn (Contenedor $c): Config => new ConfigMysql(
                $c->obtener(BD::class),
                $raiz . '/storage/config.sentinel',
                $raiz . '/storage/cache/config.json',
            ),
        );

        $this->contenedor->registrar(
            Credenciales::class,
            static fn (Contenedor $c): Credenciales => new CredencialesAes(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
                $c->obtener(Logger::class),
            ),
        );
    }

    /**
     * Etapa 0: solo lo que hace falta para verificar que el andamio está en
     * pie. La landing llega en la Etapa 1 y el panel en la 3.
     */
    private function registrarRutas(): void
    {
        $this->router->get('/', function (): Respuesta {
            return Respuesta::vista('bienvenida', [
                'entorno' => Entorno::obtener('APP_ENV', 'produccion'),
            ]);
        });

        $this->router->get('/salud', function (): Respuesta {
            // La consulta el cron cada 10 minutos (bin/salud.sh) y el
            // despliegue al terminar. Comprueba de verdad la base: responder
            // 200 con MySQL caído haría inútil el chequeo.
            try {
                $this->contenedor->obtener(BD::class)->pdo()->query('SELECT 1');
                $bd = true;
            } catch (\Throwable) {
                $bd = false;
            }

            return Respuesta::json([
                'ok' => $bd,
                'base_datos' => $bd ? 'arriba' : 'caida',
                'momento' => Fechas::ahora()->format('c'),
            ], $bd ? 200 : 503);
        });
    }

    public function manejar(Peticion $peticion): Respuesta
    {
        try {
            return $this->router->despachar($peticion);
        } catch (\Throwable $e) {
            $this->contenedor->obtener(Logger::class)->error('http.error_no_controlado', [
                'ruta' => $peticion->ruta,
                'metodo' => $peticion->metodo,
                'excepcion' => $e::class,
                'detalle' => $e->getMessage(),
            ]);

            // Nunca el mensaje de la excepción al cliente: trae rutas,
            // consultas SQL y a veces el DSN.
            return Respuesta::json(['error' => 'Error interno.'], 500);
        }
    }

    public function ejecutar(): void
    {
        $this->manejar(Peticion::desdeGlobales())->enviar();
    }
}
