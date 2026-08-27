<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

/**
 * @var \App\Modelos\Comprador $comprador
 * @var list<array<string,mixed>> $cursos
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
<title>Mis cursos</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/" class="flex shrink-0 items-center" aria-label="Pedro, abogado aduanero">
            <img src="/img/logo-pedro.png" alt="" width="40" height="40" class="h-10 w-10" decoding="async">
            <span class="sr-only">Pedro</span>
        </a>
        <p class="ml-auto text-sm text-acero"><?= $e($comprador->nombreCompleto()) ?></p>
        <form method="post" action="/salir">
            <?= $csrf->campoOculto() ?>
            <button type="submit" class="menu-enlace">Salir</button>
        </form>
    </div>
</header>

<main class="mx-auto max-w-3xl px-5 py-12 md:px-7">
    <h1 class="titular-seccion">Mis cursos</h1>

    <?php if ($cursos === []): ?>
    <p class="mt-6 text-acero">Todavía no tiene cursos comprados.</p>
    <?php else: ?>
    <div class="mt-6 grid gap-4">
        <?php foreach ($cursos as $curso): ?>
        <a href="/cursos/<?= $e((string) $curso['slug']) ?>" class="doble-bisel block p-5">
            <h2 class="text-lg font-semibold"><?= $e((string) $curso['titulo']) ?></h2>
            <p class="mt-2 text-sm text-acero">
                Comprado el <?= $e(substr((string) $curso['pagado_en'], 0, 10)) ?>
            </p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>
