<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

/**
 * @var string $token
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
<title>Elegir nueva contraseña</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <h1 class="titular-seccion">Elegir nueva contraseña</h1>

    <?php if ($error !== null): ?>
    <p class="mt-4 rounded border border-alerta/40 bg-alerta/10 p-3 text-sm text-alerta"><?= $e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/recuperar/confirmar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>
        <input type="hidden" name="token" value="<?= $e($token) ?>">

        <label class="text-xs uppercase tracking-widest text-acero">Nueva contraseña</label>
        <input name="password" type="password" required minlength="8" class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">Guardar</button>
    </form>
</main>

</body>
</html>
