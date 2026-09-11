<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var string $tipo      'completar_registro' o 'reset_password'
 * @var string $whatsapp  Número del negocio, de `configuraciones`
 *
 * El número venía escrito a mano aquí, y no era el que tiene la
 * configuración: a quien se le vencía el enlace de su curso se le ofrecía
 * escribir a un teléfono por el que el despacho no responde. No fallaba —
 * abría WhatsApp como cualquier otro enlace—, que es la razón de que durara.
 * Sale de `configuraciones.whatsapp_numero_negocio`, y si está vacío no se
 * ofrece enlace: es mejor que ofrecer uno inventado.
 */

$tipo = $tipo ?? 'reset_password';
$whatsapp = trim((string) ($whatsapp ?? ''));
$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Enlace vencido</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">
<main class="mx-auto max-w-lg px-5 py-24 text-center md:px-7">
    <h1 class="titular-seccion">Este enlace ya no es válido</h1>
    <p class="mt-4 text-acero">Puede que ya lo haya usado o que haya vencido.</p>
    <?php if ($tipo === 'completar_registro' && $whatsapp !== ''): ?>
    <p class="mt-2 text-acero">Escríbanos por WhatsApp y le reenviamos el acceso a su curso.</p>
    <a href="https://wa.me/<?= $e(rawurlencode($whatsapp)) ?>" class="menu-enlace mt-6 inline-block">Escribir por WhatsApp</a>
    <?php elseif ($tipo === 'completar_registro'): ?>
    <p class="mt-2 text-acero">Escríbanos y le reenviamos el acceso a su curso.</p>
    <?php else: ?>
    <a href="/recuperar" class="menu-enlace mt-6 inline-block">Pedir uno nuevo</a>
    <?php endif; ?>
</main>
</body>
</html>
