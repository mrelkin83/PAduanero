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
 * @var callable|null       $scripts  opcional, se pinta antes de </body>
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/panel.css') ?: '';
$rutaActual = $ctx->peticion->ruta;

// Se retiraron «Pagos», «Modelos de IA», «Prompts del bot» y «Conocimiento»
// junto con el motor y la pasarela. «Agenda y tarifas» se queda a medias a
// propósito: la agenda ya no existe, pero el precio sigue alimentando el
// copy de la landing y del diagnóstico, así que la pantalla sobrevive por
// la tarifa.
$menu = [
    ['/panel', 'Tablero', 'tablero.ver'],
    ['/panel/contenido', 'Contenido', 'contenido.editar'],
    ['/panel/tarifas', 'Tarifas', 'agenda.ver'],
    ['/panel/whatsapp', 'WhatsApp', 'ia.proveedores.ver'],
    ['/panel/whatsapp/citas', 'Citas', 'agenda.ver'],
    ['/panel/whatsapp/conversaciones', 'Conversaciones', 'casos.ver'],
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
        /* Aquí iba el aviso de uso de Evolution API, que exigía la cláusula
           1.b de su LICENSE: notificación clara y visible para los
           administradores. Se retira porque la obligación se extingue con el
           uso — este sistema ya no integra Evolution ni Chatwoot, y dejar el
           aviso sería afirmar algo que no es cierto.

           Si algún día vuelve la bandeja omnicanal, el aviso vuelve con
           ella: es condición de la licencia, no cortesía. */
        ?>
    </main>
</div>

<?php
/* Los formularios con datos guardados se pintan bloqueados y un botón
   «Editar» los habilita. Es una guarda contra el cambio accidental: en estas
   pantallas un tecleo distraído acaba en la landing o apaga el bot, y el
   clic previo convierte cada edición en una decisión.

   Reglas del bloqueo, todas deliberadas:
   · Solo formularios POST dentro de <main> — quedan fuera el cierre de
     sesión (está en el aside) y los filtros (van por GET).
   · Solo si tienen algún campo visible con valor inicial: un formulario
     vacío (crear usuario, código 2FA, canjear el código de Google) se llena
     de una vez, sin clic previo. Las contraseñas y credenciales nunca se
     reflejan al navegador, así que no cuentan como «valor guardado».
   · Un formulario de solo botones (apagar, generar QR, reanudar) no se toca:
     no hay campos que proteger y bloquearlo solo añadiría un clic.
   · `data-sin-bloqueo` en el <form> lo exime, para la pantalla que llegue a
     necesitarlo. */
?>
<script>
(function () {
    'use strict';

    document.querySelectorAll('main form[method="post"]:not([data-sin-bloqueo])').forEach(function (forma) {
        var campos = Array.prototype.filter.call(
            forma.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea'),
            function (campo) { return !campo.disabled; }
        );
        if (campos.length === 0) { return; }

        var envios = forma.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
        if (envios.length === 0) { return; }

        var guardada = campos.some(function (campo) {
            if (campo.tagName === 'SELECT') { return campo.querySelector('option[selected]') !== null; }
            if (campo.type === 'checkbox' || campo.type === 'radio') { return campo.defaultChecked; }
            if (campo.type === 'password' || campo.type === 'file') { return false; }
            return (campo.defaultValue || '').trim() !== '';
        });
        if (!guardada) { return; }

        campos.forEach(function (campo) { campo.disabled = true; });
        envios.forEach(function (boton) { boton.disabled = true; });

        var editar = document.createElement('button');
        editar.type = 'button';
        editar.textContent = 'Editar';
        editar.className = 'boton boton-secundario';

        editar.addEventListener('click', function () {
            campos.forEach(function (campo) { campo.disabled = false; });
            envios.forEach(function (boton) { boton.disabled = false; });
            editar.remove();
            campos[0].focus();
        });

        envios[0].insertAdjacentElement('afterend', editar);
    });
})();
</script>

<?php
/* Script de la pantalla, si lo tiene. Va en línea y al final del cuerpo: el
   panel no carga JS de terceros ni desde CDN, y así no hay una petición más
   que bloquee el pintado. */
?>
<?php if (isset($scripts) && is_callable($scripts)): ?>
<script><?php $scripts(); ?></script>
<?php endif; ?>

</body>
</html>
