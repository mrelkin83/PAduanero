<?php

declare(strict_types=1);

/**
 * Despacha los efectos externos pendientes (ADR-004).
 *
 *   php bin/worker-outbox.php --demonio    ← servicio pedro-outbox (systemd)
 *   php bin/worker-outbox.php              ← una pasada, para el cron y a mano
 *
 * DOS MODOS, Y NO SOBRA NINGUNO
 *
 * El modo demonio es el principal, y lo vigila `bin/salud.sh` con
 * `systemctl is-active pedro-outbox`. Existe por la latencia: una alerta de
 * escalamiento urgente que tarda hasta un minuto en salir porque espera al
 * siguiente tic del cron llega tarde a lo único que este sistema considera
 * verdaderamente urgente.
 *
 * La pasada única se conserva porque es lo que hace la clase probable sin
 * lanzar procesos, sirve para desatascar a mano, y funciona como red si
 * alguien deja el servicio caído: un cron cada cinco minutos no reemplaza al
 * demonio, pero impide que la cola se quede parada un fin de semana entero.
 *
 * Sale con 0 aunque haya eventos reprogramados: eso es funcionamiento normal
 * de la cola, no un fallo del proceso. Sale con 1 solo si no pudo arrancar.
 */

use App\Core\Aplicacion;
use App\Servicios\WorkerOutbox;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

/** Intervalo del modo demonio. Suficientemente corto para una urgencia. */
const INTERVALO_SEGUNDOS = 3;

$demonio = in_array('--demonio', $argv, true);
$seguir = true;

// Apagado ordenado: systemd manda SIGTERM al reiniciar o al desplegar. Sin
// esto, el proceso muere a mitad de un despacho y deja eventos en
// `procesando` que solo recuperará la pasada siguiente, quince minutos
// después. Con esto, termina la pasada en curso y sale limpio.
if ($demonio && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);

    foreach ([SIGTERM, SIGINT] as $senal) {
        pcntl_signal($senal, static function () use (&$seguir): void {
            $seguir = false;
        });
    }
}

try {
    $worker = (new Aplicacion($raiz))->contenedor->obtener(WorkerOutbox::class);

    do {
        $resumen = $worker->pasada();

        // Silencio cuando no hay nada que hacer. En modo demonio, una línea
        // por pasada serían decenas de miles al día que esconden las que
        // importan.
        if (array_sum($resumen) > 0) {
            printf(
                "[%s] recuperados: %d · despachados: %d · reprogramados: %d · descartados: %d%s",
                date('c'),
                $resumen['recuperados'],
                $resumen['despachados'],
                $resumen['reprogramados'],
                $resumen['descartados'],
                PHP_EOL,
            );
        }

        if ($demonio && $seguir) {
            sleep(INTERVALO_SEGUNDOS);
        }
    } while ($demonio && $seguir);

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
