<?php

declare(strict_types=1);

/**
 * VIGILANCIA DE LA SESIÓN DE WHATSAPP — Pedro Abogado Aduanero
 * Cron sugerido, cada 5 minutos:  0-59/5 * * * *  php /var/www/pedro/bin/wa-vigilar.php
 *
 * Evolution/Baileys puede perder la sesión (WhatsApp la revoca — «device
 * removed» — o cae la red) sin que la aplicación se entere sola:
 * `wa_config.estado_conexion` solo se escribe cuando alguien pasa por el
 * panel o por `wa-configurar.php`, así que puede quedar días desactualizada
 * mientras el bot lleva horas sin poder recibir ni responder. Pasó el
 * 2026-08-26: device_removed a las 19:33 UTC y nadie se enteró hasta que un
 * cliente escribió y no obtuvo respuesta, ~5.5 h después.
 *
 * Este script pregunta a Evolution el estado REAL de la instancia en cada
 * corrida y lo compara con el último que quedó guardado. Solo cuando CAMBIA
 * —no en cada corrida— deja rastro en wa_eventos y avisa.
 *
 * El aviso NO puede salir por el mismo WhatsApp que se cayó: si «pedro» está
 * desconectado, pedirle a «pedro» que avise es pedirle ayuda al que se
 * ahogó. Por eso sale por una instancia de Evolution DISTINTA —
 * wa_config.alerta_whatsapp_instancia, un número de respaldo en otro
 * teléfono, configurable desde /panel/whatsapp— y, si hay SMTP configurado
 * y wa_config.alerta_correo, también por correo. Los dos canales son best
 * effort e independientes entre sí: que falle uno no impide el otro.
 */

use App\Core\BD;
use App\Soporte\Cifrado;
use App\Soporte\Entorno;
use App\Soporte\Fechas;
use App\Soporte\Logger;
use App\Soporte\Smtp;
use App\Wa\MotorWa;
use ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient;
use ElkinLinan\WhatsappAiEngine\Core\AuditLogger;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

Entorno::cargar($raiz . '/.env');
date_default_timezone_set(Fechas::ZONA);

try {
    $bd = BD::desdeEntorno();
    $cifrado = Cifrado::desdeEntorno();
    $log = Logger::desdeEntorno();
    $db = MotorWa::conectar($bd, $cifrado, $log, $raiz);

    $cfg = WaConfig::cargar($db, true);
    if (!$cfg || (int) ($cfg['activo'] ?? 0) !== 1) {
        exit(0); // Motor apagado: nada que vigilar.
    }

    $canal = EvolutionClient::desdeConfig($db);
    if (!$canal) {
        exit(0); // Evolution sin configurar.
    }

    $anterior = (string) ($cfg['estado_conexion'] ?? '');
    $r = $canal->estado();
    $actual = (string) $r['estado']; // conectado | qr | desconectado | error

    if ($actual === $anterior) {
        exit(0); // Sin cambios: nada que decir.
    }

    $auditoria = new AuditLogger($db);

    $campos = ['estado_conexion' => $actual];
    if ($actual === 'conectado' && !empty($r['numero'])) {
        $campos['numero_whatsapp'] = (string) $r['numero'];
        $campos['ultima_conexion'] = date('Y-m-d H:i:s');
    }
    WaConfig::guardar($db, $campos);
    $auditoria->log('config', "Conexión de WhatsApp: {$anterior} -> {$actual}", ['detalle' => $r['mensaje'] ?? '']);

    $textos = [
        'conectado' => '✅ WhatsApp de Pedro reconectado' . (!empty($r['numero']) ? " ({$r['numero']})" : '') . '.',
        'qr' => '🟡 WhatsApp de Pedro está reconectando y espera un QR nuevo — el bot no puede responder mientras tanto.',
    ];
    $texto = $textos[$actual]
        ?? "🔴 WhatsApp de Pedro DESCONECTADO (estado: {$actual}). El bot no puede recibir ni responder mensajes "
            . 'hasta que se vincule de nuevo con QR desde /panel/whatsapp.';

    avisar($db, $cfg, $auditoria, $texto);

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * Manda el aviso por los canales que haya configurados desde /panel/whatsapp
 * (wa_config.alerta_whatsapp_*, wa_config.alerta_correo). Ninguno es
 * obligatorio: sin nada configurado, el cambio de estado igual queda en
 * wa_eventos para quien lo revise ahí.
 *
 * @param array<string,mixed> $cfg fila de wa_config con secretos AÚN cifrados
 *   (se descifra aquí mismo lo que haga falta, no antes).
 */
function avisar($db, array $cfg, AuditLogger $auditoria, string $texto): void
{
    $numero = trim((string) ($cfg['alerta_whatsapp_numero'] ?? ''));
    $instanciaAlerta = trim((string) ($cfg['alerta_whatsapp_instancia'] ?? ''));
    $porWa = false;

    if ($numero !== '' && $instanciaAlerta !== '') {
        $url = (string) ($cfg['evolution_url'] ?? '');
        $apikey = WaConfig::secreto($cfg, 'evolution_apikey');
        if ($url !== '' && $apikey !== '') {
            $respaldo = new EvolutionClient($url, $instanciaAlerta, $apikey);
            $envio = $respaldo->enviarTexto($numero, $texto);
            $porWa = !empty($envio['ok']);
            if (!$porWa) {
                $auditoria->log('error', 'No se pudo mandar la alerta por el WhatsApp de respaldo: '
                    . ($envio['error'] ?? ''), null);
            }
        }
    }

    $porCorreo = false;
    $correoAlerta = trim((string) ($cfg['alerta_correo'] ?? ''));
    $smtp = Smtp::desdeEntorno();
    if ($smtp && $correoAlerta !== '') {
        $porCorreo = $smtp->enviar($correoAlerta, 'Pedro — estado de WhatsApp', $texto);
    }

    if (!$porWa && !$porCorreo) {
        // Ningún canal externo funcionó (o ninguno está configurado): al
        // menos que quede en el log del cron, no solo en wa_eventos.
        error_log('[wa-vigilar] ' . $texto);
    }
}
