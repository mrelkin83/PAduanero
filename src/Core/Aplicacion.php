<?php

declare(strict_types=1);

namespace App\Core;

use App\Excepciones\ConfiguracionFatalException;
use App\Servicios\Config;
use App\Servicios\ConfigMysql;
use App\Servicios\Credenciales;
use App\Servicios\CredencialesAes;
use App\Servicios\Landing;
use App\Servicios\MetricasLanding;
use App\Servicios\Seo;
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
            \App\Soporte\Http::class,
            static fn (): \App\Soporte\Http => new \App\Soporte\Http(),
        );

        $this->contenedor->registrar(
            Credenciales::class,
            static fn (Contenedor $c): Credenciales => new CredencialesAes(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
                $c->obtener(Logger::class),
                [
                    // Los probadores llegan con su integración: Wompi en la
                    // Etapa 3, Chatwoot y Evolution en la 2, el LLM en la 4.
                    new \App\Servicios\Probadores\ProbadorWompi($c->obtener(\App\Soporte\Http::class)),
                ],
            ),
        );

        // Catálogo de modelos: descubrimiento automático, adopción manual.
        // Los descubridores se enumeran aquí y no se autodetectan: la lista
        // de proveedores a los que este sistema le pide su catálogo es una
        // decisión, y tiene que poder leerse de un vistazo.
        $this->contenedor->registrar(
            \App\Servicios\CatalogoModelos::class,
            static fn (Contenedor $c): \App\Servicios\CatalogoModelos => new \App\Servicios\CatalogoModelos(
                $c->obtener(BD::class),
                $c->obtener(Credenciales::class),
                $c->obtener(Logger::class),
                [
                    new \App\Servicios\Descubridores\DescubridorAnthropic(
                        $c->obtener(\App\Soporte\Http::class),
                    ),
                    new \App\Servicios\Descubridores\DescubridorOpenAiCompatible(
                        $c->obtener(\App\Soporte\Http::class),
                    ),
                    new \App\Servicios\Descubridores\DescubridorOllama(
                        $c->obtener(\App\Soporte\Http::class),
                    ),
                ],
            ),
        );

        // ── Motor (Etapa 4) ──────────────────────────────────────────────
        $this->contenedor->registrar(
            \App\Repositorios\ContactoRepo::class,
            static fn (Contenedor $c): \App\Repositorios\ContactoRepo => new \App\Repositorios\ContactoRepo(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
                $c->obtener(\App\Repositorios\AuditoriaRepo::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\ConsentimientoRepo::class,
            static fn (Contenedor $c): \App\Repositorios\ConsentimientoRepo
                => new \App\Repositorios\ConsentimientoRepo($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CasoRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CasoRepo
                => new \App\Repositorios\CasoRepo($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Repositorios\ConversacionEstadoRepo::class,
            static fn (Contenedor $c): \App\Repositorios\ConversacionEstadoRepo
                => new \App\Repositorios\ConversacionEstadoRepo($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Repositorios\ConsultaRepo::class,
            static fn (Contenedor $c): \App\Repositorios\ConsultaRepo
                => new \App\Repositorios\ConsultaRepo($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Servicios\Chatwoot::class,
            static fn (Contenedor $c): \App\Servicios\Chatwoot => new \App\Servicios\ChatwootApi(
                $c->obtener(\App\Soporte\Http::class),
                $c->obtener(Config::class),
                $c->obtener(Logger::class),
                rtrim(Entorno::obtener('CHATWOOT_URL', '') ?? '', '/'),
                Entorno::obtener('CHATWOOT_ACCOUNT_ID', '1') ?? '1',
                Entorno::obtener('CHATWOOT_BOT_TOKEN', '') ?? '',
                // Agente de Pedro, para asignarle los escalamientos. Si falta,
                // el escalamiento sigue ocurriendo sin asignar: peor atendido,
                // pero atendido.
                ($id = Entorno::obtener('CHATWOOT_AGENTE_ABOGADO_ID', '')) !== null && $id !== ''
                    ? (int) $id
                    : null,
            ),
        );

        $this->contenedor->registrar(
            \App\Servicios\Llm::class,
            static fn (Contenedor $c): \App\Servicios\Llm => new \App\Servicios\Llm(
                $c->obtener(BD::class),
                $c->obtener(Credenciales::class),
                $c->obtener(Config::class),
                $c->obtener(\App\Servicios\GateDorado::class),
                $c->obtener(Logger::class),
                [
                    new \App\Servicios\ClientesLlm\ClienteAnthropic(
                        $c->obtener(\App\Soporte\Http::class),
                    ),
                    new \App\Servicios\ClientesLlm\ClienteOpenAiCompatible(
                        $c->obtener(\App\Soporte\Http::class),
                    ),
                    // Ollama expone el formato de OpenAI bajo `/v1`. Se
                    // reutiliza el mismo cliente en vez de duplicarlo.
                    new \App\Servicios\ClientesLlm\ClienteOpenAiCompatible(
                        $c->obtener(\App\Soporte\Http::class),
                        'ollama',
                        '/v1',
                    ),
                ],
            ),
        );

        // La regla «¿puede este modelo hablar con clientes?» en un solo sitio.
        // La usan el panel al promover y el corredor del conjunto dorado al
        // terminar; duplicada, uno de los dos se quedaría atrás.
        $this->contenedor->registrar(
            \App\Servicios\GateDorado::class,
            static fn (Contenedor $c): \App\Servicios\GateDorado
                => new \App\Servicios\GateDorado($c->obtener(BD::class)),
        );

        // ── Panel (Etapa 3) ──────────────────────────────────────────────
        $this->contenedor->registrar(
            \App\Repositorios\UsuarioRepo::class,
            static fn (Contenedor $c): \App\Repositorios\UsuarioRepo => new \App\Repositorios\UsuarioRepo(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\SesionRepo::class,
            static fn (Contenedor $c): \App\Repositorios\SesionRepo
                => new \App\Repositorios\SesionRepo($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Repositorios\IntentoAccesoRepo::class,
            static fn (Contenedor $c): \App\Repositorios\IntentoAccesoRepo
                => new \App\Repositorios\IntentoAccesoRepo($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Repositorios\AuditoriaRepo::class,
            static fn (Contenedor $c): \App\Repositorios\AuditoriaRepo
                => new \App\Repositorios\AuditoriaRepo($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CredencialRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CredencialRepo
                => new \App\Repositorios\CredencialRepo($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Servicios\Permisos::class,
            static fn (Contenedor $c): \App\Servicios\Permisos
                => new \App\Servicios\Permisos($c->obtener(BD::class)),
        );

        $this->contenedor->registrar(
            \App\Servicios\Autenticacion::class,
            static fn (Contenedor $c): \App\Servicios\Autenticacion => new \App\Servicios\Autenticacion(
                $c->obtener(\App\Repositorios\UsuarioRepo::class),
                $c->obtener(\App\Repositorios\SesionRepo::class),
                $c->obtener(\App\Repositorios\IntentoAccesoRepo::class),
                $c->obtener(\App\Repositorios\AuditoriaRepo::class),
                (int) (Entorno::obtener('SESSION_DURACION_MIN', '120') ?? '120'),
                // El tope del segundo factor es parámetro operativo, no
                // constante: se ajusta desde el panel sin desplegar.
                (int) $c->obtener(Config::class)->get('totp_max_intentos', 5),
            ),
        );

        $this->contenedor->registrar(
            \App\Servicios\ChatwootAgentes::class,
            static fn (Contenedor $c): \App\Servicios\ChatwootAgentes => new \App\Servicios\ChatwootAgentes(
                $c->obtener(\App\Soporte\Http::class),
                $c->obtener(Logger::class),
                rtrim(Entorno::obtener('CHATWOOT_URL', '') ?? '', '/'),
                Entorno::obtener('CHATWOOT_ACCOUNT_ID', '1') ?? '1',
                Entorno::obtener('CHATWOOT_BOT_TOKEN', '') ?? '',
            ),
        );

        $urlBase = rtrim(Entorno::obtener('APP_URL', '') ?? '', '/');

        $this->contenedor->registrar(
            Seo::class,
            static fn (Contenedor $c): Seo => new Seo(
                $c->obtener(BD::class),
                $c->obtener(Config::class),
                $urlBase,
            ),
        );

        $this->contenedor->registrar(
            Landing::class,
            static fn (Contenedor $c): Landing => new Landing(
                $c->obtener(BD::class),
                $c->obtener(Config::class),
                $c->obtener(Seo::class),
                $urlBase,
                $raiz . '/storage/cache/landing.html',
                $raiz . '/storage/landing.sentinel',
                $raiz . '/public/css/app.css',
            ),
        );

        $this->contenedor->registrar(
            MetricasLanding::class,
            static fn (Contenedor $c): MetricasLanding => new MetricasLanding(
                $c->obtener(BD::class),
                $c->obtener(Config::class),
            ),
        );
    }

    /** El panel llega en la Etapa 3; hasta entonces solo landing y salud. */
    private function registrarRutas(): void
    {
        $this->router->get('/', function (): Respuesta {
            return $this->contenedor->obtener(Landing::class)->responder();
        });

        $this->router->get('/robots.txt', function (): Respuesta {
            return $this->contenedor->obtener(Seo::class)->robots();
        });

        $this->router->get('/sitemap.xml', function (): Respuesta {
            return $this->contenedor->obtener(Seo::class)->sitemap();
        });

        // El panel entero cuelga de un solo patrón; el enrutado fino lo hace
        // App\Panel\Panel, que además aplica sesión, CSRF y permisos antes de
        // llegar a ningún módulo.
        foreach (['', '/{a}', '/{a}/{b}', '/{a}/{b}/{c}'] as $sufijo) {
            $patron = '/panel' . $sufijo;

            $this->router->get($patron, fn (Peticion $p): Respuesta => $this->panel($p));
            $this->router->post($patron, fn (Peticion $p): Respuesta => $this->panel($p));
        }

        $this->router->post('/api/evento', function (Peticion $peticion): Respuesta {
            // Endpoint público y de escritura. Se responde 204 pase lo que
            // pase: decirle a un cliente que su evento se descartó no le
            // sirve de nada, y decírselo a quien sondea el endpoint le
            // regala información sobre los filtros.
            try {
                $this->contenedor->obtener(MetricasLanding::class)->registrar($peticion->json());
            } catch (\Throwable $e) {
                $this->contenedor->obtener(Logger::class)->warn('landing.evento_fallido', [
                    'excepcion' => $e::class,
                ]);
            }

            return new Respuesta('', 204);
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

    private function panel(Peticion $peticion): Respuesta
    {
        return (new \App\Panel\Panel($this->contenedor))->manejar($peticion);
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
