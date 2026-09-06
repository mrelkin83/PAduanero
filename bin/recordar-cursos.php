<?php

declare(strict_types=1);

/**
 * Recordatorio a quien compró un curso y no lo ha terminado (pasados unos
 * días). Encola los correos; el cron de correos (bin/enviar-correos.php) los
 * envía.
 *
 *   php bin/recordar-cursos.php
 *
 * Cron sugerido, una vez al día:  0 15 * * *  php /var/www/pedro/bin/recordar-cursos.php
 */

use App\Core\BD;
use App\Cuenta\ProgresoCurso;
use App\Repositorios\CertificadoRepo;
use App\Servicios\ColaCorreos;
use App\Servicios\RecordatorioCursos;
use App\Soporte\Entorno;

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
    $urlBase = rtrim((string) (Entorno::obtener('APP_URL', '') ?? ''), '/');

    $servicio = new RecordatorioCursos(
        $bd,
        new ProgresoCurso($bd, new CertificadoRepo($bd)),
        new ColaCorreos($bd),
        $urlBase,
    );

    $n = $servicio->procesar();
    echo "[recordar-cursos] recordatorios encolados: {$n}\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[recordar-cursos] Error: ' . $e->getMessage() . "\n");
    exit(1);
}
