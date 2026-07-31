<?php

declare(strict_types=1);

/**
 * Router para el servidor embebido de PHP. Solo desarrollo.
 *
 *   php -S 127.0.0.1:8000 bin/servidor-dev.php
 *
 * Existe porque `php -S host:puerto index.php` manda TODAS las peticiones al
 * front controller, incluidas /img, /css y /js, que entonces devuelven 404.
 * En producción esto lo resuelve Nginx con un `alias` (docs/RUNBOOK.md §4bis)
 * y en Laragon el .htaccess; el servidor embebido no lee ninguno de los dos.
 *
 * No se usa en producción bajo ningún concepto: sirve archivos y es de un
 * solo hilo.
 */

$raiz = dirname(__DIR__);
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$ruta = is_string($ruta) ? $ruta : '/';

// Los estáticos viven en public/ pero se piden en la raíz de la URL,
// igual que en producción.
if (preg_match('#^/(img|css|js)/#', $ruta) === 1) {
    $archivo = $raiz . '/public' . $ruta;

    // Sin esto, un `..` en la URL saca al servidor del directorio público.
    $real = realpath($archivo);
    $base = realpath($raiz . '/public');

    if ($real !== false && $base !== false && str_starts_with($real, $base) && is_file($real)) {
        $tipos = [
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'css' => 'text/css; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'ico' => 'image/x-icon',
        ];

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));

        header('Content-Type: ' . ($tipos[$extension] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($real));
        readfile($real);

        return true;
    }

    http_response_code(404);

    return true;
}

require $raiz . '/index.php';
