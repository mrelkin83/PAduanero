<?php
/**
 * ============================================================================
 * TtsManager — respuestas habladas (§18): de pago u open source, a elegir
 * ============================================================================
 * Modos (wa_config.tts_modo):
 *   nunca         → siempre texto
 *   siempre       → siempre audio
 *   espejo        → audio si el cliente mandó audio  ← recomendado y por defecto
 *   texto_y_audio → las dos cosas
 *
 * 'espejo' es el que se comporta como una persona: si te mandan una nota de
 * voz, contestas con voz; si te escriben, escribes.
 *
 * Proveedores — sistema MIXTO a propósito: cada negocio elige según su bolsillo
 * y su hardware, detrás de la misma configuración:
 *   elevenlabs · openai → de pago, con API key. La mejor calidad.
 *   piper               → open source autoalojado (OHF-Voice/piper1-gpl), sin
 *                         API key: solo la URL del servidor. Corre en tiempo
 *                         real con CPU sola — apto para el VPS sin GPU.
 *                         Devuelve WAV; Evolution lo convierte al mandarlo.
 *
 * El TTS NUNCA bloquea la respuesta: si falla, el cliente recibe el texto igual.
 * Quedarse mudo porque un proveedor de voz devolvió un 500 sería absurdo.
 */

namespace ElkinLinan\WhatsappAiEngine\Media;

use ElkinLinan\WhatsappAiEngine\Core\Http;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;


class TtsManager
{
    private $proveedor;
    private $apiKey;
    private $voz;
    private $modelo;
    private $modo;
    private $url;

    public function __construct(string $proveedor, string $apiKey, string $voz, string $modelo,
                                string $modo, string $url = '')
    {
        $this->proveedor = $proveedor;
        $this->apiKey    = $apiKey;
        $this->voz       = $voz;
        $this->modelo    = $modelo;
        $this->modo      = $modo ?: 'espejo';
        $this->url       = rtrim($url, '/');
    }

    public static function desdeConfig(?array $cfg): ?self
    {
        $cfg = $cfg ?: [];
        $proveedor = (string)($cfg['tts_proveedor'] ?? '');
        $modo      = (string)($cfg['tts_modo'] ?? 'espejo');
        $urlDef    = \ElkinLinan\WhatsappAiEngine\Engine::config()->ttsUrlPorDefecto();

        // Sin proveedor elegido por el negocio: si la PLATAFORMA ofrece un TTS
        // open source por defecto (Piper autoalojado), se usa ese — el negocio
        // contesta con voz sin configurar nada. Sin default de plataforma, sin voz.
        if ($proveedor === '') {
            if ($urlDef === '') return null;
            return new self('piper', '', (string)($cfg['tts_voice_id'] ?? ''),
                            \ElkinLinan\WhatsappAiEngine\Engine::config()->ttsModeloPorDefecto(), $modo, $urlDef);
        }

        $clave = WaConfig::secreto($cfg, 'tts_api_key');
        $url   = (string)($cfg['tts_url'] ?? '');
        $modelo = (string)($cfg['tts_modelo'] ?? '');
        // Los de pago no funcionan sin clave; el autoalojado, sin URL — y si el
        // negocio eligió Piper sin URL/modelo propios, cae a los de plataforma.
        if ($proveedor === 'piper') {
            if ($url === '')    $url    = $urlDef;
            if ($modelo === '') $modelo = \ElkinLinan\WhatsappAiEngine\Engine::config()->ttsModeloPorDefecto();
            if ($url === '') return null;
        } elseif ($clave === '') { return null; }
        return new self($proveedor, $clave, (string)($cfg['tts_voice_id'] ?? ''),
                        $modelo, $modo, $url);
    }

    public function disponible(): bool
    {
        return $this->proveedor === 'piper' ? $this->url !== '' : $this->apiKey !== '';
    }

    /** ¿Toca contestar con voz este turno? */
    public function debeHablar(bool $clienteMandoAudio): bool
    {
        if (!$this->disponible()) return false;
        switch ($this->modo) {
            case 'siempre':
            case 'texto_y_audio': return true;
            case 'espejo':        return $clienteMandoAudio;
            default:              return false;
        }
    }

    /** ¿Se manda además el texto? */
    public function tambienTexto(): bool
    {
        return $this->modo !== 'siempre';
    }

    /**
     * Sintetiza. @return ['ok'=>bool, 'audio'=>string binario, 'mime'=>string, 'error'=>string]
     */
    public function sintetizar(string $texto): array
    {
        $out = ['ok' => false, 'audio' => '', 'mime' => 'audio/mpeg', 'error' => ''];
        $texto = trim($texto);
        if (!$this->disponible()) { $out['error'] = 'Voz no configurada'; return $out; }
        if ($texto === '')        { $out['error'] = 'Texto vacío'; return $out; }
        // Una nota de voz de tres minutos no la escucha nadie.
        if (mb_strlen($texto) > 900) $texto = mb_substr($texto, 0, 900);

        if ($this->proveedor === 'elevenlabs') {
            if ($this->voz === '') { $out['error'] = 'Falta el identificador de la voz'; return $out; }
            $r = Http::request('POST', 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($this->voz), [
                'headers' => ['xi-api-key: ' . $this->apiKey, 'Content-Type: application/json', 'Accept: audio/mpeg'],
                'body'    => json_encode(['text' => $texto, 'model_id' => $this->modelo ?: 'eleven_multilingual_v2'], JSON_UNESCAPED_UNICODE),
                'timeout' => 90,
            ]);
            if (!$r['ok'] || $r['body'] === '') { $out['error'] = $r['error'] ?: 'Falló la síntesis'; return $out; }
            $out['ok'] = true; $out['audio'] = $r['body'];
            return $out;
        }

        if ($this->proveedor === 'openai') {
            $r = Http::request('POST', 'https://api.openai.com/v1/audio/speech', [
                'headers' => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
                'body'    => json_encode(['model' => $this->modelo ?: 'tts-1',
                                          'voice' => $this->voz ?: 'alloy',
                                          'input' => $texto, 'response_format' => 'mp3'], JSON_UNESCAPED_UNICODE),
                'timeout' => 90,
            ]);
            if (!$r['ok'] || $r['body'] === '') { $out['error'] = $r['error'] ?: 'Falló la síntesis'; return $out; }
            $out['ok'] = true; $out['audio'] = $r['body'];
            return $out;
        }

        if ($this->proveedor === 'piper') {
            // {text, voice?} → WAV crudo. La voz es el nombre del modelo que el
            // servidor tiene descargado (p. ej. es_MX-claude-high); vacía, usa
            // la que arrancó con -m.
            //
            // La ruta cambió entre versiones: la 1.6.x (la de PyPI hoy) expone
            // POST /synthesize; la documentación del repo nuevo dice POST /.
            // Se prueban las dos en ese orden — comprobado contra la 1.6.1.
            $cuerpo = ['text' => $texto];
            if ($this->voz !== '') $cuerpo['voice'] = $this->voz;
            $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
            $r = null;
            foreach (['/synthesize', '/'] as $ruta) {
                $r = Http::request('POST', $this->url . $ruta, [
                    'headers' => ['Content-Type: application/json'],
                    'body'    => $json,
                    'timeout' => 90,
                ]);
                // Un error llega como texto o HTML, nunca como RIFF: se mira la
                // cabecera del cuerpo para no mandarle un stacktrace al cliente
                // como si fuera una nota de voz.
                if ($r['ok'] && strncmp($r['body'], 'RIFF', 4) === 0) {
                    $out['ok'] = true; $out['audio'] = $r['body']; $out['mime'] = 'audio/wav';
                    return $out;
                }
            }
            $out['error'] = $r['error'] ?: ('El servidor de voz no devolvió audio: ' . mb_substr((string)$r['body'], 0, 120));
            return $out;
        }

        $out['error'] = 'Proveedor de voz no soportado';
        return $out;
    }

    /** Voces disponibles, para el desplegable del panel. */
    public function listarVoces(): array
    {
        if (!$this->disponible()) return [];
        $out = [];

        if ($this->proveedor === 'elevenlabs') {
            $r = Http::json('GET', 'https://api.elevenlabs.io/v1/voices', ['xi-api-key: ' . $this->apiKey], null, 30);
            if (!$r['ok']) return [];
            foreach (($r['json']['voices'] ?? []) as $v) {
                $out[] = ['id' => $v['voice_id'] ?? '', 'nombre' => $v['name'] ?? ''];
            }
            return $out;
        }

        if ($this->proveedor === 'piper') {
            // GET /voices → los modelos que el servidor tiene descargados.
            $r = Http::json('GET', $this->url . '/voices', [], null, 15);
            if (!$r['ok'] || !is_array($r['json'])) return [];
            foreach ($r['json'] as $clave => $v) {
                // Tolerante con la forma: lista de objetos o mapa nombre→detalle.
                $id = is_array($v) ? (string)($v['name'] ?? $v['id'] ?? $clave) : (string)(is_string($v) ? $v : $clave);
                if ($id !== '') $out[] = ['id' => $id, 'nombre' => $id];
            }
            return $out;
        }

        return [];
    }

    public function probar(): array
    {
        $r = $this->sintetizar('Hola, esta es una prueba de voz.');
        return ['ok' => $r['ok'], 'error' => $r['error'], 'bytes' => strlen($r['audio'])];
    }
}
