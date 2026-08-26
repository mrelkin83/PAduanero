<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var list<array<string,mixed>> $cursos
 * @var list<array{id:string,nombre:string,slug:string}> $categorias
 * @var ?string $categoriaActual
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string} $meta
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
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
        <h1 class="ml-auto text-lg font-semibold">Cursos</h1>
    </div>
</header>

<main class="mx-auto max-w-5xl px-5 py-12 md:px-7">
    <?php if ($categorias !== []): ?>
    <nav aria-label="Categorías" class="mb-8 flex flex-wrap gap-3">
        <a href="/cursos" class="menu-enlace" <?= $categoriaActual === null ? 'data-activo' : '' ?>>Todos</a>
        <?php foreach ($categorias as $cat): ?>
        <a href="/cursos?categoria=<?= $e((string) $cat['slug']) ?>"
           class="menu-enlace" <?= $categoriaActual === $cat['slug'] ? 'data-activo' : '' ?>>
            <?= $e((string) $cat['nombre']) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php if ($cursos === []): ?>
    <p class="text-acero">Todavía no hay cursos publicados.</p>
    <?php else: ?>
    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($cursos as $curso): ?>
        <a href="/cursos/<?= $e((string) $curso['slug']) ?>" class="doble-bisel block p-5">
            <?php if (!empty($curso['imagen_portada'])): ?>
            <img src="/img/cursos/<?= $e((string) $curso['imagen_portada']) ?>"
                 alt="<?= $e((string) $curso['titulo']) ?>"
                 class="mb-4 aspect-video w-full rounded object-cover" loading="lazy" decoding="async">
            <?php endif; ?>
            <p class="text-xs uppercase tracking-widest text-acero"><?= $e((string) $curso['categoria_nombre']) ?></p>
            <h2 class="mt-2 text-lg font-semibold"><?= $e((string) $curso['titulo']) ?></h2>
            <p class="mt-2 text-sm text-acero"><?= $e((string) $curso['resumen']) ?></p>
            <p class="mt-4 font-mono text-oro">
                $<?= $e(number_format((int) $curso['precio_cop'], 0, ',', '.')) ?> COP
            </p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>
