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
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Repositorios\SesionRepo;
use App\Servicios\ConfigMysql;
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

    $config = new ConfigMysql(
        $bd,
        $raiz . '/storage/config.sentinel',
        $raiz . '/storage/cache/config.json',
    );

    // Cuánto se conservan las IP es una decisión de tratamiento de datos, no
    // un detalle de implementación: vive en `configuraciones` y se cambia
    // desde el panel sin desplegar.
    $dias = (int) $config->get('retencion_intentos_acceso_dias', 30);

    $sesiones = (new SesionRepo($bd))->purgar($dias);
    $intentos = (new IntentoAccesoRepo($bd))->purgar($dias);

    // Deja huella para que `bin/salud.sh` pueda comprobar que este cron
    // sigue corriendo. Un cron que nadie vigila es un cron que un día deja
    // de correr y nadie se entera hasta que hay un requerimiento.
    (new AuditoriaRepo($bd))->registrar('sistema', null, 'purga', 'cron', [
        'retencion_dias' => $dias,
        'sesiones' => $sesiones,
        'intentos_acceso' => $intentos,
    ]);

    printf(
        "[%s] retención %d d · sesiones caducadas: %d · intentos de acceso: %d%s",
        date('c'),
        $dias,
        $sesiones,
        $intentos,
        PHP_EOL,
    );

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
