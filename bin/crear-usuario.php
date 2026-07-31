<?php

declare(strict_types=1);

/**
 * Alta del primer usuario. Se corre una vez, por consola.
 *
 *   php bin/crear-usuario.php
 *
 * El panel no puede crear su propio primer super_admin: nadie podría entrar a
 * usarlo. De ahí en adelante las altas van por el panel, que además
 * aprovisiona el agente en Chatwoot (docs/PANEL_ADMIN.md §2.9).
 *
 * La contraseña se pide por stdin con el eco apagado donde el sistema lo
 * permite: pasarla como argumento la dejaría en el historial del shell y en
 * la lista de procesos.
 */

use App\Core\BD;
use App\Soporte\Entorno;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

Entorno::cargar($raiz . '/.env');

function preguntar(string $etiqueta): string
{
    echo $etiqueta;

    return trim((string) fgets(STDIN));
}

function preguntarSecreto(string $etiqueta): string
{
    echo $etiqueta;

    // `stty -echo` no existe en Windows; allí se avisa en vez de fingir.
    $puedeOcultar = DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec');

    if ($puedeOcultar) {
        shell_exec('stty -echo 2>/dev/null');
    } else {
        echo '(visible en pantalla) ';
    }

    $valor = trim((string) fgets(STDIN));

    if ($puedeOcultar) {
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
    }

    return $valor;
}

try {
    $bd = BD::desdeEntorno();
    $pdo = $bd->pdo();

    $roles = $pdo->query('SELECT id, clave, nombre FROM roles ORDER BY id')->fetchAll();

    if ($roles === []) {
        throw new RuntimeException(
            'No hay roles en la base. Correr antes: php bin/migrar.php'
        );
    }

    echo 'Alta de usuario del panel' . PHP_EOL . PHP_EOL;

    $email = preguntar('Correo: ');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Ese correo no es válido.');
    }

    $nombre = preguntar('Nombre completo: ');
    if ($nombre === '') {
        throw new RuntimeException('El nombre no puede estar vacío.');
    }

    echo PHP_EOL . 'Roles disponibles:' . PHP_EOL;
    foreach ($roles as $rol) {
        echo "  {$rol['id']}) {$rol['clave']} — {$rol['nombre']}" . PHP_EOL;
    }

    $rolId = (int) preguntar('Id del rol: ');
    $claves = array_column($roles, 'clave', 'id');
    if (!isset($claves[$rolId])) {
        throw new RuntimeException('Ese rol no existe.');
    }

    echo PHP_EOL;
    $password = preguntarSecreto('Contraseña: ');

    // 12 caracteres es el mínimo razonable para una cuenta que ve
    // credenciales de pasarela de pagos.
    if (strlen($password) < 12) {
        throw new RuntimeException('La contraseña debe tener al menos 12 caracteres.');
    }

    if ($password !== preguntarSecreto('Repetir contraseña: ')) {
        throw new RuntimeException('Las contraseñas no coinciden.');
    }

    $hash = password_hash($password, PASSWORD_ARGON2ID);
    if ($hash === false) {
        throw new RuntimeException('No se pudo generar el hash Argon2id.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (email, nombre, password_hash, rol_id) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$email, $nombre, $hash, $rolId]);

    $pdo->prepare(
        'INSERT INTO auditoria (entidad, accion, actor, detalle) VALUES (?, ?, ?, ?)'
    )->execute([
        'usuario',
        'crear',
        'consola',
        json_encode(['rol' => $claves[$rolId]], JSON_UNESCAPED_UNICODE),
    ]);

    echo PHP_EOL . "Usuario creado con rol «{$claves[$rolId]}»." . PHP_EOL;

    if (in_array($claves[$rolId], ['super_admin', 'abogado'], true)) {
        echo 'Recordar: este rol exige TOTP, que se activa desde el panel '
            . '(docs/PANEL_ADMIN.md §4.1).' . PHP_EOL;
    }

    exit(0);
} catch (PDOException $e) {
    fwrite(
        STDERR,
        PHP_EOL . 'ERROR: ' . (BD::esDuplicado($e)
            ? 'ya existe un usuario con ese correo.'
            : 'fallo de base de datos.') . PHP_EOL
    );
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
