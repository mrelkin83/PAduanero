<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var string $estadoMostrado
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';

$mensaje = match ($estadoMostrado) {
    'pagada', 'APPROVED' => 'Pago recibido. En unos minutos le llegará un correo para crear su acceso.',
    'fallida', 'DECLINED', 'ERROR' => 'El pago no se completó. Puede intentarlo de nuevo desde la ficha del curso.',
    default => 'Estamos confirmando su pago. Si ya pagó, en unos minutos le llegará un correo con los siguientes pasos.',
};
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gracias — <?= $e((string) $curso['titulo']) ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-24 text-center md:px-7">
    <h1 class="titular-seccion"><?= $e((string) $curso['titulo']) ?></h1>
    <p class="mt-6 text-acero"><?= $e($mensaje) ?></p>
    <a href="/cursos" class="menu-enlace mt-8 inline-block">Ver más cursos</a>
</main>

</body>
</html>
