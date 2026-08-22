<?php
/**
 * ============================================================================
 * OpenAiAdapter — OpenAI y todo el ecosistema compatible
 * ============================================================================
 * Un solo adapter cubre OpenAI, OpenRouter, Groq, DeepSeek, Mistral y cualquier
 * proveedor que exponga /chat/completions: solo cambia la URL base. Escribir
 * cinco clases idénticas salvo una constante habría sido código copiado (§52).
 *
 * POST {base}/chat/completions · Authorization: Bearer <key>
 */

namespace ElkinLinan\WhatsappAiEngine\Providers;

use ElkinLinan\WhatsappAiEngine\Core\Http;


class OpenAiAdapter implements LlmAdapterInterface
{
    /**
     * URL base por proveedor. Lo único que los distingue.
     *
     * Todas verificadas contra el servidor real: `GET /models` sin credencial
     * responde 401/400 («falta la clave»), que es la prueba de que la ruta
     * existe. Un 404 significaría que la URL está mal — y eso se manifiesta
     * como «el proveedor no devuelve modelos», que no se parece a su causa.
     */
    const BASES = [
        'openai'     => 'https://api.openai.com/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'groq'       => 'https://api.groq.com/openai/v1',
        'deepseek'   => 'https://api.deepseek.com/v1',
        'mistral'    => 'https://api.mistral.ai/v1',
        'grok'       => 'https://api.x.ai/v1',
        // Kimi, GLM, MiniMax y Qwen tienen servidor internacional y servidor en
        // China, con CUENTAS SEPARADAS: una clave sacada en el portal chino NO
        // funciona en el internacional ni al revés, y el error es un 401
        // idéntico al de una clave mal escrita. Por eso van como proveedores
        // distintos: elegir mal se corrige en el desplegable, no depurando.
        // (Que son cuentas distintas no hay que creérselo: el 401 de Qwen
        // internacional remite a alibabacloud.com y el del chino a aliyun.com.)
        'kimi'       => 'https://api.moonshot.ai/v1',
        'kimi_cn'    => 'https://api.moonshot.cn/v1',
        'glm'        => 'https://api.z.ai/api/paas/v4',
        'glm_cn'     => 'https://open.bigmodel.cn/api/paas/v4',
        'minimax'    => 'https://api.minimax.io/v1',
        'minimax_cn' => 'https://api.minimaxi.com/v1',
        'qwen'       => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
        'qwen_cn'    => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
    ];

    private $apiKey;
    private $modelo;
    private $base;
    private $proveedor;

    public function __construct(string $apiKey, string $modelo, string $proveedor = 'openai', string $baseUrl = '')
    {
        $this->apiKey    = $apiKey;
        $this->modelo    = $modelo;
        $this->proveedor = $proveedor;
        $this->base      = rtrim($baseUrl ?: (self::BASES[$proveedor] ?? self::BASES['openai']), '/');
    }

    public function nombre(): string
    {
        $etiquetas = ['openai' => 'OpenAI', 'openrouter' => 'OpenRouter', 'groq' => 'Groq',
                      'deepseek' => 'DeepSeek', 'mistral' => 'Mistral', 'grok' => 'Grok (xAI)',
                      'kimi' => 'Kimi (Moonshot)', 'kimi_cn' => 'Kimi (Moonshot China)',
                      'glm' => 'GLM (Z.ai)', 'glm_cn' => 'GLM (Zhipu China)',
                      'minimax' => 'MiniMax', 'minimax_cn' => 'MiniMax (China)',
                      'qwen' => 'Qwen (Alibaba)', 'qwen_cn' => 'Qwen (Alibaba China)'];
        return $etiquetas[$this->proveedor] ?? ('Compatible OpenAI (' . $this->proveedor . ')');
    }

    private function cabeceras(): array
    {
        return ['Authorization: Bearer ' . $this->apiKey];
    }

    public function chat(array $params): array
    {
        $out = ['ok' => false, 'texto' => '', 'tool_calls' => [], 'tokens_in' => 0,
                'tokens_out' => 0, 'modelo' => $this->modelo, 'error' => ''];

        $mensajes = [];
        if (!empty($params['system'])) {
            $mensajes[] = ['role' => 'system', 'content' => $params['system']];
        }
        foreach (($params['messages'] ?? []) as $m) {
            $rol = $m['role'] ?? 'user';
            if ($rol === 'tool') {
                $mensajes[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string)($m['tool_call_id'] ?? ''),
                    'content' => (string)($m['content'] ?? ''),
                ];
            } elseif ($rol === 'assistant' && !empty($m['tool_calls'])) {
                $mensajes[] = [
                    'role' => 'assistant',
                    'content' => (string)($m['content'] ?? '') ?: null,
                    'tool_calls' => array_map(function ($tc) {
                        return ['id' => $tc['id'], 'type' => 'function', 'function' => [
                            'name' => $tc['name'],
                            'arguments' => json_encode($tc['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
                        ]];
                    }, $m['tool_calls']),
                ];
            } else {
                $mensajes[] = ['role' => $rol, 'content' => (string)($m['content'] ?? '')];
            }
        }

        $cuerpo = [
            'model'      => $this->modelo,
            'messages'   => $mensajes,
            'max_tokens' => (int)($params['max_tokens'] ?? 2048),
        ];
        if (isset($params['temperature']) && $params['temperature'] !== null) {
            $cuerpo['temperature'] = (float)$params['temperature'];
        }
        // Los proveedores que validan el schema de las tools de forma estricta
        // (Groq) rechazan la llamada entera si un modelo manda `null` en un
        // parámetro tipado como string/number. Varios modelos (gpt-oss) mandan
        // null en vez de omitir un parámetro OPCIONAL. Se hace nullable cada
        // propiedad que no esté en `required`, para que el null se acepte sin
        // tener que anotar tool por tool. No toca los parámetros obligatorios.
        if (!empty($params['tools'])) {
            $cuerpo['tools'] = array_map(function ($t) {
                return ['type' => 'function', 'function' => [
                    'name' => $t['name'],
                    'description' => $t['description'] ?? '',
                    'parameters' => self::nullablesLosOpcionales(
                        $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()]),
                ]];
            }, $params['tools']);
        }

        $r = Http::json('POST', $this->base . '/chat/completions', $this->cabeceras(), $cuerpo, 90);
        $out['http_status'] = $r['status'];
        if (!$r['ok']) { $out['error'] = $r['error'] ?: 'Sin respuesta del proveedor'; return $out; }

        // Un error dentro de un 200 (MiniMax y compañía).
        //
        // No todo el ecosistema "compatible" devuelve el error como código HTTP:
        // MiniMax responde 200 con `base_resp.status_code != 0` y sin `choices`.
        // Sin esta comprobación, un fallo de credencial o de saldo se vería como
        // una respuesta correcta y vacía — y el cliente recibiría el silencio.
        $baseResp = $r['json']['base_resp'] ?? null;
        if (is_array($baseResp) && (int)($baseResp['status_code'] ?? 0) !== 0) {
            $out['error'] = trim((string)($baseResp['status_msg'] ?? 'Error del proveedor'))
                          . ' (código ' . (int)$baseResp['status_code'] . ')';
            return $out;
        }
        if (!isset($r['json']['choices'])) {
            $out['error'] = 'El proveedor respondió sin `choices`: '
                          . mb_substr(preg_replace('/\s+/', ' ', (string)($r['body'] ?? '')), 0, 200);
            return $out;
        }

        $msg = $r['json']['choices'][0]['message'] ?? [];
        $out['texto'] = (string)($msg['content'] ?? '');
        foreach (($msg['tool_calls'] ?? []) as $tc) {
            // `arguments` llega como CADENA JSON, no como objeto.
            //
            // OJO CON EL "SIN ARGUMENTOS": al llamar a una herramienta que no
            // exige parámetros, los modelos mandan indistintamente "{}", "",
            // nada… o la cadena "null" (medido: es lo que devuelve Groq/Llama
            // 3.3). json_decode de esos valores no da un array, y tratarlo como
            // "JSON inválido" hacía fallar precisamente las herramientas más
            // usadas —consultar_menu, consultar_promociones— dos veces seguidas,
            // con lo que el motor daba la conversación por perdida y la pasaba a
            // una persona. Nada de eso son argumentos rotos: son ausencia de
            // argumentos.
            $crudo = trim((string)($tc['function']['arguments'] ?? ''));
            if ($crudo === '' || strtolower($crudo) === 'null') {
                $args = [];
                $invalidos = false;
            } else {
                $args = json_decode($crudo, true);
                $invalidos = !is_array($args);
                if ($invalidos) $args = [];
            }
            $out['tool_calls'][] = [
                'id'             => $tc['id'] ?? '',
                'name'           => $tc['function']['name'] ?? '',
                'arguments'      => $args,
                'args_invalidos' => $invalidos,
                // El texto crudo, recortado, para poder diagnosticar. Sin esto,
                // "argumentos ilegibles" es un callejón sin salida en la bitácora.
                'args_crudos'    => $invalidos ? mb_substr($crudo, 0, 300) : '',
            ];
        }
        $out['tokens_in']  = (int)($r['json']['usage']['prompt_tokens'] ?? 0);
        $out['tokens_out'] = (int)($r['json']['usage']['completion_tokens'] ?? 0);
        $out['modelo']     = $r['json']['model'] ?? $this->modelo;
        $out['ok'] = true;
        return $out;
    }

    public function listarModelos(): array
    {
        $r = Http::json('GET', $this->base . '/models', $this->cabeceras(), null, 30);
        if (!$r['ok']) return [];
        $out = [];
        foreach (($r['json']['data'] ?? []) as $m) {
            $id = $m['id'] ?? '';
            if ($id === '') continue;
            $out[] = [
                'modelo_id'      => $id,
                'nombre'         => $m['name'] ?? $id,
                'contexto_max'   => (int)($m['context_length'] ?? ($m['context_window'] ?? 0)),
                'soporta_vision' => false,
                'soporta_tools'  => true,
            ];
        }
        return $out;
    }

    public function validarCredenciales(): array
    {
        $r = Http::json('GET', $this->base . '/models', $this->cabeceras(), null, 20);
        if ($r['status'] === 401) return ['ok' => false, 'error' => 'API Key inválida', 'modelos' => 0];
        if (!$r['ok'])            return ['ok' => false, 'error' => $r['error'], 'modelos' => 0];
        return ['ok' => true, 'error' => '', 'modelos' => count($r['json']['data'] ?? [])];
    }

    /**
     * Hace nullable cada propiedad OPCIONAL (no listada en `required`) de un
     * schema de parámetros: `type: "string"` → `type: ["string","null"]`. Así un
     * modelo que manda `null` para omitir un opcional no rompe la validación
     * estricta de Groq. No toca los obligatorios ni los que ya admiten null.
     */
    private static function nullablesLosOpcionales($schema)
    {
        if (!is_array($schema) || ($schema['type'] ?? '') !== 'object') return $schema;
        $req   = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $props = $schema['properties'] ?? null;
        if (is_array($props)) {
            foreach ($props as $nombre => $def) {
                if (!is_array($def) || in_array($nombre, $req, true)) continue;
                if (isset($def['type']) && is_string($def['type']) && $def['type'] !== 'null') {
                    $props[$nombre]['type'] = [$def['type'], 'null'];
                }
            }
            $schema['properties'] = $props;
        }
        return $schema;
    }
}
