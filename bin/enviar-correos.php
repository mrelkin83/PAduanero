<?php

declare(strict_types=1);

/**
 * Procesa la cola de correos (tabla correos_cola): toma los pendientes y los
 * envía por SMTP, reintentando hasta 3 veces. El sitio solo ENCOLA; este cron
 * ENVÍA, para que un SMTP lento o caído no bloquee ni pierda un correo.
 *
 *   php bin/enviar-correos.php
 *
 * Cron sugerido, cada minuto:  * * * * *  php /var/www/pedro/bin/enviar-correos.php
 *
 * Sin SMTP configurado (SMTP_HOST vacío) no hace nada y los correos esperan
 * en la cola — no se pierden ni se marcan fallidos.
 */

use App\Core\BD;
use App\Servicios\ColaCorreos;
use App\Soporte\Entorno;
use App\Soporte\Smtp;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

Entorno::cargar($raiz . '/.env');
date_default_timezone_set(\App\Soporte\Fechas::ZONA);

try {
    $bd = BD::desdeEntorno();
    $cola = new ColaCorreos($bd);
    $r = $cola->procesarPendientes(Smtp::desdeEntorno());

    if ($r['pendientes_sin_smtp'] > 0) {
        fwrite(STDERR, "[enviar-correos] SMTP sin configurar: {$r['pendientes_sin_smtp']} correo(s) en espera.\n");
        exit(0);
    }

    echo "[enviar-correos] enviados={$r['enviados']} fallidos={$r['fallidos']}\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[enviar-correos] Error: ' . $e->getMessage() . "\n");
    exit(1);
}
