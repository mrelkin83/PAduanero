<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\Respuesta;
use App\Repositorios\UsuarioRepo;
use App\Servicios\Autenticacion;
use App\Soporte\Entorno;

final class AutenticacionControlador extends ControladorBase
{
    public function __construct(
        private readonly Autenticacion $auth,
        private readonly UsuarioRepo $usuarios,
        private readonly string $cookieSesion,
    ) {
    }

    public function formulario(Contexto $ctx): Respuesta
    {
        if ($ctx->usuario !== null) {
            return $this->redirigir('/panel');
        }

        return $this->vista('panel/entrar', ['ctx' => $ctx, 'error' => null]);
    }

    public function entrar(Contexto $ctx): Respuesta
    {
        $resultado = $this->auth->verificarCredenciales(
            $ctx->campo('email'),
            $ctx->campo('password'),
            $ctx->ip(),
        );

        if (!$resultado['ok']) {
            return $this->vista('panel/entrar', [
                'ctx' => $ctx,
                'error' => $resultado['motivo'],
            ], 401);
        }

        $usuario = $resultado['usuario'];

        if ($resultado['requiereTotp'] === true) {
            $this->marcarPendiente($usuario->id);

            return $this->redirigir('/panel/verificar');
        }

        return $this->abrir($ctx, $usuario->id);
    }

    public function formularioTotp(Contexto $ctx): Respuesta
    {
        $usuarioId = $this->pendiente();

        if ($usuarioId === null) {
            return $this->redirigir('/panel/entrar');
        }

        $usuario = $this->usuarios->porId($usuarioId);

        if ($usuario === null) {
            return $this->redirigir('/panel/entrar');
        }

        // Rol que exige TOTP pero todavía sin activar: se le deja pasar para
        // que lo active. La alternativa —bloquearlo— dejaría a Pedro fuera de
        // su propio panel el primer día.
        if (!$usuario->totpActivo) {
            return $this->abrir($ctx, $usuario->id, '/panel/seguridad');
        }

        return $this->vista('panel/verificar', ['ctx' => $ctx, 'error' => null]);
    }

    public function verificar(Contexto $ctx): Respuesta
    {
        $usuarioId = $this->pendiente();

        if ($usuarioId === null) {
            return $this->redirigir('/panel/entrar');
        }

        $usuario = $this->usuarios->porId($usuarioId);

        if ($usuario === null) {
            return $this->redirigir('/panel/entrar');
        }

        $resultado = $this->auth->verificarTotp($usuario, $ctx->campo('codigo'), $ctx->ip());

        if (!$resultado['ok']) {
            return $this->vista('panel/verificar', [
                'ctx' => $ctx,
                'error' => $resultado['motivo'],
            ], 401);
        }

        return $this->abrir($ctx, $usuario->id);
    }

    public function salir(Contexto $ctx): Respuesta
    {
        if ($ctx->tokenSesion !== null) {
            $this->auth->cerrarSesion($ctx->tokenSesion, $ctx->usuario, $ctx->ip());
        }

        $this->borrarCookie($this->cookieSesion);
        $this->limpiarPendiente();

        return $this->redirigir('/panel/entrar');
    }

    // ── Sesión ───────────────────────────────────────────────────────────

    private function abrir(Contexto $ctx, string $usuarioId, string $destino = '/panel'): Respuesta
    {
        $usuario = $this->usuarios->porId($usuarioId);

        if ($usuario === null) {
            return $this->redirigir('/panel/entrar');
        }

        $token = $this->auth->abrirSesion(
            $usuario,
            $ctx->ip(),
            $ctx->peticion->cabecera('user-agent'),
        );

        $this->limpiarPendiente();

        $minutos = (int) (Entorno::obtener('SESSION_DURACION_MIN', '120') ?? '120');

        setcookie($this->cookieSesion, $token, [
            'expires' => time() + $minutos * 60,
            'path' => '/',
            'secure' => $this->seguro(),
            // HttpOnly sí: este token ES el secreto de sesión y ningún
            // JavaScript tiene motivo para leerlo. Es lo que limita el daño
            // de un XSS a no poder robar la sesión.
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $this->redirigir($destino);
    }

    /**
     * Paso intermedio entre contraseña y TOTP.
     *
     * Usa la sesión nativa de PHP y no la tabla `sesiones`: esa tabla guarda
     * sesiones **autenticadas**, y meter ahí un estado a medias obligaría a
     * una columna nueva y a que todo el código que lee sesiones recordara
     * filtrarla. Cinco minutos de vida y solo lleva un id.
     */
    private function marcarPendiente(string $usuarioId): void
    {
        $this->iniciarSesionPhp();

        $_SESSION['pendiente_totp'] = $usuarioId;
        $_SESSION['pendiente_hasta'] = time() + 300;
    }

    private function pendiente(): ?string
    {
        $this->iniciarSesionPhp();

        $id = $_SESSION['pendiente_totp'] ?? null;
        $hasta = (int) ($_SESSION['pendiente_hasta'] ?? 0);

        if (!is_string($id) || $hasta < time()) {
            $this->limpiarPendiente();

            return null;
        }

        return $id;
    }

    private function limpiarPendiente(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['pendiente_totp'], $_SESSION['pendiente_hasta']);
        }
    }

    private function iniciarSesionPhp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/panel',
            'secure' => $this->seguro(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('pa_paso2');
        @session_start();
    }

    private function borrarCookie(string $nombre): void
    {
        if (!headers_sent()) {
            setcookie($nombre, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $this->seguro(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    private function seguro(): bool
    {
        return (Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo';
    }
}
