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

/**
 * Compresión de lo que es texto.
 *
 * No es cosmética ni una optimización de desarrollo: `bin/auditar-landing.mjs`
 * mide el peso contra un presupuesto de 300 KB y comprueba el LCP contra los
 * 2 s del criterio de cierre, y lo hace contra ESTE servidor. Sin comprimir
 * aquí, la auditoría mide un escenario que no existe en producción — el HTML
 * de la landing pesa 60 KB en crudo y 13 comprimido— y decide mal en las dos
 * direcciones: alarma por un presupuesto que no se está gastando y, peor,
 * invita a recortar lo que no hay que recortar.
 *
 * El VPS lo hace con `gzip on` en el bloque de Nginx (docs/RUNBOOK.md §4bis).
 */
$aceptaGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')
    && extension_loaded('zlib');

$comprimir = static function (string $cuerpo, string $tipo) use ($aceptaGzip): array {
    // Los binarios ya vienen comprimidos: woff2, avif y webp llevan su propia
    // compresión dentro y volver a pasarlos por gzip los deja igual o más
    // grandes, gastando CPU.
    $texto = str_starts_with($tipo, 'text/')
        || str_contains($tipo, 'javascript')
        || str_contains($tipo, 'json')
        || str_contains($tipo, 'svg');

    if (!$aceptaGzip || !$texto) {
        return [$cuerpo, false];
    }

    $comprimido = gzencode($cuerpo, 6);

    return $comprimido === false ? [$cuerpo, false] : [$comprimido, true];
};

// Los estáticos viven en public/ pero se piden en la raíz de la URL,
// igual que en producción.
if (preg_match('#^/(img|css|js|fonts)/#', $ruta) === 1) {
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
            // Sin el tipo correcto el navegador descarta el archivo y la
            // página cae a la tipografía de reserva sin decir por qué.
            'woff2' => 'font/woff2',
        ];

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $tipo = $tipos[$extension] ?? 'application/octet-stream';

        [$cuerpo, $comprimido] = $comprimir((string) file_get_contents($real), $tipo);

        header('Content-Type: ' . $tipo);
        if ($comprimido) {
            header('Content-Encoding: gzip');
        }
        header('Content-Length: ' . strlen($cuerpo));
        echo $cuerpo;

        return true;
    }

    http_response_code(404);

    return true;
}

// El HTML que sale del front controller, por el mismo camino. Se recoge en
// un búfer porque `Respuesta::enviar()` ya emitió sus cabeceras y aquí solo
// queda añadir la codificación.
ob_start();
require $raiz . '/index.php';
$salida = (string) ob_get_clean();

$tipo = 'text/html';
foreach (headers_list() as $cabecera) {
    if (stripos($cabecera, 'content-type:') === 0) {
        $tipo = trim(substr($cabecera, 13));
    }
}

[$cuerpo, $comprimido] = $comprimir($salida, $tipo);

if ($comprimido) {
    header('Content-Encoding: gzip');
    header('Content-Length: ' . strlen($cuerpo));
}

echo $cuerpo;
