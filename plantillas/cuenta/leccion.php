<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var array<string,mixed> $leccion
 * @var list<array<string,mixed>> $materiales
 * @var string|null $urlVideo
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e((string) $leccion['titulo']) ?> — <?= $e((string) $curso['titulo']) ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/mis-cursos/<?= $e((string) $curso['slug']) ?>" class="menu-enlace">← <?= $e((string) $curso['titulo']) ?></a>
    </div>
</header>

<main class="mx-auto max-w-3xl px-5 py-12 md:px-7">
    <h1 class="titular-seccion"><?= $e((string) $leccion['titulo']) ?></h1>

    <?php if ($urlVideo !== null): ?>
    <div class="mt-6 aspect-video">
        <iframe src="<?= $e($urlVideo) ?>" loading="lazy" allow="autoplay; fullscreen"
                class="h-full w-full rounded" allowfullscreen></iframe>
    </div>
    <?php elseif ($leccion['video_bunny_id'] !== null): ?>
    <p class="mt-6 text-acero">Video no disponible por ahora.</p>
    <?php endif; ?>

    <?php if (!empty($leccion['contenido_texto'])): ?>
    <div class="mt-6 space-y-4 text-acero">
        <?= Vista::parrafos((string) $leccion['contenido_texto']) ?>
    </div>
    <?php endif; ?>

    <?php if ($materiales !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Materiales</h2>
        <ul class="mt-3 space-y-2">
            <?php foreach ($materiales as $m): ?>
            <li>
                <a href="/mis-cursos/<?= $e((string) $curso['slug']) ?>/leccion/<?= $e((string) $leccion['id']) ?>/material/<?= $e((string) $m['id']) ?>"
                   class="menu-enlace">
                    <?= $e((string) $m['nombre']) ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</main>

</body>
</html>
