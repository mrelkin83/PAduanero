<?php

declare(strict_types=1);

/**
 * Sincroniza el catálogo de modelos con lo que cada proveedor anuncia hoy.
 *
 *   php bin/cron-sincronizar-modelos.php
 *
 * Cron sugerido:  15 5 * * *   (una vez al día basta y sobra)
 *
 * Esto es lo que hace que salir Opus 6 y verlo en el panel no requiera tocar
 * código. Lo que NO hace es empezar a usarlo: lo nuevo entra inactivo y sin
 * costo verificado, y ascenderlo es un acto humano con firma en `auditoria`.
 * El razonamiento completo está en `App\Servicios\CatalogoModelos`.
 *
 * Sale con 0 aunque algún proveedor falle: un proveedor caído es una
 * incidencia que se lee en la salida y en `sincronizaciones_modelos`, no
 * motivo para que el cron se marque como roto. Sale con 1 solo si NINGÚN
 * proveedor respondió, que sí suele significar que el problema es de este
 * lado —red del VPS, .env, base de datos.
 */

use App\Core\Aplicacion;
use App\Repositorios\AuditoriaRepo;
use App\Servicios\CatalogoModelos;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

try {
    $contenedor = (new Aplicacion($raiz))->contenedor;

    $resumen = $contenedor->obtener(CatalogoModelos::class)->sincronizarTodo();

    if ($resumen === []) {
        fwrite(STDERR, 'No hay proveedores de IA activos. Nada que sincronizar.' . PHP_EOL);
        exit(0);
    }

    $nuevosTotal = 0;
    $conExito = 0;

    foreach ($resumen as $fila) {
        $nuevosTotal += $fila['nuevos'];
        $conExito += $fila['ok'] ? 1 : 0;

        printf(
            "[%s] %-14s %s%s",
            date('c'),
            $fila['proveedor'],
            $fila['ok']
                ? sprintf(
                    'nuevos: %d · vistos: %d · retirados: %d',
                    $fila['nuevos'],
                    $fila['vistos'],
                    $fila['retirados'],
                )
                : 'FALLÓ — ' . $fila['error'],
            PHP_EOL,
        );
    }

    // Huella para `bin/salud.sh`, igual que en cron-purgar: un cron que
    // nadie vigila es un cron que un día deja de correr en silencio.
    $contenedor->obtener(AuditoriaRepo::class)->registrar(
        'sistema',
        null,
        'sincronizar_modelos',
        'cron',
        ['proveedores' => count($resumen), 'con_exito' => $conExito, 'nuevos' => $nuevosTotal],
    );

    if ($nuevosTotal > 0) {
        printf(
            "%s  %d modelo(s) nuevo(s) esperando revisión en /panel/ia.%s"
            . "  Ninguno se usará hasta que alguien registre su costo y lo active.%s",
            PHP_EOL,
            $nuevosTotal,
            PHP_EOL,
            PHP_EOL,
        );
    }

    exit($conExito === 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
