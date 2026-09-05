<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Contenedor;
use App\Core\Csrf;
use App\Core\Peticion;
use App\Core\Respuesta;
use App\Modelos\Usuario;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\UsuarioRepo;
use App\Servicios\Autenticacion;
use App\Servicios\Config;
use App\Servicios\Permisos;
use App\Servicios\SinPermisoException;
use App\Soporte\Entorno;
use App\Soporte\Logger;

/**
 * Front controller del panel.
 *
 * Resuelve la ruta, aplica las tres puertas —sesión, CSRF, permiso— y delega
 * en el módulo. El orden de las puertas importa: sin sesión no se sabe qué
 * permisos mirar, y validar CSRF antes de saber si la sesión existe permite
 * distinguir por tiempo qué cookies son válidas.
 */
final class Panel
{
    private const COOKIE_SESION = 'pa_sesion';

    public function __construct(private readonly Contenedor $c)
    {
    }

    public function manejar(Peticion $peticion): Respuesta
    {
        $seguro = (Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo';
        $csrf = new Csrf($seguro);

        $token = $_COOKIE[self::COOKIE_SESION] ?? null;
        $usuario = null;

        if (is_string($token) && $token !== '') {
            $usuario = $this->c->obtener(Autenticacion::class)->usuarioDeSesion($token);
        }

        $ctx = new Contexto(
            $peticion,
            $usuario,
            $this->c->obtener(Permisos::class),
            $csrf,
            is_string($token) ? $token : null,
        );

        // Restricción por IP del panel, si está configurada
        // (docs/PANEL_ADMIN.md §4.6).
        if (!$this->ipPermitida($ctx->ip())) {
            return Respuesta::texto('No autorizado.', 403);
        }

        $ruta = preg_replace('#^/panel#', '', $peticion->ruta) ?: '/';

        try {
            return $this->despachar($ruta, $ctx);
        } catch (SinPermisoException $e) {
            $this->c->obtener(Logger::class)->warn('panel.sin_permiso', [
                'ruta' => $ruta,
                'rol' => $usuario?->rol,
                'permiso' => $e->permiso,
            ]);

            // No se dice QUÉ permiso falta: eso le dibujaría a alguien el
            // mapa completo de la aplicación.
            return $this->vista('panel/error', [
                'ctx' => $ctx,
                'titulo' => 'Sin permiso',
                'mensaje' => 'Su rol no tiene acceso a esta sección.',
            ], 403);
        }
    }

    private function despachar(string $ruta, Contexto $ctx): Respuesta
    {
        $autenticacion = new AutenticacionControlador(
            $this->c->obtener(Autenticacion::class),
            $this->c->obtener(UsuarioRepo::class),
            self::COOKIE_SESION,
        );

        // Rutas públicas: son las únicas que no exigen sesión.
        $publicas = [
            'GET /entrar' => fn (): Respuesta => $autenticacion->formulario($ctx),
            'POST /entrar' => fn (): Respuesta => $autenticacion->entrar($ctx),
            'GET /verificar' => fn (): Respuesta => $autenticacion->formularioTotp($ctx),
            'POST /verificar' => fn (): Respuesta => $autenticacion->verificar($ctx),
        ];

        $clave = $ctx->peticion->metodo . ' ' . rtrim($ruta, '/');
        $clave = $clave === $ctx->peticion->metodo . ' ' ? $ctx->peticion->metodo . ' /' : $clave;

        if (isset($publicas[$clave])) {
            if (!$ctx->csrf->validar($ctx->peticion)) {
                return $this->vista('panel/entrar', [
                    'ctx' => $ctx,
                    'error' => 'La sesión del formulario expiró. Vuelva a intentarlo.',
                ], 419);
            }

            return $publicas[$clave]();
        }

        // A partir de aquí todo exige sesión.
        if ($ctx->usuario === null) {
            return $this->redirigir('/panel/entrar');
        }

        if (!$ctx->csrf->validar($ctx->peticion)) {
            return $this->vista('panel/error', [
                'ctx' => $ctx,
                'titulo' => 'Petición rechazada',
                'mensaje' => 'La verificación de seguridad del formulario falló. Recargue la página.',
            ], 419);
        }

        $modulos = $this->modulos($ctx);

        return match ($clave) {
            'GET /' => $modulos['tablero']->inicio($ctx),
            'POST /inversion' => $modulos['tablero']->anotarInversion($ctx),
            'POST /salir' => $autenticacion->salir($ctx),

            'GET /contenido' => $modulos['contenido']->listar($ctx),
            'GET /contenido/editar' => $modulos['contenido']->editar($ctx),
            'POST /contenido/guardar' => $modulos['contenido']->guardar($ctx),

            'GET /configuracion' => $modulos['configuracion']->listar($ctx),
            'POST /configuracion' => $modulos['configuracion']->guardar($ctx),

            'GET /tarifas' => $modulos['tarifas']->listar($ctx),
            'POST /tarifas' => $modulos['tarifas']->guardar($ctx),

            'GET /usuarios' => $modulos['usuarios']->listar($ctx),
            'POST /usuarios' => $modulos['usuarios']->crear($ctx),

            'GET /seguridad' => $modulos['usuarios']->seguridad($ctx),
            'POST /seguridad/totp' => $modulos['usuarios']->prepararTotp($ctx),
            'POST /seguridad/totp/confirmar' => $modulos['usuarios']->confirmarTotp($ctx),

            'GET /auditoria' => $modulos['auditoria']->listar($ctx),

            'GET /cursos' => $modulos['cursos']->listar($ctx),
            'GET /cursos/editar' => $modulos['cursos']->editar($ctx),
            'POST /cursos/guardar' => $modulos['cursos']->guardar($ctx),
            'POST /cursos/publicar' => $modulos['cursos']->publicar($ctx),
            'POST /cursos/despublicar' => $modulos['cursos']->despublicar($ctx),
            'POST /cursos/modulos/agregar' => $modulos['cursos']->agregarModulo($ctx),
            'POST /cursos/modulos/eliminar' => $modulos['cursos']->eliminarModulo($ctx),
            'POST /cursos/lecciones/agregar' => $modulos['cursos']->agregarLeccion($ctx),
            'POST /cursos/lecciones/eliminar' => $modulos['cursos']->eliminarLeccion($ctx),
            'GET /cursos/lecciones/editar' => $modulos['cursos']->editarLeccion($ctx),
            'POST /cursos/lecciones/guardar' => $modulos['cursos']->guardarLeccion($ctx),
            'POST /cursos/lecciones/materiales/agregar' => $modulos['cursos']->agregarMaterial($ctx),
            'POST /cursos/lecciones/materiales/eliminar' => $modulos['cursos']->eliminarMaterial($ctx),
            'GET /cursos/categorias' => $modulos['cursos']->categorias($ctx),
            'POST /cursos/categorias/guardar' => $modulos['cursos']->guardarCategoria($ctx),
            'GET /cursos/compras' => $modulos['cursos']->compras($ctx),
            'POST /cursos/compras/aprobar' => $modulos['cursos']->aprobarCompra($ctx),
            'POST /cursos/compras/reenviar' => $modulos['cursos']->reenviarAcceso($ctx),

            'GET /whatsapp' => $modulos['whatsapp']->ver($ctx),
            'GET /whatsapp/modelos' => $modulos['whatsapp']->modelos($ctx),
            'POST /whatsapp/modelos/sincronizar' => $modulos['whatsapp']->sincronizarModelos($ctx),
            'POST /whatsapp/conexion' => $modulos['whatsapp']->guardarConexion($ctx),
            'POST /whatsapp/ia' => $modulos['whatsapp']->guardarIa($ctx),
            'POST /whatsapp/media' => $modulos['whatsapp']->guardarMedia($ctx),
            'POST /whatsapp/encender' => $modulos['whatsapp']->encender($ctx),
            'POST /whatsapp/apagar' => $modulos['whatsapp']->apagar($ctx),
            'POST /whatsapp/token' => $modulos['whatsapp']->regenerarToken($ctx),
            'POST /whatsapp/qr' => $modulos['whatsapp']->conectarQr($ctx),
            'POST /whatsapp/cobro' => $modulos['whatsapp']->guardarCobro($ctx),
            'POST /whatsapp/wompi' => $modulos['whatsapp']->guardarWompi($ctx),
            'POST /whatsapp/horario' => $modulos['whatsapp']->guardarHorario($ctx),
            'POST /whatsapp/agente' => $modulos['whatsapp']->guardarAgente($ctx),
            'POST /whatsapp/google' => $modulos['whatsapp']->guardarGoogle($ctx),
            'POST /whatsapp/google/codigo' => $modulos['whatsapp']->canjearGoogle($ctx),
            'GET /whatsapp/citas' => $modulos['whatsapp']->citas($ctx),
            'GET /whatsapp/pendientes' => $modulos['whatsapp']->pendientes($ctx),
            'POST /whatsapp/pendientes/analizar' => $modulos['whatsapp']->proponerPendientes($ctx),
            'POST /whatsapp/pendientes/enviar' => $modulos['whatsapp']->enviarPendientes($ctx),
            'GET /whatsapp/conversaciones' => $modulos['whatsapp']->conversaciones($ctx),
            'POST /whatsapp/conversaciones/reanudar' => $modulos['whatsapp']->reanudarIa($ctx),
            'POST /whatsapp/conversaciones/responder' => $modulos['whatsapp']->responder($ctx),
            'GET /whatsapp/voz-prueba' => $modulos['whatsapp']->probarVoz($ctx),
            'POST /whatsapp/voz-prueba/enviar' => $modulos['whatsapp']->enviarPruebaVoz($ctx),
            'POST /whatsapp/pagos/aprobar' => $modulos['whatsapp']->aprobarPago($ctx),
            'GET /whatsapp/comprobante' => $modulos['whatsapp']->comprobante($ctx),

            default => $this->vista('panel/error', [
                'ctx' => $ctx,
                'titulo' => 'No encontrado',
                'mensaje' => 'Esa sección no existe.',
            ], 404),
        };
    }

    /** @return array<string,object> */
    private function modulos(Contexto $ctx): array
    {
        return [
            'tablero' => new TableroControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(Config::class),
                $this->c->obtener(\App\Servicios\MetricasLanding::class),
            ),
            'configuracion' => new ConfiguracionControlador(
                $this->c->obtener(Config::class),
            ),
            'tarifas' => new TarifasControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(AuditoriaRepo::class),
            ),
            'cursos' => new PanelCursosControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(AuditoriaRepo::class),
                $this->c->obtener(\App\Repositorios\CompraCursoRepo::class),
                $this->c->obtener(\App\Cuenta\ConfirmadorCompra::class),
                $this->c->obtener(\App\Repositorios\CursoMaterialRepo::class),
            ),
            'usuarios' => new UsuariosControlador(
                $this->c->obtener(UsuarioRepo::class),
                $this->c->obtener(Autenticacion::class),
                $this->c->obtener(AuditoriaRepo::class),
                $this->c->obtener(BD::class),
                $this->c->obtener(Logger::class),
            ),
            'auditoria' => new AuditoriaControlador(
                $this->c->obtener(AuditoriaRepo::class),
            ),
            'contenido' => new ContenidoControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(AuditoriaRepo::class),
                $this->c->obtener(\App\Servicios\Landing::class),
            ),
            'whatsapp' => new WhatsappControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(\App\Soporte\Cifrado::class),
                $this->c->obtener(Logger::class),
                $this->c->obtener(AuditoriaRepo::class),
            ),
        ];
    }

    /**
     * `PANEL_IPS_PERMITIDAS` en el .env. Vacío = sin restricción, que es lo
     * razonable mientras no haya IP fija; el documento lo pide «si es
     * viable» (docs/PANEL_ADMIN.md §4.6).
     */
    private function ipPermitida(?string $ip): bool
    {
        $lista = trim(Entorno::obtener('PANEL_IPS_PERMITIDAS', '') ?? '');

        if ($lista === '' || $ip === null) {
            return true;
        }

        foreach (explode(',', $lista) as $permitida) {
            if (trim($permitida) === $ip) {
                return true;
            }
        }

        return false;
    }

    private function redirigir(string $destino): Respuesta
    {
        return new Respuesta('', 302, ['Location' => $destino]);
    }

    /** @param array<string,mixed> $datos */
    private function vista(string $plantilla, array $datos, int $estado = 200): Respuesta
    {
        return Respuesta::vista($plantilla, $datos, $estado);
    }
}
