<?php

declare(strict_types=1);

namespace App\Wa;

use App\Core\BD;
use App\Core\Peticion;
use App\Core\Respuesta;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient;
use ElkinLinan\WhatsappAiEngine\Core\AgentManager;
use ElkinLinan\WhatsappAiEngine\Core\AiOrchestrator;
use ElkinLinan\WhatsappAiEngine\Core\AuditLogger;
use ElkinLinan\WhatsappAiEngine\Core\ConversationManager;
use ElkinLinan\WhatsappAiEngine\Core\HumanHandoff;
use ElkinLinan\WhatsappAiEngine\Core\RateLimiter;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use ElkinLinan\WhatsappAiEngine\Engine;
use ElkinLinan\WhatsappAiEngine\Media\MediaProcessor;
use ElkinLinan\WhatsappAiEngine\Media\SttManager;
use ElkinLinan\WhatsappAiEngine\Media\TtsManager;
use ElkinLinan\WhatsappAiEngine\Media\VisionManager;
use ElkinLinan\WhatsappAiEngine\Payments\PaymentManager;
use ElkinLinan\WhatsappAiEngine\Payments\WompiAdapter;

/**
 * El borde del motor en PAduanero: los dos webhooks (mensajes de Evolution y
 * eventos de la pasarela).
 *
 * Es el `webhook.php` de ControlBarMax adaptado a esta casa, con una
 * diferencia estructural: aquí hay UN negocio y UNA base, así que el token no
 * enruta entre bases — solo autentica. Se valida directo contra
 * `wa_config.webhook_token_hash`, sin pasar por `resolverPorToken()`, que en
 * su camino de un solo negocio depende de la clase `\Database` del proyecto
 * de origen.
 *
 * El orden del original se conserva porque cada paso tiene su porqué:
 * autenticar → deduplicar → responder 200 YA → y solo entonces pensar. El 200
 * temprano es lo que evita que Evolution reintente por impaciencia; el índice
 * único sobre message_id_externo mata el reintento que aun así llegue.
 */
final class WebhookControlador
{
    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
        private readonly Logger $logApp,
        private readonly string $raiz,
    ) {
    }

    /* ── Mensajes entrantes de WhatsApp ───────────────────────────────── */

    public function entrada(Peticion $peticion): Respuesta
    {
        $db = MotorWa::conectar($this->bd, $this->cifrado, $this->logApp, $this->raiz);

        $cfg = $this->autenticar($db, (string) ($peticion->parametros['token'] ?? ''));
        if ($cfg === null) {
            return Respuesta::texto('Not Found', 404);
        }

        $log = new AuditLogger($db);

        $payload = $peticion->json();
        if (!$payload) {
            return Respuesta::json(['ok' => false, 'error' => 'payload']);
        }

        if ((int) $cfg['activo'] !== 1) {
            return Respuesta::json(['ok' => true, 'ignorado' => 'motor apagado']);
        }
        $canal = EvolutionClient::desdeConfig($db);
        if (!$canal) {
            return Respuesta::json(['ok' => false, 'error' => 'canal']);
        }

        // La autenticación real es el token de la URL; la apikey solo se
        // comprueba cuando el emisor la manda y no coincide (Evolution no la
        // incluye en sus webhooks salientes).
        $apikeyGuardada = WaConfig::secreto($cfg, 'evolution_apikey');
        if ($apikeyGuardada === '') {
            $apikeyGuardada = Engine::config()->canalApikeyPorDefecto();
        }
        $apikeyRecibida = $peticion->cabecera('apikey') ?? ($peticion->cabecera('x-api-key') ?? '');
        if ($apikeyGuardada !== '' && $apikeyRecibida !== '' && !hash_equals($apikeyGuardada, $apikeyRecibida)) {
            $log->log('webhook', 'Webhook con apikey incorrecta', null);

            return Respuesta::texto('Not Found', 404);
        }

        $msg = $canal->normalizarWebhook($payload);
        if (!$msg) {
            return Respuesta::json(['ok' => true, 'ignorado' => 'no es un mensaje entrante']);
        }

        $cm = new ConversationManager($db);
        $conv = $cm->obtenerOCrear($msg['telefono'], $msg['nombre']);

        // Idempotencia: índice único sobre message_id_externo. Un reintento
        // de Evolution devuelve 0 y muere aquí.
        $msgId = $cm->guardarMensaje((int) $conv['id'], 'entrante', [
            'message_id' => $msg['message_id'] ?: null,
            'tipo' => $msg['tipo'],
            'contenido' => $msg['texto'],
            'media_mime' => $msg['media_mime'],
        ]);
        if ($msgId === 0) {
            return Respuesta::json(['ok' => true, 'duplicado' => true]);
        }
        $cm->tocar((int) $conv['id']);

        // A partir de aquí el cliente ya recibió su 200.
        $this->responder200Ya();

        try {
            $this->procesar($db, $log, $canal, $cfg, $conv, $msg, $msgId);
        } catch (\Throwable $e) {
            $log->error('Fallo procesando el mensaje: ' . $e->getMessage()
                . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']', null, (int) $conv['id']);
            try {
                $canal->enviarTexto($msg['telefono'], (new AgentManager($db))->mensajeError());
            } catch (\Throwable) {
            }
            // Y se cumple la promesa del mensaje de error: alguien del equipo
            // queda avisado aunque el aviso de WhatsApp también falle.
            try {
                (new HumanHandoff($db, $log))->transferir(
                    (int) $conv['id'], 'El motor falló procesando un mensaje', $canal, $cfg);
            } catch (\Throwable) {
            }
        }

        return new Respuesta('', 200);
    }

    /** El trabajo de verdad: media → texto → orquestador → respuesta. */
    private function procesar($db, AuditLogger $log, $canal, array $cfg, array $conv, array $msg, int $msgId): void
    {
        if (!HumanHandoff::iaPuedeResponder($conv)) {
            $log->log('mensaje', 'Mensaje recibido con la IA en pausa', ['estado' => $conv['estado']], (int) $conv['id']);

            return;
        }

        // Techo de mensajes ANTES de descargar media: transcribir cien notas
        // de voz cuesta; ignorar cien textos, no.
        $lim = (new RateLimiter($db, $log))->comprobar($conv, $cfg);
        if (!$lim['permitido']) {
            if ($lim['avisar']) {
                $canal->enviarTexto($msg['telefono'], $lim['mensaje']);
                (new ConversationManager($db))->guardarMensaje((int) $conv['id'], 'saliente',
                    ['tipo' => 'texto', 'contenido' => $lim['mensaje']]);
            }

            return;
        }

        $mp = new MediaProcessor($db, $log);
        $adapter = Engine::dominio();
        $pagos = new PaymentManager($db, $log, $adapter);
        $texto = $msg['texto'];
        $eraAudio = false;

        // ── Audio → texto ─────────────────────────────────────────────
        if ($msg['tipo'] === 'audio') {
            $eraAudio = true;
            $bin = $canal->descargarMedia($msg);
            $stt = SttManager::desdeConfig($cfg);
            if (!$bin) {
                $canal->enviarTexto($msg['telefono'], 'No pude descargar tu nota de voz 😕 ¿me lo escribes?');

                return;
            }
            $g = $mp->guardar($bin, $msg['media_mime'] ?: 'audio/ogg', 'audio');
            if ($g['ok']) {
                $db->query('UPDATE wa_mensajes SET media_ruta = ? WHERE id = ?', [$g['ruta'], $msgId]);
            }

            if (!$stt || !$stt->disponible()) {
                $canal->enviarTexto($msg['telefono'], 'Por ahora no puedo escuchar notas de voz 🙏 ¿me lo escribes?');

                return;
            }
            $t = $stt->transcribir($bin, $msg['media_mime'] ?: 'audio/ogg');
            if (!$t['ok']) {
                $log->error('No se pudo transcribir el audio: ' . $t['error'], null, (int) $conv['id']);
                $canal->enviarTexto($msg['telefono'], 'No logré entender el audio 😕 ¿me lo escribes?');

                return;
            }
            $texto = $t['texto'];
            $db->query('UPDATE wa_mensajes SET transcripcion = ? WHERE id = ?', [$texto, $msgId]);
        }

        // ── Imagen → descripción o comprobante ────────────────────────
        if ($msg['tipo'] === 'imagen') {
            $bin = $canal->descargarMedia($msg);
            if (!$bin) {
                $canal->enviarTexto($msg['telefono'], 'No pude ver la imagen 😕 ¿me la reenvías?');

                return;
            }
            $g = $mp->guardar($bin, $msg['media_mime'] ?: 'image/jpeg', 'imagen');
            if ($g['ok']) {
                $db->query('UPDATE wa_mensajes SET media_ruta = ? WHERE id = ?', [$g['ruta'], $msgId]);
            }

            $vm = VisionManager::desdeConfig($cfg);
            if (!$vm || !$vm->disponible()) {
                $texto = trim(($msg['texto'] ?: '') . "\n[SISTEMA: el cliente envió una imagen pero no hay servicio de visión disponible. NO inventes qué muestra; pídele con amabilidad que te lo cuente por texto.]");
            } else {
                // ¿Hay una cita esperando pago? Lo más probable es que la
                // foto sea el comprobante.
                $pendiente = $db->fetch(
                    "SELECT pedido_id FROM wa_pedidos
                      WHERE conversacion_id = ? AND estado_pago IN ('PAYMENT_PENDING','PAYMENT_INITIATED','PAYMENT_VALIDATING','PAYMENT_REVIEW_REQUIRED')
                      ORDER BY id DESC LIMIT 1", [(int) $conv['id']]);

                if ($pendiente) {
                    $ex = $vm->extraerComprobante($bin, $g['mime'] ?: ($msg['media_mime'] ?: 'image/jpeg'));
                    $datos = $ex['ok'] ? ($ex['datos'] ?? null) : null;
                    $r = $pagos->procesarComprobante((int) $pendiente['pedido_id'], $g['ruta'] ?? '', $datos);
                    $canal->enviarTexto($msg['telefono'], $r['mensaje'] ?? 'Recibimos tu comprobante, lo estamos revisando.');
                    (new ConversationManager($db))->guardarMensaje((int) $conv['id'], 'saliente', [
                        'tipo' => 'texto', 'contenido' => $r['mensaje'] ?? '']);
                    if (($r['estado'] ?? '') === 'PAYMENT_REVIEW_REQUIRED') {
                        (new HumanHandoff($db, $log))->transferir((int) $conv['id'],
                            'Comprobante de pago pendiente de revisión', $canal, $cfg);
                    }

                    return;
                }

                $d = $vm->describir($bin, $g['mime'] ?: ($msg['media_mime'] ?: 'image/jpeg'));
                if ($d['ok'] && trim($d['texto']) !== '') {
                    $texto = trim(($msg['texto'] ?: '') . "\n[SISTEMA: el cliente te envió una imagen y TÚ SÍ LA VES. Su contenido es: «"
                        . trim($d['texto']) . "». Responde de forma natural sobre lo que muestra la imagen. NUNCA digas que no puedes ver imágenes ni que no la pudiste ver.]");
                } else {
                    $texto = trim(($msg['texto'] ?: '') . "\n[SISTEMA: el cliente envió una imagen pero no se pudo analizar su contenido. NO inventes qué muestra; pídele con amabilidad que te la describa por texto.]");
                }
            }
            $db->query('UPDATE wa_mensajes SET transcripcion = ? WHERE id = ?', [$texto, $msgId]);
        }

        if ($msg['tipo'] === 'documento' && trim($texto) === '') {
            $canal->enviarTexto($msg['telefono'], 'Por este canal solo puedo leer texto, notas de voz e imágenes 🙏');

            return;
        }
        if (trim($texto) === '') {
            return;
        }

        // ── Pensar y responder ────────────────────────────────────────
        $orq = new AiOrchestrator($db, $canal, $log, $pagos);
        $respuesta = $orq->procesar($conv, $texto);

        // ── Voz de vuelta, si toca ────────────────────────────────────
        $tts = TtsManager::desdeConfig($cfg);
        if ($respuesta !== '' && $tts && $tts->debeHablar($eraAudio)) {
            $s = $tts->sintetizar($respuesta);
            if ($s['ok']) {
                $canal->enviarAudio($msg['telefono'], base64_encode($s['audio']), $s['mime']);
            } else {
                $log->error('No se pudo generar el audio de respuesta: ' . $s['error'], null, (int) $conv['id']);
            }
        }
    }

    /* ── Eventos de la pasarela de pago ───────────────────────────────── */

    public function pago(Peticion $peticion): Respuesta
    {
        $db = MotorWa::conectar($this->bd, $this->cifrado, $this->logApp, $this->raiz);

        $cfg = $this->autenticar($db, (string) ($peticion->parametros['token'] ?? ''));
        if ($cfg === null) {
            return Respuesta::texto('Not Found', 404);
        }

        $log = new AuditLogger($db);

        $pasarela = WompiAdapter::desdeConfig($cfg);
        if (!$pasarela) {
            return Respuesta::json(['ok' => false, 'error' => 'sin pasarela']);
        }

        // La firma se valida contra el CUERPO CRUDO, nunca contra el JSON
        // reparseado (docs/CONTRATOS.md — misma regla que tenía Wompi aquí).
        $v = $pasarela->verificarWebhook($peticion->cuerpoCrudo, $peticion->cabeceras);
        if (!$v['ok']) {
            $log->log('webhook', 'Evento de pago rechazado: ' . $v['error'], null);

            // 200 a propósito: un 4xx haría reintentar eternamente un evento
            // que nunca vamos a aceptar.
            return Respuesta::json(['ok' => false]);
        }

        $this->responder200Ya();

        try {
            $adapter = Engine::dominio();
            $pm = new PaymentManager($db, $log, $adapter);
            $res = $pm->aplicarWebhook($v);
            if (empty($res['ok']) || !empty($res['duplicado'])) {
                return new Respuesta('', 200);
            }

            $wp = $db->fetch('SELECT conversacion_id FROM wa_pedidos WHERE pedido_id = ?', [(int) $res['pedido_id']]);
            if (!$wp) {
                return new Respuesta('', 200);
            }
            $conv = $db->fetch('SELECT * FROM wa_conversaciones WHERE id = ?', [(int) $wp['conversacion_id']]);
            $canal = EvolutionClient::desdeConfig($db);
            if (!$conv || !$canal) {
                return new Respuesta('', 200);
            }

            $textos = [
                'PAYMENT_VERIFIED' => '✅ ¡Pago confirmado! Tu cita quedó agendada. La invitación con el enlace de la videollamada llega a tu correo.',
                'PAYMENT_REJECTED' => '❌ El pago fue rechazado. Puedes intentarlo de nuevo cuando quieras.',
                'PAYMENT_REVIEW_REQUIRED' => '⏳ Recibimos tu pago pero necesita una revisión rápida. Te confirmamos en un momento.',
            ];
            $texto = $textos[$res['estado']] ?? null;
            if ($texto) {
                $canal->enviarTexto((string) $conv['telefono'], $texto);
                (new ConversationManager($db))->guardarMensaje((int) $conv['id'], 'saliente',
                    ['tipo' => 'texto', 'contenido' => $texto]);
            }
            if ($res['estado'] === 'PAYMENT_REVIEW_REQUIRED') {
                (new HumanHandoff($db, $log))->transferir((int) $conv['id'], 'Pago que necesita revisión', $canal, $cfg);
            }
        } catch (\Throwable $e) {
            $log->error('Fallo aplicando el evento de pago: ' . $e->getMessage());
        }

        return new Respuesta('', 200);
    }

    /* ── Interno ──────────────────────────────────────────────────────── */

    /**
     * Autentica el token de 256 bits de la URL contra el hash guardado.
     * Devuelve la configuración, o null (y el llamador responde 404 seco,
     * sin cuerpo con pistas y sin eco del valor recibido).
     */
    private function autenticar($db, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $cfg = WaConfig::cargar($db);
        if (!$cfg || empty($cfg['webhook_token_hash'])) {
            return null;
        }
        if (!hash_equals((string) $cfg['webhook_token_hash'], hash('sha256', $token))) {
            return null;
        }

        return $cfg;
    }

    /**
     * Cierra la respuesta HTTP y deja el proceso trabajando: es lo que evita
     * que Evolution o la pasarela reintenten por impaciencia. Si el servidor
     * no lo permite, se degrada a seguir en el mismo request.
     */
    private function responder200Ya(): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Connection: close');
            $json = '{"ok":true}';
            header('Content-Length: ' . strlen($json));
            echo $json;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }
        @ignore_user_abort(true);
        @set_time_limit(120);
    }
}
