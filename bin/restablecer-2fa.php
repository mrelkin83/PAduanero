<?php

declare(strict_types=1);

/**
 * PROCEDIMIENTO DE EMERGENCIA — restablecer el segundo factor.
 *
 *   php bin/restablecer-2fa.php pedro@ejemplo.com
 *
 * Para cuando alguien pierde el teléfono, lo cambia sin migrar la aplicación
 * de autenticación, o esta se borra. Sin esto, el titular del despacho queda
 * fuera del panel de su propio negocio y la única salida es editar la base de
 * producción a mano — que es exactamente lo que el segundo factor existe para
 * evitar.
 *
 * POR QUÉ ES DEFENDIBLE QUE ESTO EXISTA. Quien puede ejecutar este comando ya
 * tiene shell en el VPS, y con shell ya tiene la `MASTER_KEY`, la base y todo
 * lo demás: su privilegio es máximo con o sin este archivo. No añade
 * superficie de ataque, solo hace usable una recuperación que de otro modo se
 * haría con un UPDATE improvisado a las tres de la mañana, sin registro y con
 * el riesgo de tocar la fila equivocada.
 *
 * Qué hace, y por qué cada parte:
 *   · borra el secreto TOTP y el contador
 *   · REVOCA TODAS LAS SESIONES del usuario — si el teléfono se perdió, una
 *     sesión abierta en ese teléfono sigue abierta
 *   · deja constancia en `auditoria` con quién lo ejecutó y por qué
 *
 * Lo que NO hace: no cambia la contraseña ni entra a ninguna cuenta. Tras
 * ejecutarlo, el usuario entra con su contraseña de siempre y el panel le
 * obliga a configurar el segundo factor otra vez.
 */

use App\Core\BD;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Repositorios\SesionRepo;
use App\Repositorios\UsuarioRepo;
use App\Servicios\Autenticacion;
use App\Soporte\Cifrado;
use App\Soporte\Entorno;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

Entorno::cargar($raiz . '/.env');

$email = $argv[1] ?? '';

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php bin/restablecer-2fa.php <correo>\n");
    exit(1);
}

try {
    $bd = BD::desdeEntorno();
    $usuarios = new UsuarioRepo($bd, Cifrado::desdeEntorno());

    $usuario = $usuarios->porEmail($email);

    if ($usuario === null) {
        fwrite(STDERR, "No existe un usuario con el correo {$email}.\n");
        exit(1);
    }

    echo PHP_EOL;
    echo "Usuario:  {$usuario->nombre} <{$usuario->email}>" . PHP_EOL;
    echo "Rol:      {$usuario->rol}" . PHP_EOL;
    echo '2FA:      ' . ($usuario->totpActivo ? 'activa' : 'no activa') . PHP_EOL;
    echo PHP_EOL;

    if (!$usuario->totpActivo) {
        echo 'Este usuario no tiene el segundo factor activo. No hay nada que restablecer.' . PHP_EOL;
        exit(0);
    }

    echo 'Se borrará su segundo factor y se cerrarán TODAS sus sesiones.' . PHP_EOL;
    echo 'Tendrá que configurarlo de nuevo al entrar.' . PHP_EOL . PHP_EOL;

    // Confirmación escribiendo el correo completo, no un «s/n»: es un
    // comando destructivo sobre una cuenta concreta y confundirse de usuario
    // en un listado es fácil.
    echo "Para confirmar, escriba el correo completo: ";
    $confirmacion = trim((string) fgets(STDIN));

    if ($confirmacion !== $usuario->email) {
        echo 'No coincide. No se hizo ningún cambio.' . PHP_EOL;
        exit(1);
    }

    echo 'Motivo (queda en la bitácora): ';
    $motivo = trim((string) fgets(STDIN));

    $auth = new Autenticacion(
        $usuarios,
        new SesionRepo($bd),
        new IntentoAccesoRepo($bd),
        new AuditoriaRepo($bd),
    );

    // El actor es quien tiene la shell. No hay usuario autenticado que
    // registrar, así que se anota el usuario del sistema operativo: es lo
    // único cierto y sirve para cruzarlo con los accesos SSH.
    $actor = 'consola:' . (getenv('SUDO_USER') ?: (getenv('USER') ?: (getenv('USERNAME') ?: 'desconocido')));

    $auth->restablecerTotp($usuario, $actor, $motivo !== '' ? $motivo : null);

    echo PHP_EOL;
    echo "Listo. {$usuario->email} debe entrar con su contraseña y volver a" . PHP_EOL;
    echo 'configurar el segundo factor. Queda registrado en la bitácora.' . PHP_EOL;

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
