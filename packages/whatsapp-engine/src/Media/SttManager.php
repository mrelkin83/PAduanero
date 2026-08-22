<?php
/**
 * ============================================================================
 * SttManager — notas de voz a texto (§17)
 * ============================================================================
 * WhatsApp → Evolution → audio → aquí → texto → orquestador.
 *
 * SISTEMA MIXTO, igual que la voz de respuesta: de pago (OpenAI, Groq, Mistral)
 * u OPEN SOURCE AUTOALOJADO (`local`), que no lleva API key sino URL.
 *
 * Que quepan todos en una sola clase no es suerte: el contrato de transcripción
 * de OpenAI (`/audio/transcriptions`, multipart) es el que reimplementan tanto
 * los de pago como los servidores libres —**Speaches** (antes
 * faster-whisper-server) y las imágenes de whisper.cpp/faster-whisper—, así que
 * cambiar de proveedor es cambiar la URL base y nada más.
 *
 * Si no hay STT configurado, el motor NO se calla: responde pidiendo que
 * escriban. Dejar a alguien sin respuesta porque mandó un audio es peor que
 * pedirle que lo escriba.
 */

namespace ElkinLinan\WhatsappAiEngine\Media;

use ElkinLinan\WhatsappAiEngine\Core\Http;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;


class SttManager
{
    /** Proveedores de pago. `local` no está aquí: su base es la URL del negocio. */
    const BASES = [
        'openai'  => 'https://api.openai.com/v1',
        'groq'    => 'https://api.groq.com/openai/v1',
        'mistral' => 'https://api.mistral.ai/v1',
    ];

    /** Lo que ve el dueño en el desplegable. */
    const PROVEEDORES = [
        'groq'    => 'Groq — whisper-large-v3 (gratis)',
        'openai'  => 'OpenAI — Whisper',
        'mistral' => 'Mistral — Voxtral',
        'local'   => 'Servidor propio (open source)',
    ];

    /** Modelo por defecto de cada proveedor, para no dejar el campo en blanco. */
    const MODELOS_SUGERIDOS = [
        'groq'    => ['whisper-large-v3', 'whisper-large-v3-turbo'],
        'openai'  => ['whisper-1', 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe'],
        'mistral' => ['voxtral-mini-latest'],
        'local'   => ['Systran/faster-whisper-small', 'Systran/faster-whisper-medium', 'whisper-1'],
    ];

    private $proveedor;
    private $apiKey;
    private $modelo;
    private $url;

    public function __construct(string $proveedor, string $apiKey, string $modelo = '', string $url = '')
    {
        $this->proveedor = $proveedor;
        $this->apiKey    = $apiKey;
        $this->modelo    = $modelo ?: (self::MODELOS_SUGERIDOS[$proveedor][0] ?? 'whisper-1');
        $this->url       = rtrim($url, '/');
    }

    public static function desdeConfig(?array $cfg): ?self
    {
        $cfg = $cfg ?: [];
        $proveedor = (string)($cfg['stt_proveedor'] ?? '');
        $urlDef    = \ElkinLinan\WhatsappAiEngine\Engine::config()->sttUrlPorDefecto();

        // Sin proveedor elegido por el negocio: si la PLATAFORMA ofrece un STT
        // open source por defecto (Whisper autoalojado), se usa ese — el bot
        // entiende las notas de voz sin configurar nada. Sin default, no transcribe.
        if ($proveedor === '') {
            if ($urlDef === '') return null;
            return new self('local', '',
                            \ElkinLinan\WhatsappAiEngine\Engine::config()->sttModeloPorDefecto(), $urlDef);
        }

        $clave = WaConfig::secreto($cfg, 'stt_api_key');
        $url   = (string)($cfg['stt_url'] ?? '');
        $modelo = (string)($cfg['stt_modelo'] ?? '');
        // El autoalojado no necesita clave; los de pago no funcionan sin ella. Si
        // el negocio eligió 'local' sin URL/modelo, cae a los de plataforma.
        if ($proveedor === 'local') {
            if ($url === '')    $url    = $urlDef;
            if ($modelo === '') $modelo = \ElkinLinan\WhatsappAiEngine\Engine::config()->sttModeloPorDefecto();
            if ($url === '') return null;
        } elseif ($clave === '') { return null; }
        return new self($proveedor, $clave, $modelo, $url);
    }

    /** URL base efectiva del proveedor configurado. */
    private function base(): string
    {
        if ($this->proveedor === 'local') {
            // Se acepta tanto `http://host:8000` como `http://host:8000/v1`: el
            // dueño copia lo que ve en la documentación de su servidor y no
            // tiene por qué saber si el sufijo va incluido.
            return substr($this->url, -3) === '/v1' ? $this->url : $this->url . '/v1';
        }
        return self::BASES[$this->proveedor] ?? '';
    }

    public function disponible(): bool
    {
        return $this->proveedor === 'local'
            ? $this->url !== ''
            : ($this->apiKey !== '' && isset(self::BASES[$this->proveedor]));
    }

    /**
     * Transcribe. @return ['ok'=>bool, 'texto'=>string, 'error'=>string]
     */
    public function transcribir(string $audio, string $mime = 'audio/ogg', string $idioma = 'es'): array
    {
        $out = ['ok' => false, 'texto' => '', 'error' => ''];
        if (!$this->disponible()) { $out['error'] = 'Transcripción no configurada'; return $out; }
        if ($audio === '')        { $out['error'] = 'Audio vacío'; return $out; }

        $base   = $this->base();
        $limite = '----ControlBarMax' . bin2hex(random_bytes(8));
        $ext    = ['audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
                   'audio/wav' => 'wav', 'audio/webm' => 'webm', 'audio/amr' => 'amr'][$mime] ?? 'ogg';

        $cuerpo  = '';
        $campos  = ['model' => $this->modelo, 'language' => $idioma, 'response_format' => 'json'];
        foreach ($campos as $k => $v) {
            $cuerpo .= "--$limite\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        $cuerpo .= "--$limite\r\n"
                 . "Content-Disposition: form-data; name=\"file\"; filename=\"audio.$ext\"\r\n"
                 . "Content-Type: $mime\r\n\r\n" . $audio . "\r\n"
                 . "--$limite--\r\n";

        // Un servidor propio normalmente no pide Authorization; mandarlo vacío
        // hace que algunos rechacen la petición con 401.
        $cabeceras = ['Content-Type: multipart/form-data; boundary=' . $limite];
        if ($this->apiKey !== '') array_unshift($cabeceras, 'Authorization: Bearer ' . $this->apiKey);

        $r = Http::request('POST', $base . '/audio/transcriptions', [
            'headers' => $cabeceras,
            'body'    => $cuerpo,
            'timeout' => 120,
        ]);
        if (!$r['ok']) { $out['error'] = $r['error'] ?: 'Falló la transcripción'; return $out; }

        $texto = trim((string)($r['json']['text'] ?? ''));
        if ($texto === '') { $out['error'] = 'No se entendió el audio'; return $out; }
        $out['ok'] = true;
        $out['texto'] = $texto;
        return $out;
    }

    public function probar(): array
    {
        if (!$this->disponible()) {
            return ['ok' => false, 'error' => $this->proveedor === 'local'
                ? 'Falta la URL del servidor de transcripción'
                : 'Sin proveedor o sin API Key'];
        }
        $cab = $this->apiKey !== '' ? ['Authorization: Bearer ' . $this->apiKey] : [];
        $r = Http::json('GET', $this->base() . '/models', $cab, null, 20);
        if ($r['status'] === 401) return ['ok' => false, 'error' => 'API Key inválida'];
        if ($r['status'] === 0 && $this->proveedor === 'local') {
            return ['ok' => false, 'error' => 'No responde nadie en esa dirección. ¿Está levantado el contenedor?'];
        }
        return ['ok' => $r['ok'], 'error' => $r['ok'] ? '' : $r['error']];
    }

    /**
     * Modelos que ofrece el proveedor configurado, para el desplegable.
     * Se PREGUNTAN a su API (`/models`) y solo si no responde se cae a la lista
     * sugerida: un servidor propio puede tener descargado cualquier modelo, y
     * enumerarlos a mano aquí sería una lista desactualizada desde el día uno.
     */
    public function listarModelos(): array
    {
        $sugeridos = self::MODELOS_SUGERIDOS[$this->proveedor] ?? [];
        if (!$this->disponible()) return $sugeridos;

        $cab = $this->apiKey !== '' ? ['Authorization: Bearer ' . $this->apiKey] : [];
        $r = Http::json('GET', $this->base() . '/models', $cab, null, 20);
        if (!$r['ok'] || empty($r['json']['data'])) return $sugeridos;

        $ids = [];
        foreach ($r['json']['data'] as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '') continue;
            // De un catálogo entero (Groq y OpenAI listan también los de chat)
            // solo interesan los que transcriben.
            if ($this->proveedor === 'local'
                || stripos($id, 'whisper') !== false
                || stripos($id, 'transcribe') !== false
                || stripos($id, 'voxtral') !== false) {
                $ids[] = $id;
            }
        }
        sort($ids);
        return $ids ?: $sugeridos;
    }
}
