<?php

declare(strict_types=1);

use App\Soporte\Vista;

/** @var array<string,mixed>|null $certificado */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificación de certificado</title>
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-24 md:px-7 text-center">
    <?php if ($certificado === null): ?>
    <h1 class="titular-seccion">No encontrado</h1>
    <p class="mt-4 text-acero">Ese código no corresponde a ningún certificado.</p>
    <?php else: ?>
    <h1 class="titular-seccion">Certificado válido</h1>
    <p class="mt-6 text-lg font-semibold"><?= $e((string) $certificado['nombres']) ?> <?= $e((string) $certificado['apellidos']) ?></p>
    <p class="mt-2 text-acero">completó el curso</p>
    <p class="mt-2 text-lg"><?= $e((string) $certificado['curso_titulo']) ?></p>
    <p class="mt-6 text-sm text-acero">Emitido el <?= $e(substr((string) $certificado['emitido_en'], 0, 10)) ?></p>
    <?php endif; ?>

    <a href="/certificados/verificar" class="menu-enlace mt-8 inline-block">Verificar otro código</a>
</main>

</body>
</html>
