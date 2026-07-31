<?php

declare(strict_types=1);

/**
 * Aplica db/migraciones/ en orden. Idempotente (ADR-013).
 *
 *   php bin/migrar.php            aplica lo pendiente
 *   php bin/migrar.php --estado   solo informa, no toca nada
 *
 * Lo llama el despliegue (docs/RUNBOOK.md §5). Antes de una migración de
 * esquema: respaldo manual.
 */

use App\Core\BD;
use App\Core\Migrador;
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
    $migrador = new Migrador($bd, $raiz . '/db/migraciones');

    if (in_array('--estado', $argv, true)) {
        $filas = $bd->pdo()->query(
            'SELECT version, aplicada_en FROM migraciones ORDER BY version'
        )->fetchAll();

        echo 'Base: ' . $bd->nombreBase() . PHP_EOL;
        foreach ($filas as $fila) {
            echo "  {$fila['version']}  ({$fila['aplicada_en']} UTC)" . PHP_EOL;
        }
        echo count($filas) . ' migraciones aplicadas.' . PHP_EOL;
        exit(0);
    }

    echo 'Migrando ' . $bd->nombreBase() . '…' . PHP_EOL;

    foreach ($migrador->migrar() as $mensaje) {
        echo '  ' . $mensaje . PHP_EOL;
    }

    echo 'Listo.' . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
