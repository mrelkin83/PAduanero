<?php

declare(strict_types=1);

/**
 * Expira las reservas cuya ventana de pago venció.
 *
 *   php bin/cron-expirar-reservas.php
 *
 * Cron sugerido:  *\/5 * * * *   (cada cinco minutos)
 *
 * La regla 7: una reserva sin pago confirmado expira a los N minutos
 * (`minutos_reserva_pago`, default 45). Este cron es quien la ejecuta — la
 * expiración no ocurre «sola» por tener la columna `reserva_expira`.
 *
 * Tres efectos por cada reserva vencida, en este orden:
 *
 *  1. La consulta pasa a `expirada` (ConsultaRepo::expirarVencidas), lo que
 *     libera el cupo: `slot_unico` se vuelve NULL y otra persona puede
 *     tomar esa hora.
 *  2. Sus pagos pendientes pasan a `expirado`. Si el contacto paga DESPUÉS
 *     con un enlace viejo, el webhook llegará con la referencia de un pago
 *     expirado y quedará registrado sin confirmar nada — conciliación
 *     manual, no confirmación automática de un cupo que ya no existe.
 *  3. Se le avisa al contacto por el hilo, con plantilla fija. Sin este
 *     aviso, el que iba a pagar a los 50 minutos descubre en el checkout
 *     que el enlace murió y nadie le dijo por qué.
 */

use App\Core\BD;
use App\Servicios\ConfigMysql;
use App\Servicios\OutboxMysql;
use App\Soporte\Entorno;
use App\Soporte\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

Entorno::cargar($raiz . '/.env');
date_default_timezone_set('America/Bogota');

$bd = BD::desdeEntorno();
$log = Logger::desdeEntorno();
$outbox = new OutboxMysql($bd);
$consultas = new App\Repositorios\ConsultaRepo($bd);

$expiradas = $consultas->expirarVencidas();

if ($expiradas === []) {
    echo "Nada que expirar.\n";
    exit(0);
}

$pdo = $bd->pdo();
$huecos = implode(',', array_fill(0, count($expiradas), '?'));

// Los enlaces de pago de esas reservas mueren con ellas.
$pdo->prepare(
    "UPDATE pagos SET estado = 'expirado'
      WHERE consulta_id IN ({$huecos}) AND estado IN ('creado','pendiente')"
)->execute($expiradas);

// Aviso al contacto, por el hilo de su caso.
$stmt = $pdo->prepare(
    "SELECT ce.chatwoot_conv_id
       FROM consultas c
       JOIN conversacion_estado ce ON ce.caso_id = c.caso_id
      WHERE c.id IN ({$huecos})"
);
$stmt->execute($expiradas);

foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $conv) {
    $outbox->encolar('chatwoot.entregar', [
        'chatwoot_conv_id' => (int) $conv,
        'texto' => 'El tiempo para confirmar el pago de su asesoría venció y el cupo '
            . 'quedó liberado. Si aún desea la asesoría, con gusto le proponemos '
            . 'un nuevo horario por este mismo medio.',
    ]);
}

$log->info('reservas.expiradas', ['cuantas' => count($expiradas)]);

printf("Expiradas: %d reserva(s).\n", count($expiradas));
