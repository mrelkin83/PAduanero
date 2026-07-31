<?php

declare(strict_types=1);

/**
 * Verifica el criterio de cierre de la Etapa 2, en la parte que una máquina
 * puede verificar.
 *
 *   php bin/verificar-canales.php
 *
 * Se corre EN EL VPS, con Chatwoot y Evolution ya desplegados.
 *
 * Qué comprueba:
 *   · Chatwoot responde y el token del bot sirve.
 *   · Existe una bandeja por cada uno de los cuatro canales.
 *   · La instancia de WhatsApp está conectada (`open`), y distingue el
 *     estado «pendiente de activación de licencia» del resto de fallos,
 *     porque el remedio es distinto (CLAUDE.md §1.3).
 *   · El widget web está configurado en la landing.
 *   · Las notificaciones por correo están configuradas.
 *
 * Qué NO puede comprobar, y por eso el cierre sigue necesitando a una
 * persona: que un mensaje enviado de verdad desde cada canal aparezca en la
 * bandeja. Eso exige un teléfono, una cuenta de Instagram y una de
 * Messenger. La lista para hacerlo a mano está en
 * docs/DESPLIEGUE_CANALES.md §7.
 *
 * NOTA DE ALCANCE: este script habla con la API de Chatwoot por su cuenta.
 * No es `App\Servicios\Chatwoot` ni pretende serlo — ese contrato se
 * implementa en la Etapa 4 (docs/PLAN_BUILD.md). Aquí solo hay lecturas.
 */

use App\Soporte\Entorno;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

Entorno::cargar($raiz . '/.env');

const CANALES_ESPERADOS = [
    'Channel::Api' => 'WhatsApp (vía Evolution)',
    'Channel::Instagram' => 'Instagram',
    'Channel::FacebookPage' => 'Messenger',
    'Channel::WebWidget' => 'Widget web',
];

$fallos = 0;
$avisos = 0;

function ok(string $texto): void
{
    echo "  \033[32m✓\033[0m {$texto}\n";
}

function mal(string $texto): void
{
    global $fallos;
    $fallos++;
    echo "  \033[31m✗\033[0m {$texto}\n";
}

function aviso(string $texto): void
{
    global $avisos;
    $avisos++;
    echo "  \033[33m!\033[0m {$texto}\n";
}

/** @return array{estado:int,cuerpo:string} */
function pedir(string $url, array $cabeceras = [], int $timeout = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $cabeceras,
        CURLOPT_FAILONERROR => false,
    ]);

    $cuerpo = curl_exec($ch);
    $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['estado' => $estado, 'cuerpo' => is_string($cuerpo) ? $cuerpo : ''];
}

echo "\nVerificación de canales — Etapa 2\n\n";

// ── Chatwoot ────────────────────────────────────────────────────────────
echo "Chatwoot\n";

$chatwootUrl = rtrim(Entorno::obtener('CHATWOOT_URL', '') ?? '', '/');
$cuenta = Entorno::obtener('CHATWOOT_ACCOUNT_ID', '1');
$token = Entorno::obtener('CHATWOOT_BOT_TOKEN', '');

$bandejas = [];

if ($chatwootUrl === '') {
    mal('CHATWOOT_URL no está definida en el .env');
} elseif ($token === null || $token === '') {
    mal('CHATWOOT_BOT_TOKEN no está definido en el .env');
} else {
    $r = pedir(
        "{$chatwootUrl}/api/v1/accounts/{$cuenta}/inboxes",
        ['api_access_token: ' . $token],
    );

    if ($r['estado'] === 401 || $r['estado'] === 403) {
        mal("el token no autoriza en la cuenta {$cuenta} (HTTP {$r['estado']})");
    } elseif ($r['estado'] !== 200) {
        mal("Chatwoot no responde correctamente (HTTP {$r['estado']})");
    } else {
        ok("responde y el token autoriza en la cuenta {$cuenta}");

        $datos = json_decode($r['cuerpo'], true);
        $bandejas = is_array($datos['payload'] ?? null) ? $datos['payload'] : [];

        ok(count($bandejas) . ' bandeja(s) configurada(s)');
    }
}

// ── Los cuatro canales ──────────────────────────────────────────────────
echo "\nCanales\n";

$tiposPresentes = array_column($bandejas, 'channel_type');

foreach (CANALES_ESPERADOS as $tipo => $nombre) {
    if (in_array($tipo, $tiposPresentes, true)) {
        $indice = array_search($tipo, $tiposPresentes, true);
        $bandeja = $bandejas[$indice];
        ok(sprintf('%-24s bandeja «%s» (id %d)', $nombre, $bandeja['name'] ?? '?', $bandeja['id'] ?? 0));
    } else {
        mal(sprintf('%-24s SIN bandeja — ver docs/DESPLIEGUE_CANALES.md', $nombre));
    }
}

// ── WhatsApp ────────────────────────────────────────────────────────────
echo "\nWhatsApp (Evolution)\n";

$evolutionUrl = rtrim(Entorno::obtener('EVOLUTION_URL', '') ?? '', '/');
$instancia = Entorno::obtener('EVOLUTION_INSTANCE', 'pedro');
$apiKey = Entorno::obtener('EVOLUTION_API_KEY', '');

if ($evolutionUrl === '' || $apiKey === null || $apiKey === '') {
    mal('EVOLUTION_URL o EVOLUTION_API_KEY sin definir en el .env');
} else {
    $r = pedir("{$evolutionUrl}/instance/connectionState/{$instancia}", ['apikey: ' . $apiKey]);

    if ($r['estado'] === 503) {
        // En v2.3.7 esto no debería pasar nunca: la activación llegó en la
        // 2.4.0. Si aparece, es que alguien subió de versión sin leer
        // CLAUDE.md §1.3. Y no es «WhatsApp desconectado»: el remedio es otro.
        mal('licencia SIN ACTIVAR (HTTP 503). ¿Se actualizó a 2.4.0+? '
            . 'Activar en /manager y poner EVOLUTION_OPERATOR_EMAIL');
    } elseif ($r['estado'] !== 200) {
        mal("Evolution no responde (HTTP {$r['estado']})");
    } else {
        $datos = json_decode($r['cuerpo'], true);
        $estado = $datos['instance']['state'] ?? ($datos['state'] ?? 'desconocido');

        if ($estado === 'open') {
            ok("instancia «{$instancia}» conectada");
        } elseif ($estado === 'connecting') {
            mal("instancia «{$instancia}» conectando — falta escanear el QR");
        } else {
            mal("instancia «{$instancia}» en estado «{$estado}» — ver RUNBOOK §3.1");
        }
    }
}

// Vacío es lo correcto en v2.3.7 y solo importa tras subir a 2.4.0+, así que
// esto no se comprueba aquí: avisarlo hoy sería ruido en cada corrida, y el
// ruido en un chequeo automático es como se dejan de leer los chequeos.
// La comprobación de que sigue puesto va en la rutina mensual del RUNBOOK.

// ── Widget web en la landing ────────────────────────────────────────────
echo "\nWidget web en la landing\n";

try {
    $bd = App\Core\BD::desdeEntorno();
    $stmt = $bd->pdo()->prepare('SELECT clave, valor FROM configuraciones WHERE clave IN (?, ?)');
    $stmt->execute(['chatwoot_widget_token', 'chatwoot_widget_url']);

    $config = [];
    foreach ($stmt->fetchAll() as $fila) {
        $config[$fila['clave']] = trim((string) json_decode((string) $fila['valor'], true));
    }

    if (($config['chatwoot_widget_token'] ?? '') === '' || ($config['chatwoot_widget_url'] ?? '') === '') {
        mal('sin configurar: la landing no emite el widget. '
            . 'Rellenar chatwoot_widget_token y chatwoot_widget_url');
    } else {
        ok('configurado — la landing emitirá el script del widget');
    }
} catch (Throwable $e) {
    mal('no se pudo leer la configuración: ' . $e->getMessage());
}

// ── Correo ──────────────────────────────────────────────────────────────
echo "\nAlertas por correo\n";

$compose = '/opt/chatwoot/.env';
if (!is_readable($compose)) {
    aviso("no se pudo leer {$compose} para comprobar el SMTP de Chatwoot");
} else {
    $contenido = (string) file_get_contents($compose);
    $tieneSmtp = preg_match('/^SMTP_ADDRESS=\S+/m', $contenido) === 1;

    $tieneSmtp
        ? ok('SMTP configurado en Chatwoot')
        : mal('SMTP sin configurar: Pedro no recibirá aviso de los mensajes nuevos');
}

// ── Resumen ─────────────────────────────────────────────────────────────
echo "\n";

if ($fallos === 0) {
    echo "Todo lo automatizable está en orden.\n\n";
    echo "Falta la parte manual del cierre: enviar un mensaje real desde cada\n";
    echo "uno de los cuatro canales y comprobar que aparece en la bandeja.\n";
    if ($avisos > 0) {
        echo "\nCon {$avisos} aviso(s) arriba: no bloquean el cierre, pero muerden después.\n";
    }

    exit(0);
}

echo "{$fallos} problema(s). La Etapa 2 no está cerrada.\n\n";
exit(1);
