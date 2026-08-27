<?php

declare(strict_types=1);

use App\Soporte\Vista;

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Revise su correo</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">
<main class="mx-auto max-w-lg px-5 py-24 text-center md:px-7">
    <h1 class="titular-seccion">Revise su correo</h1>
    <p class="mt-4 text-acero">Si ese correo tiene una cuenta, le llegará un enlace para elegir una contraseña nueva.</p>
</main>
</body>
</html>
