<?php

declare(strict_types=1);

/**
 * Purga de datos con retención limitada.
 *
 *   php bin/cron-purgar.php
 *
 * Cron sugerido:  30 4 * * *
 *
 * No es mantenimiento opcional: `intentos_acceso` guarda direcciones IP, que
 * son dato personal bajo la Ley 1581 de 2012. Guardarlas indefinidamente es
 * un pasivo, no un activo — el mismo argumento que `docs/RESPALDOS.md` §6
 * hace sobre los respaldos.
 *
 * Las sesiones caducadas se van con ellas: no sirven para nada y solo hacen
 * crecer la tabla.
 */

use App\Core\BD;
use App\Repositorios\IntentoAccesoRepo;
use App\Repositorios\SesionRepo;
use App\Soporte\Entorno;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

Entorno::cargar($raiz . '/.env');

try {
    $bd = BD::desdeEntorno();

    $sesiones = (new SesionRepo($bd))->purgar(30);
    $intentos = (new IntentoAccesoRepo($bd))->purgar(30);

    printf(
        "[%s] sesiones caducadas: %d · intentos de acceso: %d%s",
        date('c'),
        $sesiones,
        $intentos,
        PHP_EOL,
    );

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
