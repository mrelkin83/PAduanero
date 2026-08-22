<?php
/**
 * ============================================================================
 * GeminiAdapter — Google Gemini (generateContent)
 * ============================================================================
 * POST {base}/models/{modelo}:generateContent?key=<API key>
 *
 * Gemini es el que más se aleja del formato neutro: los mensajes se llaman
 * `contents`, el rol del asistente es "model", el prompt de sistema va en
 * `system_instruction`, y las herramientas viajan como functionDeclarations con
 * functionCall/functionResponse en vez de ids de llamada. Como no hay id, se
 * fabrica uno estable a partir del nombre para que el resto del motor —que sí
 * razona con ids— no tenga que enterarse.
 */

namespace ElkinLinan\WhatsappAiEngine\Providers;

use ElkinLinan\WhatsappAiEngine\Core\Http;


class GeminiAdapter implements LlmAdapterInterface
{
    const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private $apiKey;
    private $modelo;

    public function __construct(string $apiKey, string $modelo)
    {
        $this->apiKey = $apiKey;
        $this->modelo = $modelo;
    }

    public function nombre(): string { return 'Google Gemini'; }

    public function chat(array $params): array
    {
        $out = ['ok' => false, 'texto' => '', 'tool_calls' => [], 'tokens_in' => 0,
                'tokens_out' => 0, 'modelo' => $this->modelo, 'error' => ''];

        $contents = [];
        foreach (($params['messages'] ?? []) as $m) {
            $rol = $m['role'] ?? 'user';
            if ($rol === 'tool') {
                $resp = json_decode((string)($m['content'] ?? ''), true);
                $contents[] = ['role' => 'user', 'parts' => [[
                    'functionResponse' => [
                        'name' => (string)($m['name'] ?? ''),
                        'response' => ['result' => is_array($resp) ? $resp : (string)($m['content'] ?? '')],
                    ],
                ]]];
                continue;
            }
            if ($rol === 'assistant') {
                $parts = [];
                if (trim((string)($m['content'] ?? '')) !== '') $parts[] = ['text' => $m['content']];
                foreach (($m['tool_calls'] ?? []) as $tc) {
                    $p = ['functionCall' => ['name' => $tc['name'], 'args' => (object)($tc['arguments'] ?? [])]];
                    // La firma de razonamiento vuelve TAL CUAL o Gemini 3 corta
                    // el turno con un 400: «Function call is missing a
                    // thought_signature». Sin esto el modelo pide la
                    // herramienta, se le contesta, y la segunda vuelta muere —
                    // que en el motor se traduce en transferir a una persona una
                    // conversación que iba perfectamente.
                    if (!empty($tc['firma'])) $p['thoughtSignature'] = $tc['firma'];
                    $parts[] = $p;
                }
                if ($parts) $contents[] = ['role' => 'model', 'parts' => $parts];
                continue;
            }
            $contents[] = ['role' => 'user', 'parts' => [['text' => (string)($m['content'] ?? '')]]];
        }

        $cuerpo = ['contents' => $contents, 'generationConfig' => [
            'maxOutputTokens' => (int)($params['max_tokens'] ?? 2048),
        ]];
        if (isset($params['temperature']) && $params['temperature'] !== null) {
            $cuerpo['generationConfig']['temperature'] = (float)$params['temperature'];
        }
        if (!empty($params['system'])) {
            $cuerpo['system_instruction'] = ['parts' => [['text' => $params['system']]]];
        }
        if (!empty($params['tools'])) {
            $cuerpo['tools'] = [['functionDeclarations' => array_map(function ($t) {
                return [
                    'name' => $t['name'],
                    'description' => $t['description'] ?? '',
                    'parameters' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ];
            }, $params['tools'])]];
        }

        $url = self::BASE_URL . '/models/' . rawurlencode($this->modelo) . ':generateContent?key=' . urlencode($this->apiKey);
        $r = Http::json('POST', $url, [], $cuerpo, 90);
        $out['http_status'] = $r['status'];
        if (!$r['ok']) { $out['error'] = $r['error'] ?: 'Sin respuesta del proveedor'; return $out; }

        $partes = $r['json']['candidates'][0]['content']['parts'] ?? [];
        $i = 0;
        foreach ($partes as $p) {
            if (isset($p['text'])) {
                $out['texto'] .= $p['text'];
            } elseif (isset($p['functionCall'])) {
                $out['tool_calls'][] = [
                    // Gemini no da id de llamada: se fabrica uno estable para que
                    // el orquestador pueda casar la respuesta con su petición.
                    'id'        => 'gemini-' . (++$i) . '-' . ($p['functionCall']['name'] ?? ''),
                    'name'      => $p['functionCall']['name'] ?? '',
                    'arguments' => (array)($p['functionCall']['args'] ?? []),
                    // Se guarda para devolvérsela en la vuelta siguiente. El
                    // resto del motor la ignora; solo este adapter la entiende.
                    'firma'     => (string)($p['thoughtSignature'] ?? ''),
                ];
            }
        }
        $out['tokens_in']  = (int)($r['json']['usageMetadata']['promptTokenCount'] ?? 0);
        $out['tokens_out'] = (int)($r['json']['usageMetadata']['candidatesTokenCount'] ?? 0);
        $out['ok'] = true;
        return $out;
    }

    /**
     * Modelos que responden a generateContent pero NO sirven para conversar.
     *
     * Google mete en el mismo catálogo los de voz, imagen, vídeo, robótica y
     * los de investigación profunda. Todos declaran `generateContent`, así que
     * un filtro por método los deja pasar y acaban en el desplegable del panel
     * junto a los de chat. Elegir «lyria-3-clip-preview» para atender clientes
     * no da un error entendible: da respuestas absurdas o un 400 opaco.
     */
    const NO_CONVERSACIONALES = [
        'tts', 'image', 'imagen', 'veo', 'lyria', 'nano-banana', 'embedding',
        'native-audio', 'live', 'robotics', 'computer-use', 'deep-research', 'aqa',
    ];

    private static function esDeChat(string $id): bool
    {
        foreach (self::NO_CONVERSACIONALES as $pista) {
            if (strpos($id, $pista) !== false) return false;
        }
        return true;
    }

    public function listarModelos(): array
    {
        // pageSize alto: por defecto Google pagina y se quedaban modelos fuera.
        $r = Http::json('GET', self::BASE_URL . '/models?pageSize=200&key=' . urlencode($this->apiKey), [], null, 30);
        if (!$r['ok']) return [];
        $out = [];
        foreach (($r['json']['models'] ?? []) as $m) {
            $id = str_replace('models/', '', (string)($m['name'] ?? ''));
            if ($id === '') continue;
            // Solo los que generan contenido: los de embeddings no sirven aquí.
            $metodos = $m['supportedGenerationMethods'] ?? [];
            if ($metodos && !in_array('generateContent', $metodos, true)) continue;
            if (!self::esDeChat($id)) continue;
            $out[] = [
                'modelo_id'      => $id,
                'nombre'         => $m['displayName'] ?? $id,
                'contexto_max'   => (int)($m['inputTokenLimit'] ?? 0),
                'soporta_vision' => true,
                'soporta_tools'  => true,
            ];
        }
        return $out;
    }

    public function validarCredenciales(): array
    {
        $r = Http::json('GET', self::BASE_URL . '/models?key=' . urlencode($this->apiKey), [], null, 20);
        if (in_array($r['status'], [400, 401, 403], true)) {
            return ['ok' => false, 'error' => 'API Key inválida o sin permisos', 'modelos' => 0];
        }
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'modelos' => 0];
        return ['ok' => true, 'error' => '', 'modelos' => count($r['json']['models'] ?? [])];
    }
}
