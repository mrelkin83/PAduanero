<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var string|null         $error
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/panel.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar · Panel</title>
<link rel="icon" href="/img/icono.svg" type="image/svg+xml">
<style><?= $css ?></style>
</head>
<body class="flex min-h-screen items-center justify-center bg-tinta px-5">

<div class="w-full max-w-sm">
    <p class="font-mono text-xs tracking-widest text-ambar">PANEL</p>
    <h1 class="mt-1 text-xl font-bold text-papel">Pedro · Abogado aduanero</h1>

    <form method="post" action="/panel/entrar" class="tarjeta mt-6 space-y-4 p-6">
        <?= $ctx->csrf->campoOculto() ?>

        <?php if ($error !== null): ?>
            <p class="aviso aviso-error"><?= $e($error) ?></p>
        <?php endif; ?>

        <div>
            <label for="email" class="rotulo">Correo</label>
            <input id="email" name="email" type="email" required autofocus
                   autocomplete="username" class="campo mt-1">
        </div>

        <div>
            <label for="password" class="rotulo">Contraseña</label>
            <input id="password" name="password" type="password" required
                   autocomplete="current-password" class="campo mt-1">
        </div>

        <button type="submit" class="boton w-full">Entrar</button>
    </form>

    <p class="mt-4 text-xs text-tinta-suave">
        Los accesos quedan registrados en la bitácora.
    </p>
</div>

</body>
</html>
