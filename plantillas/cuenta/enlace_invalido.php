<?php

declare(strict_types=1);

use App\Soporte\Vista;

/** @var string $tipo 'completar_registro' o 'reset_password' */

$tipo = $tipo ?? 'reset_password';
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
    <?php if ($tipo === 'completar_registro'): ?>
    <p class="mt-2 text-acero">Escríbanos por WhatsApp y le reenviamos el acceso a su curso.</p>
    <a href="https://wa.me/573159923676" class="menu-enlace mt-6 inline-block">Escribir por WhatsApp</a>
    <?php else: ?>
    <a href="/recuperar" class="menu-enlace mt-6 inline-block">Pedir uno nuevo</a>
    <?php endif; ?>
</main>
</body>
</html>
