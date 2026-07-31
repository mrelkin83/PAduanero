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
<title>Verificación · Panel</title>
<style><?= $css ?></style>
</head>
<body class="flex min-h-screen items-center justify-center bg-tinta px-5">

<div class="w-full max-w-sm">
    <p class="font-mono text-xs tracking-widest text-ambar">SEGUNDO PASO</p>
    <h1 class="mt-1 text-xl font-bold text-papel">Código de verificación</h1>

    <form method="post" action="/panel/verificar" class="tarjeta mt-6 space-y-4 p-6">
        <?= $ctx->csrf->campoOculto() ?>

        <?php if ($error !== null): ?>
            <p class="aviso aviso-error"><?= $e($error) ?></p>
        <?php endif; ?>

        <div>
            <label for="codigo" class="rotulo">Código de 6 dígitos</label>
            <input id="codigo" name="codigo" type="text" required autofocus
                   inputmode="numeric" pattern="[0-9]*" maxlength="6"
                   autocomplete="one-time-code"
                   class="campo mt-1 text-center font-mono text-2xl tracking-[0.4em]">
        </div>

        <button type="submit" class="boton w-full">Verificar</button>
    </form>

    <p class="mt-4 text-xs text-tinta-suave">
        El código cambia cada 30 segundos. Si no coincide, revise que la hora
        del teléfono esté sincronizada.
    </p>
</div>

</body>
</html>
