<?php

declare(strict_types=1);

use App\Soporte\Vista;

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificar certificado</title>
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-24 md:px-7">
    <h1 class="titular-seccion">Verificar un certificado</h1>
    <p class="mt-4 text-acero">Escriba el código de verificación impreso en el certificado.</p>

    <form method="get" action="" class="mt-6" onsubmit="event.preventDefault(); window.location = '/certificados/verificar/' + encodeURIComponent(this.codigo.value.trim());">
        <input name="codigo" placeholder="PA-XXXXXXXX" class="campo" required>
        <button type="submit" class="boton-diagnostico-global mt-3">Verificar</button>
    </form>
</main>

</body>
</html>
