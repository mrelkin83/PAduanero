<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var string|null         $error
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/panel.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar · Panel</title>
<link rel="icon" href="/img/icono.svg" type="image/svg+xml">
<style><?= $css ?></style>
</head>
<body class="flex min-h-screen items-center justify-center bg-tinta px-5">

<div class="w-full max-w-sm">
    <p class="font-mono text-xs tracking-widest text-ambar">PANEL</p>
    <h1 class="mt-1 text-xl font-bold text-papel">Pedro · Abogado aduanero</h1>

    <form method="post" action="/panel/entrar" class="tarjeta mt-6 space-y-4 p-6">
        <?= $ctx->csrf->campoOculto() ?>

        <?php if ($error !== null): ?>
            <p class="aviso aviso-error"><?= $e($error) ?></p>
        <?php endif; ?>

        <div>
            <label for="email" class="rotulo">Correo</label>
            <input id="email" name="email" type="email" required autofocus
                   autocomplete="username" class="campo mt-1">
        </div>

        <div>
            <label for="password" class="rotulo">Contraseña</label>
            <div class="relative mt-1">
                <input id="password" name="password" type="password" required
                       autocomplete="current-password" class="campo pr-16">
                <?php /* type="button": dentro de un form, un botón sin tipo
                         envía. Solo aparece con JS (lo revela el script):
                         sin JS no puede alternar nada y sería un adorno. */ ?>
                <button type="button" id="ver-clave" hidden
                        class="absolute inset-y-0 right-0 px-3 text-xs text-acero underline"
                        aria-label="Mostrar la contraseña">ver</button>
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="recordar">
            <span>Recordar mi correo en este equipo</span>
        </label>

        <button type="submit" class="boton w-full">Entrar</button>
    </form>

    <p class="mt-4 text-xs text-tinta-suave">
        Los accesos quedan registrados en la bitácora.
    </p>
</div>

<?php /* Comodidades de sesión, todas del lado del navegador:
         · «Recordar mi correo» guarda SOLO el correo en localStorage de este
           equipo — jamás la contraseña: recordar una contraseña es trabajo
           del gestor del navegador, no de un localStorage legible por
           cualquiera con acceso al equipo.
         · «ver» alterna la visibilidad de la contraseña; existe solo con JS. */ ?>
<script>
(function () {
    'use strict';

    var CLAVE = 'panel.correo';
    var correo = document.getElementById('email');
    var recordar = document.getElementById('recordar');
    var clave = document.getElementById('password');
    var ver = document.getElementById('ver-clave');

    try {
        var guardado = localStorage.getItem(CLAVE);
        if (guardado) {
            correo.value = guardado;
            recordar.checked = true;
            clave.focus();
        }
    } catch (e) { /* almacenamiento bloqueado: la casilla queda sin memoria */ }

    document.querySelector('form').addEventListener('submit', function () {
        try {
            if (recordar.checked && correo.value !== '') {
                localStorage.setItem(CLAVE, correo.value);
            } else {
                localStorage.removeItem(CLAVE);
            }
        } catch (e) { }
    });

    ver.hidden = false;
    ver.addEventListener('click', function () {
        var oculta = clave.type === 'password';
        clave.type = oculta ? 'text' : 'password';
        ver.textContent = oculta ? 'ocultar' : 'ver';
        ver.setAttribute('aria-label', (oculta ? 'Ocultar' : 'Mostrar') + ' la contraseña');
        clave.focus();
    });
})();
</script>

</body>
</html>
