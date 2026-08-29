<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var list<array{id:string,titulo:string,lecciones:list<array<string,mixed>>}> $modulos
 * @var array{vistas:int,total:int} $progreso
 * @var bool $completo
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e((string) $curso['titulo']) ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/mis-cursos" class="menu-enlace">← Mis cursos</a>
    </div>
</header>

<main class="mx-auto max-w-3xl px-5 py-12 md:px-7">
    <h1 class="titular-seccion"><?= $e((string) $curso['titulo']) ?></h1>

    <?php if ($completo): ?>
    <a href="/mis-cursos/<?= $e((string) $curso['slug']) ?>/certificado" class="boton-diagnostico-global mt-4 inline-block">
        Descargar certificado
    </a>
    <?php else: ?>
    <p class="mt-2 text-sm text-acero"><?= $e((string) $progreso['vistas']) ?> de <?= $e((string) $progreso['total']) ?> lecciones vistas</p>
    <?php endif; ?>

    <?php foreach ($modulos as $modulo): ?>
    <section class="doble-bisel mt-6 p-4">
        <h2 class="font-semibold"><?= $e((string) $modulo['titulo']) ?></h2>
        <ul class="mt-2 space-y-1 text-sm">
            <?php foreach ($modulo['lecciones'] as $leccion): ?>
            <li>
                <a href="/mis-cursos/<?= $e((string) $curso['slug']) ?>/leccion/<?= $e((string) $leccion['id']) ?>"
                   class="menu-enlace">
                    <?= $e((string) $leccion['titulo']) ?>
                    <?= $leccion['duracion_min'] !== null ? ' · ' . $e((string) $leccion['duracion_min']) . ' min' : '' ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
</main>

</body>
</html>
