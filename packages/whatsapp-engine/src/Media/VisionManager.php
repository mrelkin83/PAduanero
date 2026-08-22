<?php
/**
 * ============================================================================
 * VisionManager — análisis de imágenes y lectura de comprobantes (§19, §20)
 * ============================================================================
 * Dos usos distintos con la misma tubería:
 *   describir()          → qué se ve en la foto, para que el agente responda
 *   extraerComprobante() → datos estructurados de una captura de pago
 *
 * §19 avisa de que visión y OCR no son lo mismo. Aquí se usa VISIÓN de un modelo
 * multimodal, no un OCR clásico: una captura de Nequi o Bancolombia no es texto
 * plano sobre fondo blanco, es una interfaz con logos, colores y jerarquía —
 * justo donde un OCR devuelve una sopa de letras y un modelo con visión entiende
 * qué campo es cuál.
 *
 * LO QUE ESTA CLASE **NO** HACE: aprobar un pago. Lee y extrae. Quien decide es
 * la consulta a la pasarela (§21).
 */

namespace ElkinLinan\WhatsappAiEngine\Media;

use ElkinLinan\WhatsappAiEngine\Core\Http;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use ElkinLinan\WhatsappAiEngine\Providers\OpenAiAdapter;

// Las URL base de los proveedores compatibles viven en OpenAiAdapter y ahora se
// consultan también desde desdeConfig(). Se requiere aquí para que la clase no
// dependa de que alguien más lo haya cargado antes.

class VisionManager
{
    /**
     * Modelos con visión que suele haber en un servidor propio, para el
     * desplegable cuando su API no responda. Ollama, LM Studio y vLLM exponen
     * el contrato de OpenAI, así que aquí entran como un proveedor más — y el
     * único con visión que corre decente en CPU es moondream.
     */
    const MODELOS_LOCALES = ['moondream', 'llava', 'llava:13b', 'qwen2.5vl', 'gemma3'];

    private $proveedor;
    private $apiKey;
    private $modelo;
    private $url;

    public function __construct(string $proveedor, string $apiKey, string $modelo, string $url = '')
    {
        $this->proveedor = $proveedor;
        $this->apiKey    = $apiKey;
        $this->modelo    = $modelo;
        $this->url       = rtrim($url, '/');
    }

    /**
     * Toma la configuración de visión; si el negocio no puso una aparte, cae a
     * la del LLM principal cuando ese proveedor ya sabe ver imágenes. Pedirle al
     * dueño de un bar que configure dos veces la misma clave es maltratarlo.
     */
    public static function desdeConfig(?array $cfg): ?self
    {
        // Config nula (negocio que nunca guardó nada) no corta aquí: el servidor
        // de visión de la PLATAFORMA aplica igual, como ya hace el STT.
        $cfg = $cfg ?: [];
        if (!empty($cfg['vision_proveedor'])) {
            $prov = (string)$cfg['vision_proveedor'];
            $url  = (string)($cfg['vision_url'] ?? '');
            // Servidor propio: sin API key, con URL. Mismo patrón que Piper y
            // que el STT autoalojado.
            if ($prov === 'local') {
                if ($url !== '') {
                    return new self($prov, WaConfig::secreto($cfg, 'vision_api_key'),
                                    (string)($cfg['vision_modelo'] ?: 'moondream'), $url);
                }
                return null;
            }
            $clave = WaConfig::secreto($cfg, 'vision_api_key') ?: WaConfig::secreto($cfg, 'llm_api_key');
            if ($clave !== '') {
                return new self($prov, $clave, (string)($cfg['vision_modelo'] ?: $cfg['llm_modelo']), $url);
            }
        }
        // Servidor de VISIÓN open source de la PLATAFORMA (moondream). Va ANTES
        // del proveedor principal: es un modelo que SÍ ve, mientras que el LLM
        // principal puede NO ser multimodal. Un proveedor compatible con OpenAI
        // (p. ej. Groq con gpt-oss) está en las BASES pero no acepta imágenes, y
        // caer ahí devolvía "content must be a string" y el bot nunca veía nada.
        $urlDef = \ElkinLinan\WhatsappAiEngine\Engine::config()->visionUrlPorDefecto();
        if ($urlDef !== '') {
            $modeloDef = \ElkinLinan\WhatsappAiEngine\Engine::config()->visionModeloPorDefecto() ?: 'moondream';
            return new self('local', '', $modeloDef, $urlDef);
        }
        // Sin servidor de plataforma: se cae al proveedor principal SOLO si es uno
        // que de verdad ve imágenes. Los compatibles-OpenAI genéricos (Groq…) no
        // se asumen multimodales: mejor sin visión que un error en cada foto.
        $prov = (string)($cfg['llm_proveedor'] ?? '');
        $veImagenes = ['anthropic', 'gemini', 'openai'];
        if (in_array($prov, $veImagenes, true)) {
            $clave = WaConfig::secreto($cfg, 'llm_api_key');
            if ($clave !== '') return new self($prov, $clave, (string)$cfg['llm_modelo']);
        }
        return null;
    }

    public function disponible(): bool
    {
        if ($this->modelo === '') return false;
        return $this->proveedor === 'local' ? $this->url !== '' : $this->apiKey !== '';
    }

    /** URL base para los proveedores que hablan el contrato de OpenAI. */
    private function baseOpenAi(): string
    {
        if ($this->proveedor === 'local') {
            return substr($this->url, -3) === '/v1' ? $this->url : $this->url . '/v1';
        }
        return OpenAiAdapter::BASES[$this->proveedor] ?? OpenAiAdapter::BASES['openai'];
    }

    /** Modelos con visión del proveedor configurado, para el desplegable. */
    public function listarModelos(): array
    {
        if ($this->proveedor === 'anthropic' || $this->proveedor === 'gemini') return [];
        $cab = $this->apiKey !== '' ? ['Authorization: Bearer ' . $this->apiKey] : [];
        $r = Http::json('GET', $this->baseOpenAi() . '/models', $cab, null, 20);
        if (!$r['ok'] || empty($r['json']['data'])) {
            return $this->proveedor === 'local' ? self::MODELOS_LOCALES : [];
        }
        $ids = [];
        foreach ($r['json']['data'] as $m) {
            if (!empty($m['id'])) $ids[] = (string)$m['id'];
        }
        sort($ids);
        return $ids ?: ($this->proveedor === 'local' ? self::MODELOS_LOCALES : []);
    }

    public function probar(): array
    {
        if (!$this->disponible()) {
            return ['ok' => false, 'error' => $this->proveedor === 'local'
                ? 'Falta la URL del servidor de visión o el modelo'
                : 'Falta la API Key o el modelo'];
        }
        // Un PNG rojo de 32×32: comprueba el camino entero (autenticación,
        // modelo y formato multimodal) sin gastar una foto de verdad. NO puede
        // ser de 1×1: el preprocesador de imágenes de Ollama (moondream) lo
        // rechaza con "Failed to load image or audio file".
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAK0lEQVRIie3NQREAAAQAQeTSf8RSgt9egNuc7visXu8AAAAAAAAAAADgsAW3HAGA3ycjGgAAAABJRU5ErkJggg==');
        $r = $this->preguntar($png, 'image/png', 'Responde solo con la palabra: ok');
        return ['ok' => !empty($r['ok']), 'error' => $r['error'] ?? ''];
    }

    /** Descripción breve, para que el agente sepa de qué le hablan. */
    public function describir(string $imagen, string $mime): array
    {
        return $this->preguntar($imagen, $mime,
            'Describe en dos frases qué se ve en esta imagen. Si es una captura de un pago o una transferencia, dilo explícitamente.');
    }

    /**
     * Extrae los datos de un comprobante. Se pide JSON estricto y se valida:
     * lo que no venga bien formado se trata como "no se pudo leer", nunca como
     * un pago a medias.
     */
    public function extraerComprobante(string $imagen, string $mime): array
    {
        $instruccion = <<<TXT
Esta imagen debería ser el comprobante de un pago o una transferencia.
Devuelve ÚNICAMENTE un objeto JSON, sin texto alrededor y sin bloques de código, con estas claves:
{"es_comprobante": true|false, "proveedor": "Nequi|Daviplata|Bancolombia|Wompi|otro|null", "valor": número sin puntos ni símbolos o null, "moneda": "COP" o null, "fecha": "YYYY-MM-DD" o null, "hora": "HH:MM" o null, "referencia": "texto" o null, "destino": "a quién se le pagó" o null, "estado": "aprobado|pendiente|rechazado|desconocido"}
Si un dato no se lee con claridad, pon null. No adivines. No inventes una referencia.
TXT;
        $r = $this->preguntar($imagen, $mime, $instruccion);
        if (!$r['ok']) return $r;

        // El modelo puede envolver el JSON en ```json … ``` por costumbre.
        $texto = trim($r['texto']);
        $texto = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $texto);
        if (preg_match('/\{.*\}/s', $texto, $m)) $texto = $m[0];

        $datos = json_decode($texto, true);
        if (!is_array($datos)) {
            return ['ok' => false, 'texto' => $r['texto'], 'datos' => null,
                    'error' => 'No se pudo leer el comprobante'];
        }
        // Normalización: "1.250.000" y "$ 1250000" tienen que acabar igual.
        if (isset($datos['valor']) && $datos['valor'] !== null) {
            $limpio = preg_replace('/[^\d]/', '', (string)$datos['valor']);
            $datos['valor'] = $limpio === '' ? null : (float)$limpio;
        }
        return ['ok' => true, 'texto' => $r['texto'], 'datos' => $datos, 'error' => ''];
    }

    /** Una pregunta sobre una imagen, en el formato de cada proveedor. */
    private function preguntar(string $imagen, string $mime, string $instruccion): array
    {
        $out = ['ok' => false, 'texto' => '', 'error' => ''];
        if (!$this->disponible()) { $out['error'] = 'Análisis de imágenes no configurado'; return $out; }
        $b64 = base64_encode($imagen);
        $mimeBase = strtolower(trim(explode(';', $mime)[0])) ?: 'image/jpeg';

        if ($this->proveedor === 'anthropic') {
            $r = Http::json('POST', 'https://api.anthropic.com/v1/messages',
                ['x-api-key: ' . $this->apiKey, 'anthropic-version: 2023-06-01'], [
                'model' => $this->modelo, 'max_tokens' => 1024,
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeBase, 'data' => $b64]],
                    ['type' => 'text', 'text' => $instruccion],
                ]]],
            ], 90);
            if (!$r['ok']) { $out['error'] = $r['error']; return $out; }
            foreach (($r['json']['content'] ?? []) as $b) {
                if (($b['type'] ?? '') === 'text') $out['texto'] .= $b['text'];
            }
            $out['ok'] = trim($out['texto']) !== '';
            if (!$out['ok']) $out['error'] = 'El modelo no devolvió texto';
            return $out;
        }

        if ($this->proveedor === 'gemini') {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->modelo)
                 . ':generateContent?key=' . urlencode($this->apiKey);
            $r = Http::json('POST', $url, [], [
                'contents' => [['role' => 'user', 'parts' => [
                    ['inline_data' => ['mime_type' => $mimeBase, 'data' => $b64]],
                    ['text' => $instruccion],
                ]]],
                'generationConfig' => ['maxOutputTokens' => 1024],
            ], 90);
            if (!$r['ok']) { $out['error'] = $r['error']; return $out; }
            foreach (($r['json']['candidates'][0]['content']['parts'] ?? []) as $p) {
                if (isset($p['text'])) $out['texto'] .= $p['text'];
            }
            $out['ok'] = trim($out['texto']) !== '';
            if (!$out['ok']) $out['error'] = 'El modelo no devolvió texto';
            return $out;
        }

        // OpenAI, compatibles y el servidor propio: la imagen viaja como data
        // URI. Ollama, LM Studio y vLLM implementan este mismo contrato, así que
        // el autoalojado no necesita un camino aparte.
        $base = $this->baseOpenAi();
        $cab  = $this->apiKey !== '' ? ['Authorization: Bearer ' . $this->apiKey] : [];
        $r = Http::json('POST', $base . '/chat/completions', $cab, [
            'model' => $this->modelo, 'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $instruccion],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeBase . ';base64,' . $b64]],
            ]]],
        ], 90);
        if (!$r['ok']) { $out['error'] = $r['error']; return $out; }
        $out['texto'] = (string)($r['json']['choices'][0]['message']['content'] ?? '');
        $out['ok'] = trim($out['texto']) !== '';
        if (!$out['ok']) $out['error'] = 'El modelo no devolvió texto';
        return $out;
    }
}
