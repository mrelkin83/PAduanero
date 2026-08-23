<?php

declare(strict_types=1);

/**
 * Recordatorio de citas próximas. Lo corre el cron cada 5 minutos:
 *
 *   *\/5 * * * *  php /var/www/pedro/bin/wa-recordatorios.php
 *
 * Ver App\Wa\Recordatorios para las reglas (ventana de 30 minutos, un solo
 * aviso por cita, WhatsApp siempre y correo solo con SMTP configurado).
 */

use App\Core\BD;
use App\Soporte\Cifrado;
use App\Soporte\Entorno;
use App\Soporte\Logger;
use App\Soporte\Smtp;
use App\Wa\MotorWa;
use App\Wa\Recordatorios;
use ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient;

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
    $log = Logger::desdeEntorno();
    $db = MotorWa::conectar($bd, Cifrado::desdeEntorno(), $log, $raiz);

    $enviadas = (new Recordatorios($db, EvolutionClient::desdeConfig($db), Smtp::desdeEntorno()))->enviar();

    if ($enviadas > 0) {
        $log->info('wa.citas_recordadas', ['citas' => $enviadas]);
        echo "Recordadas: {$enviadas}\n";
    }

    // La otra cara de la agenda: la reserva aparta la franja MIENTRAS se
    // paga, y si el pago no llega (wa_config.pago_expira_minutos, 30 por
    // defecto), aquí se cancela y la hora vuelve a ofrecerse. Sin esta
    // llamada, nada vencía los apartados y una franja sin pagar quedaba
    // retenida para siempre.
    $vencidos = (new \ElkinLinan\WhatsappAiEngine\Payments\PaymentManager(
        $db,
        new \ElkinLinan\WhatsappAiEngine\Core\AuditLogger($db),
        \ElkinLinan\WhatsappAiEngine\Engine::dominio(),
    ))->vencerAbandonados();

    if ($vencidos > 0) {
        $log->info('wa.apartados_vencidos', ['pedidos' => $vencidos]);
        echo "Vencidos: {$vencidos}\n";
    }
} catch (\Throwable $e) {
    error_log('[wa-recordatorios] ' . $e->getMessage());
    exit(1);
}
