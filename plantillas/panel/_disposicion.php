<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Marco del panel. Lo incluyen todas las pantallas autenticadas.
 *
 * @var \App\Panel\Contexto $ctx
 * @var string              $titulo
 * @var array{ok:string,error:string} $avisos
 * @var callable            $contenido
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/panel.css') ?: '';
$rutaActual = $ctx->peticion->ruta;

$menu = [
    ['/panel', 'Tablero', 'tablero.ver'],
    ['/panel/tarifas', 'Agenda y tarifas', 'agenda.ver'],
    ['/panel/pagos', 'Pagos', 'pagos.transacciones.ver'],
    ['/panel/configuracion', 'Configuración', 'config.ver'],
    ['/panel/usuarios', 'Usuarios', 'usuarios.ver'],
    ['/panel/auditoria', 'Bitácora', 'auditoria.ver'],
];
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($titulo) ?> · Panel</title>
<link rel="icon" href="/img/icono.svg" type="image/svg+xml">
<style><?= $css ?></style>
</head>
<body class="min-h-screen">

<div class="flex min-h-screen flex-col md:flex-row">

    <aside class="bg-tinta text-papel md:w-56 md:shrink-0">
        <div class="px-4 py-5">
            <p class="font-mono text-xs tracking-widest text-ambar">PANEL</p>
            <p class="mt-1 text-sm font-semibold">Pedro · Aduanero</p>
        </div>

        <nav class="px-2 pb-4">
            <?php foreach ($menu as [$ruta, $etiqueta, $permiso]): ?>
                <?php if (!$ctx->puede($permiso)) { continue; } ?>
                <a href="<?= $e($ruta) ?>"
                   class="nav-enlace <?= $rutaActual === $ruta ? 'nav-activo' : '' ?>">
                    <?= $e($etiqueta) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="mt-auto px-4 py-4 text-xs text-tinta-suave">
            <p class="truncate"><?= $e($ctx->usuario?->nombre ?? '') ?></p>
            <p class="font-mono"><?= $e($ctx->usuario?->rol ?? '') ?></p>

            <a href="/panel/seguridad" class="mt-2 block underline">Seguridad de mi cuenta</a>

            <form method="post" action="/panel/salir" class="mt-2">
                <?= $ctx->csrf->campoOculto() ?>
                <button type="submit" class="underline">Cerrar sesión</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 px-5 py-7 md:px-8">
        <h1 class="text-2xl font-bold tracking-tight"><?= $e($titulo) ?></h1>

        <?php if (($avisos['ok'] ?? '') !== ''): ?>
            <p class="aviso aviso-ok mt-4"><?= $e($avisos['ok']) ?></p>
        <?php endif; ?>
        <?php if (($avisos['error'] ?? '') !== ''): ?>
            <p class="aviso aviso-error mt-4"><?= $e($avisos['error']) ?></p>
        <?php endif; ?>

        <div class="mt-6 max-w-5xl">
            <?php $contenido(); ?>
        </div>

        <?php
        /* Aviso de uso de Evolution API.
           Lo exige la cláusula 1.b de su LICENSE: notificación clara, visible
           para los administradores del sistema y accesible desde la página de
           ajustes. Va aquí y no en la landing porque es a los administradores
           a quienes debe verse (CLAUDE.md §1.3). No se quita. */
        ?>
        <footer class="mt-14 border-t border-acero/20 pt-4 text-xs text-acero">
            <p>
                Este sistema utiliza <strong>Evolution API</strong> para la integración con WhatsApp
                (Apache 2.0, © Evolution API) y <strong>Chatwoot</strong> Community Edition
                (MIT) como bandeja omnicanal.
            </p>
        </footer>
    </main>
</div>

</body>
</html>
