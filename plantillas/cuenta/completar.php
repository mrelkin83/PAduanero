<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

/**
 * @var string $token
 * @var string $correo
 * @var bool $existeCuenta
 * @var ?string $error
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$csrf = new Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $existeCuenta ? 'Iniciar sesión' : 'Completar registro' ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <h1 class="titular-seccion">
        <?= $existeCuenta ? 'Ya tiene una cuenta' : 'Complete su registro' ?>
    </h1>
    <p class="mt-4 text-acero">
        <?= $existeCuenta
            ? 'Inicie sesión para ver su curso.'
            : 'Su pago fue confirmado. Cree su contraseña para acceder.' ?>
    </p>

    <?php if ($error !== null): ?>
    <p class="mt-4 rounded border border-alerta/40 bg-alerta/10 p-3 text-sm text-alerta"><?= $e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/mis-cursos/completar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>
        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <input type="hidden" name="modo" value="<?= $existeCuenta ? 'login' : 'registro' ?>">

        <label class="text-xs uppercase tracking-widest text-acero">Correo</label>
        <input value="<?= $e($correo) ?>" disabled class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-acero">

        <?php if (!$existeCuenta): ?>
        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Nombres</label>
        <input name="nombres" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Apellidos</label>
        <input name="apellidos" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Tipo de documento</label>
        <select name="tipo_documento" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">
            <option value="CC">Cédula de ciudadanía</option>
            <option value="CE">Cédula de extranjería</option>
            <option value="PASAPORTE">Pasaporte</option>
            <option value="NIT">NIT</option>
        </select>

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Número de documento</label>
        <input name="numero_documento" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Celular</label>
        <input name="celular" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">
        <?php endif; ?>

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Contraseña</label>
        <input name="password" type="password" required minlength="8" class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">
            <?= $existeCuenta ? 'Entrar' : 'Crear mi cuenta' ?>
        </button>
    </form>
</main>

</body>
</html>
