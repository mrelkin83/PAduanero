<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var ?string $error
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$csrf = new Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
$precio = '$' . number_format((int) $curso['precio_cop'], 0, ',', '.') . ' COP';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprar: <?= $e((string) $curso['titulo']) ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <p class="text-xs uppercase tracking-widest text-acero">Comprar curso</p>
    <h1 class="titular-seccion mt-2"><?= $e((string) $curso['titulo']) ?></h1>
    <p class="mt-4 font-mono text-2xl text-oro"><?= $e($precio) ?></p>

    <?php if ($error !== null): ?>
    <p class="mt-4 rounded border border-alerta/40 bg-alerta/10 p-3 text-sm text-alerta"><?= $e((string) $error) ?></p>
    <?php endif; ?>

    <form method="post" action="/cursos/<?= $e((string) $curso['slug']) ?>/comprar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>

        <label class="text-xs uppercase tracking-widest text-acero">Nombre completo</label>
        <input name="nombre" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Correo</label>
        <input name="correo" type="email" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">
            Pagar <?= $e($precio) ?> con Wompi
        </button>
    </form>
</main>

</body>
</html>
