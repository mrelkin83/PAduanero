<?php

declare(strict_types=1);

/**
 * Configuración del motor de WhatsApp por consola, hasta que el panel tenga
 * sus pantallas. Todo lo que hace es lo mismo que hará el panel: escribir
 * wa_config (cifrando secretos), generar el token del webhook y conectar el
 * Google Calendar del abogado.
 *
 *   php bin/wa-configurar.php --estado
 *       Qué hay configurado y qué falta para encender el motor.
 *
 *   php bin/wa-configurar.php --poner clave=valor [clave=valor…]
 *       Escribe columnas de wa_config. Los secretos (evolution_apikey,
 *       llm_api_key, wompi_private_key…) se cifran solos (ADR-011).
 *       Ej.: --poner evolution_url=https://evo.midominio.com evolution_instancia=pedro
 *            --poner llm_proveedor=anthropic llm_modelo=claude-sonnet-5 llm_api_key=sk-...
 *            --poner activo=1
 *
 *   php bin/wa-configurar.php --token
 *       Genera (y muestra UNA vez) el token del webhook. Con él se registran
 *       en Evolution:   <APP_URL>/api/wa/webhook/<token>
 *       y en Wompi:     <APP_URL>/api/wa/pago/<token>
 *
 *   php bin/wa-configurar.php --google-cliente <client_id> <client_secret>
 *   php bin/wa-configurar.php --google-url
 *       Imprime la URL para que Pedro autorice su calendario.
 *   php bin/wa-configurar.php --google-codigo <code>
 *       Canjea el código que devuelve Google y guarda el refresh token.
 */

use App\Core\BD;
use App\Soporte\Cifrado;
use App\Soporte\Entorno;
use App\Soporte\Logger;
use App\Wa\GoogleCalendar;
use App\Wa\MotorWa;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

Entorno::cargar($raiz . '/.env');
date_default_timezone_set(\App\Soporte\Fechas::ZONA);

// El flujo OAuth de escritorio: Google redirige a localhost y el código se
// copia de la barra de direcciones. Sin desplegar nada.
const REDIRECT_LOCAL = 'http://localhost:8123/oauth/google';

try {
    $bd = BD::desdeEntorno();
    $cifrado = Cifrado::desdeEntorno();
    $log = Logger::desdeEntorno();
    $db = MotorWa::conectar($bd, $cifrado, $log, $raiz);

    $accion = $argv[1] ?? '--estado';

    switch ($accion) {
        case '--estado':
            $cfg = WaConfig::cargar($db, true) ?? [];
            $google = new GoogleCalendar($bd, $cifrado, $log);

            $si = static fn ($v): string => $v ? 'sí' : 'NO';
            echo "Motor de WhatsApp — estado\n";
            echo '  activo:               ' . $si((int) ($cfg['activo'] ?? 0) === 1) . "\n";
            echo '  evolution_url:        ' . (($cfg['evolution_url'] ?? '') ?: '(vacío)') . "\n";
            echo '  evolution_instancia:  ' . (($cfg['evolution_instancia'] ?? '') ?: '(vacío)') . "\n";
            echo '  evolution_apikey:     ' . $si(!empty($cfg['evolution_apikey'])) . "\n";
            echo '  llm:                  ' . (($cfg['llm_proveedor'] ?? '') ?: '(vacío)') . ' / ' . (($cfg['llm_modelo'] ?? '') ?: '(vacío)') . "\n";
            echo '  llm_api_key:          ' . $si(!empty($cfg['llm_api_key'])) . "\n";
            echo '  pago_modo:            ' . ($cfg['pago_modo'] ?? '') . "  (mixto = exige pago; contra_entrega = agenda sin cobrar)\n";
            echo '  wompi:                ' . $si(!empty($cfg['wompi_private_key'])) . "\n";
            echo '  webhook_token:        ' . $si(!empty($cfg['webhook_token_hash'])) . "  (--token para generarlo)\n";
            echo '  google_calendar:      ' . $si($google->conectado()) . "  (--google-cliente / --google-url / --google-codigo)\n";
            echo "\nPara encender: cada NO de arriba resuelto, y después --poner activo=1\n";
            echo "Recuerda el CLAUDE.md: encender el motor exige la política de tratamiento de datos publicada.\n";
            break;

        case '--poner':
            $campos = [];
            foreach (array_slice($argv, 2) as $par) {
                $pos = strpos($par, '=');
                if ($pos === false) {
                    fwrite(STDERR, "Ignorado (no es clave=valor): {$par}\n");
                    continue;
                }
                $campos[substr($par, 0, $pos)] = substr($par, $pos + 1);
            }
            if (!$campos) {
                fwrite(STDERR, "Nada que escribir.\n");
                exit(1);
            }
            WaConfig::guardar($db, $campos);
            echo 'Guardado: ' . implode(', ', array_keys($campos)) . "\n";
            break;

        case '--token':
            $token = WaConfig::regenerarWebhookToken($db);
            $base = rtrim((string) (Entorno::obtener('APP_URL', '') ?? ''), '/');
            echo "Token generado. Se muestra UNA sola vez; guárdalo al registrarlo:\n\n";
            echo "  Webhook de Evolution:  {$base}/api/wa/webhook/{$token}\n";
            echo "  Webhook de Wompi:      {$base}/api/wa/pago/{$token}\n";
            break;

        case '--google-cliente':
            $id = $argv[2] ?? '';
            $secreto = $argv[3] ?? '';
            if ($id === '' || $secreto === '') {
                fwrite(STDERR, "Uso: --google-cliente <client_id> <client_secret>\n");
                exit(1);
            }
            (new GoogleCalendar($bd, $cifrado, $log))->guardarCliente($id, $secreto);
            echo "Cliente OAuth guardado (secreto cifrado). Sigue con --google-url\n";
            break;

        case '--google-url':
            $url = (new GoogleCalendar($bd, $cifrado, $log))->urlAutorizacion(REDIRECT_LOCAL);
            echo "Abre esta URL con la cuenta de Google del abogado y autoriza el calendario.\n";
            echo "Al terminar, Google redirige a localhost: copia el parámetro `code` de la URL\n";
            echo "y canjéalo con --google-codigo <code>\n\n{$url}\n";
            break;

        case '--google-codigo':
            $codigo = $argv[2] ?? '';
            if ($codigo === '') {
                fwrite(STDERR, "Uso: --google-codigo <code>\n");
                exit(1);
            }
            $ok = (new GoogleCalendar($bd, $cifrado, $log))->canjearCodigo($codigo, REDIRECT_LOCAL);
            echo $ok
                ? "Calendario conectado: refresh token guardado cifrado.\n"
                : "No se pudo canjear el código (¿expiró? Google los da por minutos). Genera otro con --google-url\n";
            exit($ok ? 0 : 1);

        default:
            fwrite(STDERR, "Acción desconocida: {$accion}. Lee el encabezado de este archivo.\n");
            exit(1);
    }

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
