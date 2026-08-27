<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$csrf = new Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar contraseña</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <h1 class="titular-seccion">Recuperar contraseña</h1>
    <p class="mt-4 text-acero">Escriba su correo y le enviaremos un enlace para elegir una contraseña nueva.</p>

    <form method="post" action="/recuperar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>
        <label class="text-xs uppercase tracking-widest text-acero">Correo</label>
        <input name="correo" type="email" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">Enviar enlace</button>
    </form>
</main>

</body>
</html>
