<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorEnlaceRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Servicios\AutenticacionComprador;
use App\Soporte\Entorno;
use App\Soporte\Smtp;

final class AccesoControlador
{
    public const COOKIE = 'pa_comprador';
    private const MINUTOS_RESET = 120; // 2 horas

    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly CompradorRepo $compradores,
        private readonly CompradorSesionRepo $sesiones,
        private readonly CompradorEnlaceRepo $enlaces,
        private readonly CompraCursoRepo $compras,
        private readonly ?Smtp $smtp,
        private readonly string $urlBase,
    ) {
    }

    public function completarMostrar(Peticion $peticion): Respuesta
    {
        $datos = $this->resolverEnlaceCompletar((string) ($peticion->consulta['token'] ?? ''));
        if ($datos === null) {
            return Respuesta::vista('cuenta/enlace_invalido', [], 410);
        }

        return Respuesta::vista('cuenta/completar', [
            'token' => $datos['token'],
            'correo' => $datos['correo'],
            'existeCuenta' => $this->compradores->existeCorreo($datos['correo']),
            'error' => $peticion->consulta['error'] ?? null,
        ]);
    }

    public function completarProcesar(Peticion $peticion): Respuesta
    {
        $token = (string) ($peticion->formulario['token'] ?? '');
        $datos = $this->resolverEnlaceCompletar($token);

        if ($datos === null) {
            return Respuesta::vista('cuenta/enlace_invalido', [], 410);
        }

        $correo = $datos['correo'];
        $modo = (string) ($peticion->formulario['modo'] ?? '');

        if ($modo === 'login') {
            $resultado = $this->auth->verificarCredenciales($correo, (string) ($peticion->formulario['password'] ?? ''), $peticion->ip);

            if (!$resultado['ok']) {
                return $this->redirigirCompletar($token, $resultado['motivo']);
            }

            $comprador = $resultado['comprador'];
        } else {
            if ($this->compradores->existeCorreo($correo)) {
                return $this->redirigirCompletar($token, 'Ese correo ya tiene una cuenta. Inicie sesión.');
            }

            $nombres = trim((string) ($peticion->formulario['nombres'] ?? ''));
            $apellidos = trim((string) ($peticion->formulario['apellidos'] ?? ''));
            $tipoDocumento = (string) ($peticion->formulario['tipo_documento'] ?? '');
            $numeroDocumento = trim((string) ($peticion->formulario['numero_documento'] ?? ''));
            $celular = trim((string) ($peticion->formulario['celular'] ?? ''));
            $password = (string) ($peticion->formulario['password'] ?? '');

            if ($nombres === '' || $apellidos === '' || $numeroDocumento === '' || $celular === ''
                || mb_strlen($password) < 8
                || !in_array($tipoDocumento, ['CC', 'CE', 'PASAPORTE', 'NIT'], true)
            ) {
                return $this->redirigirCompletar($token, 'Complete todos los campos. La contraseña debe tener al menos 8 caracteres.');
            }

            $compradorId = $this->compradores->crear($nombres, $apellidos, $tipoDocumento, $numeroDocumento, $celular, $correo, $password);
            $comprador = $this->compradores->porId($compradorId);
        }

        $this->compras->vincularComprador($datos['compraId'], $comprador->id);
        $this->enlaces->marcarUsado($datos['enlaceId']);

        $sesionToken = $this->auth->abrirSesion($comprador, $peticion->ip, $peticion->cabecera('user-agent'));

        return $this->conCookieDeSesion($sesionToken, '/mis-cursos');
    }

    public function entrarMostrar(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('cuenta/entrar', ['error' => $peticion->consulta['error'] ?? null]);
    }

    public function entrarProcesar(Peticion $peticion): Respuesta
    {
        $resultado = $this->auth->verificarCredenciales(
            (string) ($peticion->formulario['correo'] ?? ''),
            (string) ($peticion->formulario['password'] ?? ''),
            $peticion->ip,
        );

        if (!$resultado['ok']) {
            return new Respuesta('', 302, [
                'Location' => '/entrar?' . http_build_query(['error' => $resultado['motivo']]),
            ]);
        }

        $token = $this->auth->abrirSesion($resultado['comprador'], $peticion->ip, $peticion->cabecera('user-agent'));

        return $this->conCookieDeSesion($token, '/mis-cursos');
    }

    public function salir(Peticion $peticion): Respuesta
    {
        $token = $_COOKIE[self::COOKIE] ?? null;

        if (is_string($token) && $token !== '') {
            $this->auth->cerrarSesion($token);
        }

        $this->borrarCookieDeSesion();

        return new Respuesta('', 302, ['Location' => '/entrar']);
    }

    public function recuperarMostrar(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('cuenta/recuperar', []);
    }

    public function recuperarProcesar(Peticion $peticion): Respuesta
    {
        $correo = mb_strtolower(trim((string) ($peticion->formulario['correo'] ?? '')));
        $comprador = $this->compradores->porCorreo($correo);

        // Mismo mensaje exista o no la cuenta — no se puede enumerar correos
        // desde este formulario, igual que el login. El enlace se crea
        // siempre que la cuenta exista; el correo es un mejor esfuerzo aparte
        // (si SMTP no está configurado, el enlace igual queda listo).
        if ($comprador !== null) {
            $token = $this->enlaces->crear('reset_password', $comprador->id, null, self::MINUTOS_RESET);

            if ($this->smtp !== null) {
                $url = rtrim($this->urlBase, '/') . '/recuperar/confirmar?token=' . $token;

                $this->smtp->enviar(
                    $comprador->correo,
                    'Recuperar su contraseña',
                    "Hola {$comprador->nombreCompleto()},\n\nUse este enlace para elegir una contraseña nueva:\n{$url}\n\n"
                        . "Este enlace es válido por 2 horas. Si no pidió esto, ignore el correo.\n",
                );
            }
        }

        return Respuesta::vista('cuenta/recuperar_enviado', []);
    }

    public function recuperarConfirmarMostrar(Peticion $peticion): Respuesta
    {
        $token = (string) ($peticion->consulta['token'] ?? '');

        if ($this->enlaces->vigente($token, 'reset_password') === null) {
            return Respuesta::vista('cuenta/enlace_invalido', [], 410);
        }

        return Respuesta::vista('cuenta/recuperar_confirmar', [
            'token' => $token,
            'error' => $peticion->consulta['error'] ?? null,
        ]);
    }

    public function recuperarConfirmarProcesar(Peticion $peticion): Respuesta
    {
        $token = (string) ($peticion->formulario['token'] ?? '');
        $enlace = $this->enlaces->vigente($token, 'reset_password');

        if ($enlace === null || $enlace['comprador_id'] === null) {
            return Respuesta::vista('cuenta/enlace_invalido', [], 410);
        }

        $password = (string) ($peticion->formulario['password'] ?? '');

        if (mb_strlen($password) < 8) {
            return new Respuesta('', 302, [
                'Location' => '/recuperar/confirmar?' . http_build_query([
                    'token' => $token,
                    'error' => 'La contraseña debe tener al menos 8 caracteres.',
                ]),
            ]);
        }

        $this->compradores->cambiarPassword($enlace['comprador_id'], $password);
        $this->enlaces->marcarUsado($enlace['id']);

        // Rotación al cambiar contraseña, misma disciplina que
        // Autenticacion::cambiarPassword() para el panel: si la clave se
        // filtró, una sesión ya abierta con la clave vieja no debe seguir viva.
        $this->sesiones->revocarTodas($enlace['comprador_id']);

        return new Respuesta('', 302, ['Location' => '/entrar']);
    }

    /** @return array{token:string,correo:string,compraId:string,enlaceId:string}|null */
    private function resolverEnlaceCompletar(string $token): ?array
    {
        $enlace = $this->enlaces->vigente($token, 'completar_registro');
        if ($enlace === null || $enlace['compra_id'] === null) {
            return null;
        }

        $compra = $this->compras->porId($enlace['compra_id']);
        if ($compra === null) {
            return null;
        }

        return [
            'token' => $token,
            'correo' => (string) $compra['correo'],
            'compraId' => (string) $compra['id'],
            'enlaceId' => $enlace['id'],
        ];
    }

    private function redirigirCompletar(string $token, string $error): Respuesta
    {
        return new Respuesta('', 302, [
            'Location' => '/mis-cursos/completar?' . http_build_query(['token' => $token, 'error' => $error]),
        ]);
    }

    private function conCookieDeSesion(string $token, string $destino): Respuesta
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => $this->seguro(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return new Respuesta('', 302, ['Location' => $destino]);
    }

    private function borrarCookieDeSesion(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->seguro(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function seguro(): bool
    {
        return (Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo';
    }
}
