<?php

declare(strict_types=1);

namespace App\Core;

use App\Excepciones\ConfiguracionFatalException;
use App\Servicios\Config;
use App\Servicios\ConfigMysql;
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
 * deliberado y está probado: sin `MASTER_KEY` la aplicación NO arranca.
 * Sigue siendo obligatoria aunque ya no haya credenciales de proveedores que
 * guardar —el motor y la pasarela se retiraron—: cifra los secretos TOTP de
 * `usuarios`, y arrancar sin ella significaría escribir segundos factores
 * que nadie podrá volver a descifrar.
 *
 * Lo que este archivo cablea, ahora que es todo lo que hay: las dos páginas
 * públicas (`/` y `/perfil`), su SEO, el registro de eventos de la landing y
 * el panel de contenido.
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
        // Construir el Cifrado valida de una vez que MASTER_KEY exista, sea
        // base64 y mida 32 bytes.
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

        // El diagnóstico comparte el centinela de la landing: sus bloques
        // viven en la misma tabla, así que guardar uno tiene que invalidar
        // las dos páginas.
        $this->contenedor->registrar(
            \App\Servicios\Perfil::class,
            static fn (Contenedor $c): \App\Servicios\Perfil => new \App\Servicios\Perfil(
                $c->obtener(BD::class),
                $c->obtener(Config::class),
                $c->obtener(Seo::class),
                $urlBase,
                $raiz . '/storage/cache/perfil.html',
                $raiz . '/storage/landing.sentinel',
                $raiz . '/public/css/app.css',
            ),
        );

        $this->contenedor->registrar(
            \App\Servicios\Cursos::class,
            static fn (Contenedor $c): \App\Servicios\Cursos => new \App\Servicios\Cursos(
                $c->obtener(BD::class),
                $c->obtener(Config::class),
                $urlBase,
            ),
        );

        $this->contenedor->registrar(
            \App\Wa\ConexionCompartida::class,
            static fn (Contenedor $c): \App\Wa\ConexionCompartida => new \App\Wa\ConexionCompartida(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
                $c->obtener(Logger::class),
                $raiz,
            ),
        );

        $this->contenedor->registrar(
            \App\Cuenta\ConfirmadorCompra::class,
            static fn (Contenedor $c): \App\Cuenta\ConfirmadorCompra => new \App\Cuenta\ConfirmadorCompra(
                $c->obtener(\App\Repositorios\CompraCursoRepo::class),
                $c->obtener(\App\Repositorios\CompradorEnlaceRepo::class),
                $c->obtener(\App\Wa\ConexionCompartida::class),
                $c->obtener(BD::class),
                \App\Soporte\Smtp::desdeEntorno(),
                $urlBase,
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CompraCursoRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CompraCursoRepo => new \App\Repositorios\CompraCursoRepo(
                $c->obtener(BD::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CursoMaterialRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CursoMaterialRepo => new \App\Repositorios\CursoMaterialRepo(
                $c->obtener(BD::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CompradorRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CompradorRepo => new \App\Repositorios\CompradorRepo(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CompradorSesionRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CompradorSesionRepo => new \App\Repositorios\CompradorSesionRepo(
                $c->obtener(BD::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CompradorEnlaceRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CompradorEnlaceRepo => new \App\Repositorios\CompradorEnlaceRepo(
                $c->obtener(BD::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Servicios\AutenticacionComprador::class,
            static fn (Contenedor $c): \App\Servicios\AutenticacionComprador => new \App\Servicios\AutenticacionComprador(
                $c->obtener(\App\Repositorios\CompradorRepo::class),
                $c->obtener(\App\Repositorios\CompradorSesionRepo::class),
                $c->obtener(\App\Repositorios\IntentoAccesoRepo::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Servicios\PaginaLegal::class,
            static fn (Contenedor $c): \App\Servicios\PaginaLegal => new \App\Servicios\PaginaLegal(
                $c->obtener(Config::class),
                $urlBase,
                $c->obtener(BD::class),
            ),
        );

        $this->contenedor->registrar(
            MetricasLanding::class,
            static fn (Contenedor $c): MetricasLanding => new MetricasLanding(
                $c->obtener(BD::class),
                $c->obtener(Config::class),
            ),
        );

        // ── Motor de WhatsApp (2026-08-22) ───────────────────────────────
        // El motor vendorizado en packages/whatsapp-engine, conectado por los
        // puertos de src/Wa. Solo se instancia si un webhook llega.
        $this->contenedor->registrar(
            \App\Wa\WebhookControlador::class,
            static fn (Contenedor $c): \App\Wa\WebhookControlador => new \App\Wa\WebhookControlador(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
                $c->obtener(Logger::class),
                $raiz,
            ),
        );
    }

    /** El panel llega en la Etapa 3; hasta entonces solo landing y salud. */
    private function registrarRutas(): void
    {
        // Mismo cálculo que en registrarServicios(): esta es una función
        // aparte y no comparte sus variables locales.
        $urlBase = rtrim(Entorno::obtener('APP_URL', '') ?? '', '/');

        $this->router->get('/', function (): Respuesta {
            return $this->contenedor->obtener(Landing::class)->responder();
        });

        $this->router->get('/perfil', function (): Respuesta {
            return $this->contenedor->obtener(\App\Servicios\Perfil::class)->responder();
        });

        $this->router->get('/cursos', function (Peticion $p): Respuesta {
            $categoria = $p->consulta['categoria'] ?? null;

            return $this->contenedor->obtener(\App\Servicios\Cursos::class)->catalogo(
                is_string($categoria) && $categoria !== '' ? $categoria : null,
            );
        });

        $this->router->get('/cursos/{slug}', function (Peticion $p): Respuesta {
            return $this->contenedor->obtener(\App\Servicios\Cursos::class)->ficha($p->parametros['slug']);
        });

        $this->router->get('/cursos/{slug}/comprar', function (Peticion $p): Respuesta {
            $conexion = $this->contenedor->obtener(\App\Wa\ConexionCompartida::class);

            return (new \App\Cuenta\ComprasControlador(
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $conexion->wompi(),
                $urlBase,
            ))->formulario($p, (string) $p->parametros['slug']);
        });

        $this->router->post('/cursos/{slug}/comprar', function (Peticion $p): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            $conexion = $this->contenedor->obtener(\App\Wa\ConexionCompartida::class);

            return (new \App\Cuenta\ComprasControlador(
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $conexion->wompi(),
                $urlBase,
            ))->procesar($p, (string) $p->parametros['slug']);
        });

        $this->router->get('/cursos/{slug}/gracias', function (Peticion $p): Respuesta {
            $conexion = $this->contenedor->obtener(\App\Wa\ConexionCompartida::class);

            return (new \App\Cuenta\ComprasControlador(
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $conexion->wompi(),
                $urlBase,
            ))->gracias($p, (string) $p->parametros['slug']);
        });

        $accesoControlador = fn (): \App\Cuenta\AccesoControlador => new \App\Cuenta\AccesoControlador(
            $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
            $this->contenedor->obtener(\App\Repositorios\CompradorRepo::class),
            $this->contenedor->obtener(\App\Repositorios\CompradorSesionRepo::class),
            $this->contenedor->obtener(\App\Repositorios\CompradorEnlaceRepo::class),
            $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
            \App\Soporte\Smtp::desdeEntorno(),
            $urlBase,
        );

        $this->router->get('/mis-cursos/completar', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->completarMostrar($p);
        });
        $this->router->post('/mis-cursos/completar', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->completarProcesar($p);
        });

        $this->router->get('/entrar', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->entrarMostrar($p);
        });
        $this->router->post('/entrar', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->entrarProcesar($p);
        });

        $this->router->post('/salir', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->salir($p);
        });

        $this->router->get('/recuperar', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->recuperarMostrar($p);
        });
        $this->router->post('/recuperar', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->recuperarProcesar($p);
        });

        $this->router->get('/recuperar/confirmar', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->recuperarConfirmarMostrar($p);
        });
        $this->router->post('/recuperar/confirmar', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->recuperarConfirmarProcesar($p);
        });

        $this->router->get('/mis-cursos', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\MisCursosControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
            ))->mostrar($p);
        });

        $this->router->get('/mis-cursos/{slug}', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\AulaControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                new \App\Cuenta\AccesoLeccion($this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class)),
                $this->contenedor->obtener(BD::class),
                \App\Soporte\BunnyStream::desdeEntorno(),
                $this->contenedor->obtener(\App\Repositorios\CursoMaterialRepo::class),
            ))->aula($p, (string) $p->parametros['slug']);
        });

        $this->router->get('/mis-cursos/{slug}/leccion/{leccionId}', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\AulaControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                new \App\Cuenta\AccesoLeccion($this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class)),
                $this->contenedor->obtener(BD::class),
                \App\Soporte\BunnyStream::desdeEntorno(),
                $this->contenedor->obtener(\App\Repositorios\CursoMaterialRepo::class),
            ))->leccion($p, (string) $p->parametros['slug'], (string) $p->parametros['leccionId']);
        });

        $this->router->get('/mis-cursos/{slug}/leccion/{leccionId}/material/{materialId}', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\AulaControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                new \App\Cuenta\AccesoLeccion($this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class)),
                $this->contenedor->obtener(BD::class),
                \App\Soporte\BunnyStream::desdeEntorno(),
                $this->contenedor->obtener(\App\Repositorios\CursoMaterialRepo::class),
            ))->material(
                $p,
                (string) $p->parametros['slug'],
                (string) $p->parametros['leccionId'],
                (string) $p->parametros['materialId'],
            );
        });

        // Las páginas legales. Las exige el propio proyecto (el motor de
        // WhatsApp trata datos personales) y además Google, que solo publica
        // la app OAuth del calendario si hay una política enlazada.
        $this->router->get('/privacidad', function (): Respuesta {
            return $this->contenedor->obtener(\App\Servicios\PaginaLegal::class)->privacidad();
        });

        $this->router->get('/condiciones', function (): Respuesta {
            return $this->contenedor->obtener(\App\Servicios\PaginaLegal::class)->condiciones();
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

        // Webhooks del motor de WhatsApp. El token de 256 bits de la URL es
        // la autenticación (se valida contra su hash en wa_config); un token
        // que no case recibe 404 seco.
        $this->router->post('/api/wa/webhook/{token}', function (Peticion $p): Respuesta {
            return $this->contenedor->obtener(\App\Wa\WebhookControlador::class)->entrada($p);
        });

        $this->router->post('/api/wa/pago/{token}', function (Peticion $p): Respuesta {
            return $this->contenedor->obtener(\App\Wa\WebhookControlador::class)->pago($p);
        });

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
