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
<title>Enlace vencido</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">
<main class="mx-auto max-w-lg px-5 py-24 text-center md:px-7">
    <h1 class="titular-seccion">Este enlace ya no es válido</h1>
    <p class="mt-4 text-acero">Puede que ya lo haya usado o que haya vencido.</p>
    <a href="/recuperar" class="menu-enlace mt-6 inline-block">Pedir uno nuevo</a>
</main>
</body>
</html>
