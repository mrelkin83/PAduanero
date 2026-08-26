<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var list<array{titulo:string,lecciones:list<array<string,mixed>>}> $modulos
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string} $meta
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$precio = '$' . number_format((int) $curso['precio_cop'], 0, ',', '.') . ' COP';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($meta['titulo']) ?></title>
<meta name="description" content="<?= $e($meta['descripcion']) ?>">
<?php if (!$meta['indexable']): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="canonical" href="<?= $e($meta['url']) ?>">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/" class="flex shrink-0 items-center" aria-label="Pedro, abogado aduanero">
            <img src="/img/logo-pedro.png" alt="" width="40" height="40" class="h-10 w-10" decoding="async">
            <span class="sr-only">Pedro</span>
        </a>
        <a href="/cursos" class="ml-auto menu-enlace">Todos los cursos</a>
    </div>
</header>

<main class="mx-auto max-w-3xl px-5 py-12 md:px-7">
    <?php if (!empty($curso['imagen_portada'])): ?>
    <img src="/img/cursos/<?= $e((string) $curso['imagen_portada']) ?>"
         alt="<?= $e((string) $curso['titulo']) ?>"
         class="mb-6 aspect-video w-full rounded object-cover" loading="eager" decoding="async">
    <?php endif; ?>

    <?php if ($curso['estado'] === 'borrador'): ?>
    <p class="mb-3 inline-block rounded px-2 py-1 text-xs font-semibold text-tinta bg-oro">
        Borrador — vista previa
    </p>
    <?php endif; ?>

    <p class="text-xs uppercase tracking-widest text-acero"><?= $e((string) $curso['categoria_nombre']) ?></p>
    <h1 class="titular-seccion mt-2"><?= $e((string) $curso['titulo']) ?></h1>
    <p class="mt-4 text-acero"><?= $e((string) $curso['descripcion']) ?></p>

    <?php if ($curso['lo_que_aprendera'] !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Lo que aprenderá</h2>
        <ul class="mt-3 space-y-2">
            <?php foreach ($curso['lo_que_aprendera'] as $item): ?>
            <li class="flex gap-2"><span class="text-oro">✓</span><span><?= $e((string) $item) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($modulos !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Temario</h2>
        <?php foreach ($modulos as $modulo): ?>
        <div class="doble-bisel mt-3 p-4">
            <h3 class="font-semibold"><?= $e((string) $modulo['titulo']) ?></h3>
            <ul class="mt-2 space-y-1 text-sm text-acero">
                <?php foreach ($modulo['lecciones'] as $leccion): ?>
                <li class="flex justify-between gap-4">
                    <span>
                        <?= $e((string) $leccion['titulo']) ?><?= (int) $leccion['vista_previa_gratis'] === 1 ? ' · vista previa gratis' : '' ?>
                    </span>
                    <?php if ($leccion['duracion_min'] !== null): ?>
                    <span class="font-mono"><?= $e((string) $leccion['duracion_min']) ?> min</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="doble-bisel mt-10 p-6 text-center">
        <p class="font-mono text-2xl text-oro"><?= $e($precio) ?></p>
        <a href="/cursos/<?= $e((string) $curso['slug']) ?>/comprar" class="boton-diagnostico-global mt-4 inline-block">
            Comprar
        </a>
    </section>
</main>

</body>
</html>
