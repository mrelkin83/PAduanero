<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\Usuario;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Repositorios\SesionRepo;
use App\Repositorios\UsuarioRepo;
use App\Soporte\Totp;

/**
 * Entrada al panel.
 *
 * Tres defensas que atacan cosas distintas y por eso están las tres:
 *   · bloqueo por CUENTA — fuerza bruta contra un correo conocido
 *   · rate limit por IP  — barrido de mil correos con una contraseña común,
 *                          que nunca dispara el bloqueo por cuenta
 *   · TOTP               — la contraseña ya filtrada por otro lado
 *
 * Los mensajes de error son deliberadamente vagos («credenciales
 * incorrectas», nunca «ese correo no existe»): decir cuál de las dos falló
 * convierte el formulario en un enumerador de cuentas.
 */
final class Autenticacion
{
    /** Fallos por IP en 15 minutos antes de cortar. */
    private const TOPE_POR_IP = 20;

    public function __construct(
        private readonly UsuarioRepo $usuarios,
        private readonly SesionRepo $sesiones,
        private readonly IntentoAccesoRepo $intentos,
        private readonly AuditoriaRepo $auditoria,
        private readonly int $duracionMinutos = 120,
        private readonly int $maxIntentosTotp = 5,
    ) {
    }

    /**
     * Primer paso: correo y contraseña.
     *
     * @return array{ok:bool,motivo?:string,usuario?:Usuario,requiereTotp?:bool}
     */
    public function verificarCredenciales(string $email, string $password, ?string $ip): array
    {
        $email = mb_strtolower(trim($email));

        if ($this->intentos->fallosRecientes('login', $ip) >= self::TOPE_POR_IP) {
            $this->intentos->registrar('login', $ip, false, $email);

            return ['ok' => false, 'motivo' => 'Demasiados intentos desde esta conexión. Espere unos minutos.'];
        }

        $minutos = $this->usuarios->minutosDeBloqueo($email);
        if ($minutos !== null) {
            $this->intentos->registrar('login', $ip, false, $email);

            return [
                'ok' => false,
                'motivo' => "Cuenta bloqueada temporalmente. Vuelva a intentar en {$minutos} minuto(s).",
            ];
        }

        if (!$this->usuarios->verificarPassword($email, $password)) {
            $this->usuarios->registrarFallo($email);
            $this->intentos->registrar('login', $ip, false, $email);
            $this->auditoria->registrar('sesion', null, 'login_fallido', $email, [], $ip);

            return ['ok' => false, 'motivo' => 'Credenciales incorrectas.'];
        }

        $usuario = $this->usuarios->porEmail($email);

        if ($usuario === null || !$usuario->activo) {
            return ['ok' => false, 'motivo' => 'Credenciales incorrectas.'];
        }

        return [
            'ok' => true,
            'usuario' => $usuario,
            // Si el rol exige TOTP pero aún no lo activó, se le manda a
            // activarlo antes de dejarle entrar a nada.
            'requiereTotp' => $usuario->totpActivo || $usuario->exigeTotp(),
        ];
    }

    /** Segundo paso. @return array{ok:bool,motivo?:string} */
    public function verificarTotp(Usuario $usuario, string $codigo, ?string $ip): array
    {
        $secreto = $this->usuarios->secretoTotp($usuario->id);

        if ($secreto === null || !$usuario->totpActivo) {
            return ['ok' => false, 'motivo' => 'Este usuario todavía no tiene la verificación en dos pasos activa.'];
        }

        // Tope PROPIO del segundo factor, y por cuenta, no por IP: son seis
        // dígitos y quien ya pasó la contraseña puede rotar de IP mientras
        // prueba. El contador de la contraseña mide otra cosa y no sirve.
        if ($this->intentos->fallosDeUsuario('totp', $usuario->email) >= $this->maxIntentosTotp) {
            $this->auditoria->registrar('sesion', $usuario->id, 'totp_bloqueado', $usuario->email, [], $ip);

            return [
                'ok' => false,
                'motivo' => 'Demasiados códigos incorrectos. Espere unos minutos antes de volver a intentar.',
            ];
        }

        $contador = Totp::verificarConContador(
            $secreto,
            $codigo,
            // Antirreplay: un código ya usado no vuelve a valer aunque siga
            // dentro de su ventana de 30 segundos (RFC 6238 §5.2).
            $this->usuarios->ultimoContadorTotp($usuario->id),
        );

        if ($contador === null) {
            $this->usuarios->registrarFallo($usuario->email);
            $this->intentos->registrar('totp', $ip, false, $usuario->email);
            $this->auditoria->registrar('sesion', $usuario->id, 'totp_fallido', $usuario->email, [], $ip);

            return ['ok' => false, 'motivo' => 'Código incorrecto.'];
        }

        $this->usuarios->guardarContadorTotp($usuario->id, $contador);
        $this->intentos->registrar('totp', $ip, true, $usuario->email);

        return ['ok' => true];
    }

    /** @return string token de sesión en claro, para la cookie */
    public function abrirSesion(Usuario $usuario, ?string $ip, ?string $userAgent): string
    {
        $token = $this->sesiones->crear($usuario->id, $this->duracionMinutos, $ip, $userAgent);

        $this->usuarios->registrarAcceso($usuario->id);
        $this->intentos->registrar('login', $ip, true, $usuario->email);
        $this->auditoria->registrar('sesion', $usuario->id, 'login', $usuario->email, [
            'rol' => $usuario->rol,
        ], $ip);

        return $token;
    }

    public function cerrarSesion(string $token, ?Usuario $usuario, ?string $ip): void
    {
        $this->sesiones->revocar($token);

        if ($usuario !== null) {
            $this->auditoria->registrar('sesion', $usuario->id, 'logout', $usuario->email, [], $ip);
        }
    }

    public function usuarioDeSesion(string $token): ?Usuario
    {
        $sesion = $this->sesiones->vigente($token);

        if ($sesion === null) {
            return null;
        }

        $usuario = $this->usuarios->porId($sesion['usuario_id']);

        // Una cuenta desactivada tiene que perder el acceso de inmediato, sin
        // esperar a que caduque su sesión.
        return ($usuario !== null && $usuario->activo) ? $usuario : null;
    }

    // ── Alta de TOTP ─────────────────────────────────────────────────────

    /** @return array{secreto:string,uri:string} */
    public function prepararTotp(Usuario $usuario): array
    {
        $secreto = Totp::generarSecreto();
        $this->usuarios->guardarSecretoTotp($usuario->id, $secreto);

        return ['secreto' => $secreto, 'uri' => Totp::uri($secreto, $usuario->email)];
    }

    /**
     * Confirma el alta con un código.
     *
     * Se exige el código antes de activar a propósito: activar sin
     * comprobarlo dejaría a Pedro fuera del panel con un secreto que su
     * teléfono nunca llegó a guardar.
     */
    public function confirmarTotp(Usuario $usuario, string $codigo, ?string $ip): bool
    {
        $secreto = $this->usuarios->secretoTotp($usuario->id);

        if ($secreto === null) {
            return false;
        }

        // El alta también tiene tope: si no, el formulario de activación
        // sería una vía de fuerza bruta sin vigilancia.
        if ($this->intentos->fallosDeUsuario('totp', $usuario->email) >= $this->maxIntentosTotp) {
            return false;
        }

        // Sin `ultimoAceptado`: es el primer código de este secreto y no hay
        // contador previo con el que comparar.
        $contador = Totp::verificarConContador($secreto, $codigo);

        if ($contador === null) {
            $this->intentos->registrar('totp', $ip, false, $usuario->email);

            return false;
        }

        // Se guarda el contador del propio código de activación: si no, ese
        // mismo código serviría acto seguido para iniciar sesión.
        $this->usuarios->activarTotp($usuario->id, $contador);
        $this->auditoria->registrar('usuario', $usuario->id, 'totp_activado', $usuario->email, [], $ip);

        return true;
    }

    /**
     * Restablece el segundo factor de un usuario.
     *
     * Solo desde consola (`bin/restablecer-2fa.php`). Deja la cuenta sin
     * TOTP y con todas sus sesiones revocadas: el siguiente ingreso obliga a
     * configurarlo de nuevo.
     */
    public function restablecerTotp(Usuario $usuario, string $actor, ?string $motivo = null): void
    {
        $this->usuarios->desactivarTotp($usuario->id);

        // Revocar es imprescindible: si el teléfono se perdió, una sesión
        // abierta en ese teléfono sigue siendo una sesión abierta.
        $revocadas = $this->sesiones->revocarTodas($usuario->id);

        $this->auditoria->registrar('usuario', $usuario->id, 'totp_restablecido', $actor, [
            'usuario_afectado' => $usuario->email,
            'motivo' => $motivo,
            'sesiones_revocadas' => $revocadas,
        ]);
    }

    public function cambiarPassword(Usuario $usuario, string $nueva, ?string $ip): void
    {
        $this->usuarios->cambiarPassword($usuario->id, $nueva);

        // Rotación al cambiar contraseña (docs/PANEL_ADMIN.md §4.2): si no,
        // quien robó una sesión sigue dentro después de que la víctima
        // «arregle» el problema.
        $revocadas = $this->sesiones->revocarTodas($usuario->id);

        $this->auditoria->registrar('usuario', $usuario->id, 'password_cambiada', $usuario->email, [
            'sesiones_revocadas' => $revocadas,
        ], $ip);
    }
}
