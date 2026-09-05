<?php

declare(strict_types=1);

namespace App\Wa;

use ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient;
use ElkinLinan\WhatsappAiEngine\Core\PromptComposer;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use ElkinLinan\WhatsappAiEngine\Media\TtsManager;
use ElkinLinan\WhatsappAiEngine\Providers\LlmProviderManager;

/**
 * Chats que quedaron sin responder — típicamente porque la línea estuvo
 * desvinculada (pasó del 2026-09-01 al 04: tres días de mensajes al vacío).
 *
 * La fuente es la base de Evolution (`listarChats`), NO `wa_mensajes`: los
 * mensajes que llegan con el motor caído jamás pasan por el webhook, pero el
 * historial que el teléfono sincroniza al revincular sí queda en Evolution.
 * Por lo mismo, el historial puede venir incompleto — se trabaja con lo que
 * hay y el LLM lo sabe.
 *
 * El flujo es en dos tiempos a propósito (decisión del PO, 2026-09-04):
 * el LLM ANALIZA y PROPONE; una persona revisa, edita y decide qué se envía.
 * Nada sale al cliente sin ese ojo humano — es la misma lógica del
 * comprobante de pago que aprueba una persona.
 */
final class PendientesSinResponder
{
    /** Menos de esto no es «sin responder», es una conversación en curso. */
    public const SILENCIO_MINIMO = 1800;

    /** Más viejo que esto ya no se responde: una disculpa de hace un mes incomoda. */
    public const VENTANA = 7 * 86400;

    public function __construct(private readonly DbMotor $db)
    {
    }

    /**
     * Filtra los chats de Evolution a los que esperan respuesta: el último
     * mensaje es DEL CLIENTE, tiene al menos media hora y no más de una
     * semana. Grupos, difusiones y canales quedan fuera — el bot atiende
     * personas.
     *
     * Estática y pura a propósito: la decisión de qué cuenta como pendiente
     * se prueba sin Evolution ni base de datos.
     *
     * @param list<array<string,mixed>> $chats lo que devuelve listarChats()
     * @return list<array{jid:string,telefono:string,nombre:string,tipo:string,texto:string,cuando:int}>
     */
    public static function filtrar(array $chats, int $ahora): array
    {
        $out = [];
        foreach ($chats as $chat) {
            if (!is_array($chat)) {
                continue;
            }
            $jid = (string) ($chat['remoteJid'] ?? '');
            if ($jid === '' || str_contains($jid, '@g.us') || str_contains($jid, '@broadcast')
                || str_contains($jid, '@newsletter')) {
                continue;
            }

            $ultimo = $chat['lastMessage'] ?? null;
            if (!is_array($ultimo) || !empty($ultimo['key']['fromMe'])) {
                continue;
            }

            $cuando = (int) ($ultimo['messageTimestamp'] ?? 0);
            if ($cuando <= 0 || $ahora - $cuando < self::SILENCIO_MINIMO || $ahora - $cuando > self::VENTANA) {
                continue;
            }

            [$tipo, $texto] = self::contenido($ultimo);
            $out[] = [
                'jid' => $jid,
                // Un JID con '@' viaja completo (los @lid no son un número);
                // normalizarNumero lo respeta al enviar.
                'telefono' => str_ends_with($jid, '@s.whatsapp.net')
                    ? (string) strstr($jid, '@', true)
                    : $jid,
                'nombre' => (string) ($chat['pushName'] ?? ($ultimo['pushName'] ?? '')),
                'tipo' => $tipo,
                'texto' => $texto,
                'cuando' => $cuando,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['cuando'] <=> $a['cuando']);

        return $out;
    }

    /**
     * Qué dijo el mensaje, en texto plano; los tipos sin texto se rotulan
     * para que la persona (y el LLM) sepan qué llegó aunque no qué decía.
     *
     * @param array<string,mixed> $mensaje fila de Evolution con `message`
     * @return array{0:string,1:string} [tipo, texto]
     */
    public static function contenido(array $mensaje): array
    {
        $m = is_array($mensaje['message'] ?? null) ? $mensaje['message'] : [];

        $texto = (string) ($m['conversation'] ?? ($m['extendedTextMessage']['text'] ?? ''));
        if ($texto !== '') {
            return ['texto', $texto];
        }
        if (isset($m['audioMessage'])) {
            return ['audio', '[nota de voz]'];
        }
        if (isset($m['imageMessage'])) {
            $pie = (string) ($m['imageMessage']['caption'] ?? '');

            return ['imagen', $pie !== '' ? $pie : '[imagen]'];
        }
        if (isset($m['documentMessage'])) {
            return ['documento', '[documento]'];
        }

        return ['otro', '[mensaje sin texto]'];
    }

    /** Los chats pendientes, ya filtrados. */
    public function listar(EvolutionClient $canal): array
    {
        return self::filtrar($canal->listarChats(), time());
    }

    /**
     * El análisis: el LLM lee el chat (lo que Evolution tenga de él) y
     * propone si responder y con qué texto. La propuesta NO se envía aquí —
     * vuelve al panel para que una persona la revise.
     *
     * @param array{jid:string,telefono:string,nombre:string,tipo:string,texto:string,cuando:int} $pendiente
     * @return array{ok:bool,responder:bool,texto:string,motivo:string,error:string}
     */
    public function proponer(array $pendiente, EvolutionClient $canal): array
    {
        $out = ['ok' => false, 'responder' => false, 'texto' => '', 'motivo' => '', 'error' => ''];

        $cfg = WaConfig::cargar($this->db) ?? [];
        $agente = $this->db->fetch('SELECT * FROM wa_agentes WHERE id = 1') ?? [];

        $historial = $canal->mensajesDeChat($pendiente['jid'], 10);
        $lineas = [];
        foreach (array_reverse($historial) as $m) {
            if (!is_array($m)) {
                continue;
            }
            [, $texto] = self::contenido($m);
            $lineas[] = (empty($m['key']['fromMe']) ? 'Cliente' : 'Despacho') . ': ' . $texto;
        }
        if ($lineas === []) {
            $lineas[] = 'Cliente: ' . $pendiente['texto'];
        }

        // El contexto mínimo que componer() espera: aquí no hay cliente
        // cargado — el chat viene de Evolution, no de wa_conversaciones.
        $ctxCli = [
            'nombre' => $pendiente['nombre'],
            'es_nuevo' => true,
            'pedidos_abiertos' => 0,
            'puntos' => null,
        ];
        $sistema = PromptComposer::componer($cfg, $agente, $ctxCli, []) . "\n\n" . self::tarea();

        $r = (new LlmProviderManager($this->db))->chat([
            'system' => $sistema,
            'messages' => [[
                'role' => 'user',
                'content' => 'Chat sin responder (el mensaje más reciente es del cliente, de hace '
                    . self::haceCuanto($pendiente['cuando']) . "):\n\n"
                    . implode("\n", $lineas),
            ]],
            'max_tokens' => 700,
        ]);
        if (empty($r['ok'])) {
            $out['error'] = (string) ($r['error'] ?? 'El proveedor de IA no respondió.');

            return $out;
        }

        $json = self::pelarJson((string) $r['texto']);
        if ($json === null) {
            $out['error'] = 'La IA no devolvió una propuesta legible.';

            return $out;
        }

        $out['ok'] = true;
        $out['responder'] = !empty($json['responder']);
        $out['texto'] = trim((string) ($json['texto'] ?? ''));
        $out['motivo'] = trim((string) ($json['motivo'] ?? ''));

        return $out;
    }

    /**
     * Sintetiza el texto YA REVISADO y lo envía como nota de voz. Queda
     * registrado en wa_conversaciones/wa_mensajes con la conversación en
     * HUMANO_ATENDIENDO — quien aprobó la respuesta se queda con el chat,
     * igual que al responder a mano desde el panel.
     *
     * @return array{ok:bool,error:string}
     */
    public function enviarNotaDeVoz(string $telefono, string $texto, EvolutionClient $canal): array
    {
        $cfg = WaConfig::cargar($this->db, true) ?? [];
        $prov = (string) ($cfg['tts_proveedor'] ?? '');
        if ($prov === '') {
            return ['ok' => false, 'error' => 'No hay proveedor de voz configurado.'];
        }

        // El TtsManager del turno normal decide según tts_modo; aquí la voz
        // no es negociable — el botón existe para responder HABLANDO.
        $tts = new TtsManager(
            $prov,
            WaConfig::secreto($cfg, 'tts_api_key'),
            (string) ($cfg['tts_voice_id'] ?? ''),
            (string) ($cfg['tts_modelo'] ?? ''),
            'siempre',
            (string) ($cfg['tts_url'] ?? ''),
        );
        $s = $tts->sintetizar($texto);
        if (empty($s['ok'])) {
            return ['ok' => false, 'error' => 'No se pudo sintetizar: ' . (string) $s['error']];
        }

        $env = $canal->enviarAudio($telefono, base64_encode((string) $s['audio']), (string) $s['mime']);
        if (empty($env['ok'])) {
            return ['ok' => false, 'error' => (string) ($env['error'] ?? 'WhatsApp no aceptó el envío.')];
        }

        $conv = $this->db->fetch('SELECT id FROM wa_conversaciones WHERE telefono = ? ORDER BY id DESC LIMIT 1', [$telefono]);
        if ($conv) {
            $this->db->query(
                "UPDATE wa_conversaciones SET estado = 'HUMANO_ATENDIENDO', ultimo_mensaje_at = NOW() WHERE id = ?",
                [$conv['id']],
            );
            $convId = (int) $conv['id'];
        } else {
            $convId = $this->db->insert(
                "INSERT INTO wa_conversaciones (telefono, estado, ultimo_mensaje_at) VALUES (?, 'HUMANO_ATENDIENDO', NOW())",
                [$telefono],
            );
        }
        $this->db->query(
            "INSERT INTO wa_mensajes (conversacion_id, message_id_externo, direccion, tipo, contenido)
             VALUES (?, ?, 'saliente', 'audio', ?)",
            [$convId, $env['message_id'] ?? null, $texto],
        );

        return ['ok' => true, 'error' => ''];
    }

    /** La capa de tarea que se suma al prompt compuesto (reglas incluidas). */
    private static function tarea(): string
    {
        return <<<'TXT'
## Tarea puntual: proponer la respuesta a un chat que quedó sin atender
La línea estuvo desconectada y este chat quedó sin respuesta. NO estás
conversando con el cliente: estás proponiendo UNA respuesta que una persona
del despacho va a revisar antes de enviarla como NOTA DE VOZ.

Devuelve SOLO un objeto JSON, sin comentarios ni markdown:
{"responder": true|false, "motivo": "por qué sí o no, en una frase", "texto": "la respuesta"}

- "responder" es false si el chat no espera nada (un «gracias», un sticker,
  spam, una conversación que ya cerró). En ese caso "texto" va vacío.
- El texto se va a LEER EN VOZ ALTA: nada de enlaces, correos, listas,
  formato ni emojis. Frases cortas, tono cálido y natural, máximo unos
  cuatro o cinco enunciados.
- Empieza disculpándote brevemente por la demora (hubo un inconveniente
  técnico con la línea) solo si el mensaje lleva horas esperando.
- Si el último mensaje es una nota de voz o una imagen que no puedes ver,
  dilo con naturalidad y pide que lo cuente por texto o lo reenvíe.
- No inventes datos del caso ni del cliente. Ante la duda, invita a
  continuar la conversación y ofrece la asesoría del despacho.
TXT;
    }

    /** «2 horas», «3 días» — para que el LLM calibre la disculpa. */
    private static function haceCuanto(int $ts): string
    {
        $seg = max(0, time() - $ts);
        if ($seg < 3600) {
            return (string) intdiv($seg, 60) . ' minutos';
        }
        if ($seg < 86400) {
            return (string) intdiv($seg, 3600) . ' horas';
        }

        return (string) intdiv($seg, 86400) . ' días';
    }

    /** El JSON de la IA, tolerando cercas de código y texto alrededor. */
    private static function pelarJson(string $crudo): ?array
    {
        $crudo = trim($crudo);
        if (preg_match('/\{.*\}/su', $crudo, $m)) {
            $crudo = $m[0];
        }
        $json = json_decode($crudo, true);

        return is_array($json) ? $json : null;
    }
}
