<?php

declare(strict_types=1);

/**
 * Punto de entrada único. En la raíz, por requisito del PO.
 *
 * Todo lo demás vive bajo src/ y llega aquí por el .htaccess, que reescribe
 * cualquier ruta que no sea un archivo real hacia este archivo.
 */

use App\Core\Aplicacion;
use App\Excepciones\ConfiguracionFatalException;

require __DIR__ . '/vendor/autoload.php';

try {
    (new Aplicacion(__DIR__))->ejecutar();
} catch (ConfiguracionFatalException $e) {
    // La aplicación no arrancó: falta MASTER_KEY, PEPPER_TELEFONO o la
    // conexión a la base. El detalle va al log de PHP, nunca al navegador —
    // decir qué variable falta es decirle a un atacante qué buscar.
    error_log('[arranque] ' . $e->getMessage());

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 300');
    echo "El servicio no está disponible en este momento.\n";
}
