<?php

declare(strict_types=1);

/**
 * Genera las variantes responsivas de public/img/ en AVIF y WebP.
 *
 *   php bin/optimizar-imagenes.php
 *
 * Se corre cuando Pedro sube una foto nueva, no en cada despliegue. Las
 * variantes se versionan en el repositorio: son parte del sitio, y
 * regenerarlas en el servidor obligaría a tener GD con AVIF en el VPS.
 *
 * Por qué importa: el criterio de cierre de la Etapa 1 pide peso inicial
 * < 300 KB y LCP < 2 s. Los JPEG originales pesan ~95 KB cada uno a 890 px;
 * en AVIF el mismo recorte baja a la quinta parte, y en móvil ni siquiera se
 * descarga el ancho grande.
 */

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const ANCHOS = [400, 640, 890];
const CALIDAD_AVIF = 55;   // AVIF aguanta calidades bajas sin artefactos visibles
const CALIDAD_WEBP = 78;

if (!function_exists('imagewebp')) {
    fwrite(STDERR, "ERROR: GD sin soporte WebP.\n");
    exit(1);
}

$hayAvif = function_exists('imageavif');
if (!$hayAvif) {
    fwrite(STDERR, "AVISO: GD sin soporte AVIF. Se generará solo WebP.\n");
}

$directorio = $raiz . '/public/img';
$originales = glob($directorio . '/*.jpg') ?: [];

if ($originales === []) {
    fwrite(STDERR, "No hay JPEG en {$directorio}.\n");
    exit(1);
}

$totalAntes = 0;
$totalDespues = 0;

foreach ($originales as $ruta) {
    $nombre = pathinfo($ruta, PATHINFO_FILENAME);

    // Las variantes también terminan en .jpg no: se distinguen por el sufijo
    // de ancho. Saltarlas evita generar variantes de variantes.
    if (preg_match('/-\d+$/', $nombre) === 1) {
        continue;
    }

    $original = imagecreatefromjpeg($ruta);
    if ($original === false) {
        fwrite(STDERR, "No se pudo leer {$ruta}\n");
        continue;
    }

    $anchoOriginal = imagesx($original);
    $altoOriginal = imagesy($original);
    $totalAntes += filesize($ruta);

    printf("%s  (%d x %d)\n", basename($ruta), $anchoOriginal, $altoOriginal);

    foreach (ANCHOS as $ancho) {
        // Nunca ampliar: agrandar una foto solo suma bytes y borrosidad.
        $destinoAncho = min($ancho, $anchoOriginal);
        $destinoAlto = (int) round($destinoAncho * $altoOriginal / $anchoOriginal);

        $escalada = imagescale($original, $destinoAncho, $destinoAlto, IMG_BICUBIC_FIXED);
        if ($escalada === false) {
            continue;
        }

        if ($hayAvif) {
            $salida = "{$directorio}/{$nombre}-{$ancho}.avif";
            imageavif($escalada, $salida, CALIDAD_AVIF);
            $totalDespues += filesize($salida);
            printf("  → %-28s %6.1f KB\n", basename($salida), filesize($salida) / 1024);
        }

        $salida = "{$directorio}/{$nombre}-{$ancho}.webp";
        imagewebp($escalada, $salida, CALIDAD_WEBP);
        printf("  → %-28s %6.1f KB\n", basename($salida), filesize($salida) / 1024);

        imagedestroy($escalada);
    }

    imagedestroy($original);
    echo PHP_EOL;
}

printf(
    "JPEG originales: %.1f KB · variantes AVIF: %.1f KB\n",
    $totalAntes / 1024,
    $totalDespues / 1024,
);
