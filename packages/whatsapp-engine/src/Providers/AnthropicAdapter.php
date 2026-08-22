<?php
/**
 * ============================================================================
 * AnthropicAdapter — Claude por la Messages API
 * ============================================================================
 * POST https://api.anthropic.com/v1/messages
 * Cabeceras: x-api-key, anthropic-version: 2023-06-01, content-type: application/json
 *
 * TRES DECISIONES QUE NO SON CAPRICHO (verificadas contra la referencia oficial
 * de la API antes de escribir esto, no de memoria):
 *
 * 1. NUNCA se envía `temperature`. Los modelos actuales (Opus 5, Opus 4.8/4.7,
 *    Fable 5) la RECHAZAN con un 400, y Sonnet 5 rechaza cualquier valor que no
 *    sea el de por defecto. La columna llm_temperatura sigue existiendo para los
 *    proveedores que sí la aceptan; aquí se ignora a propósito.
 *
 * 2. NO se desactiva el razonamiento. Es tentador (menos tokens, menos latencia),
 *    pero con `thinking: disabled` el modelo escribe a veces la llamada a la
 *    herramienta como TEXTO en lugar de emitir un bloque tool_use: el turno
 *    termina bien, la herramienta NUNCA se ejecuta y no hay error. En un motor
 *    que consulta stock y crea pedidos, ese fallo silencioso es inaceptable.
 *
 * 3. `system` va en su propio campo de primer nivel, NO como un mensaje más.
 *    Es donde la API lo espera y donde el caché de prompt lo aprovecha.
 *
 * En los modelos que razonan, max_tokens cubre razonamiento + respuesta: por eso
 * el valor por defecto de wa_config es 2048 y no 1024.
 */

namespace ElkinLinan\WhatsappAiEngine\Providers;

use ElkinLinan\WhatsappAiEngine\Core\Http;


class AnthropicAdapter implements LlmAdapterInterface
{
    const BASE_URL = 'https://api.anthropic.com/v1';
    const VERSION  = '2023-06-01';

    private $apiKey;
    private $modelo;

    public function __construct(string $apiKey, string $modelo)
    {
        $this->apiKey = $apiKey;
        $this->modelo = $modelo;
    }

    public function nombre(): string { return 'Anthropic (Claude)'; }

    private function cabeceras(): array
    {
        return ['x-api-key: ' . $this->apiKey, 'anthropic-version: ' . self::VERSION];
    }

    public function chat(array $params): array
    {
        $out = ['ok' => false, 'texto' => '', 'tool_calls' => [], 'tokens_in' => 0,
                'tokens_out' => 0, 'modelo' => $this->modelo, 'error' => ''];

        $cuerpo = [
            'model'      => $this->modelo,
            'max_tokens' => (int)($params['max_tokens'] ?? 2048),
            'messages'   => $this->convertirMensajes($params['messages'] ?? []),
        ];
        if (!empty($params['system'])) $cuerpo['system'] = $params['system'];
        if (!empty($params['tools'])) {
            $cuerpo['tools'] = array_map(function ($t) {
                return [
                    'name'         => $t['name'],
                    'description'  => $t['description'] ?? '',
                    // Anthropic llama input_schema a lo que otros llaman parameters
                    'input_schema' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ];
            }, $params['tools']);
        }

        $r = Http::json('POST', self::BASE_URL . '/messages', $this->cabeceras(), $cuerpo, 90);
        $out['http_status'] = $r['status'];
        if (!$r['ok']) {
            $out['error'] = $r['error'] ?: 'Sin respuesta del proveedor';
            return $out;
        }

        $j = $r['json'] ?? [];
        foreach (($j['content'] ?? []) as $bloque) {
            $tipo = $bloque['type'] ?? '';
            if ($tipo === 'text') {
                $out['texto'] .= $bloque['text'] ?? '';
            } elseif ($tipo === 'tool_use') {
                $out['tool_calls'][] = [
                    'id'        => $bloque['id'] ?? '',
                    'name'      => $bloque['name'] ?? '',
                    'arguments' => is_array($bloque['input'] ?? null) ? $bloque['input'] : [],
                ];
            }
            // Los bloques de razonamiento se ignoran: no se muestran al cliente
            // y su contenido no se guarda en la bitácora.
        }
        $out['tokens_in']  = (int)($j['usage']['input_tokens'] ?? 0);
        $out['tokens_out'] = (int)($j['usage']['output_tokens'] ?? 0);
        $out['modelo']     = $j['model'] ?? $this->modelo;

        // Un rechazo de los clasificadores de seguridad llega como 200 con
        // stop_reason='refusal' y `content` vacío. Sin esta rama, el motor
        // enviaría un mensaje en blanco al cliente.
        if (($j['stop_reason'] ?? '') === 'refusal') {
            $out['error'] = 'El proveedor rechazó la solicitud por sus políticas de seguridad';
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * Traduce el formato neutro al de Anthropic.
     *
     * El detalle que rompe si se hace mal: los resultados de herramientas
     * CONSECUTIVOS deben ir como varios bloques dentro de UN SOLO mensaje de
     * usuario. Mandarlos en mensajes separados hace que el modelo deje de pedir
     * herramientas en paralelo — y con varios productos por pedido, eso es lo
     * normal aquí.
     */
    private function convertirMensajes(array $mensajes): array
    {
        $out = [];
        $pendientesTool = [];

        $volcarTools = function () use (&$out, &$pendientesTool) {
            if ($pendientesTool) {
                $out[] = ['role' => 'user', 'content' => $pendientesTool];
                $pendientesTool = [];
            }
        };

        foreach ($mensajes as $m) {
            $rol = $m['role'] ?? 'user';
            if ($rol === 'tool') {
                $pendientesTool[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string)($m['tool_call_id'] ?? ''),
                    'content'     => (string)($m['content'] ?? ''),
                    'is_error'    => !empty($m['is_error']),
                ];
                continue;
            }
            $volcarTools();

            if ($rol === 'assistant') {
                $bloques = [];
                if (trim((string)($m['content'] ?? '')) !== '') {
                    $bloques[] = ['type' => 'text', 'text' => $m['content']];
                }
                foreach (($m['tool_calls'] ?? []) as $tc) {
                    $bloques[] = [
                        'type'  => 'tool_use',
                        'id'    => $tc['id'],
                        'name'  => $tc['name'],
                        'input' => (object)($tc['arguments'] ?? []),
                    ];
                }
                if ($bloques) $out[] = ['role' => 'assistant', 'content' => $bloques];
                continue;
            }
            $out[] = ['role' => 'user', 'content' => (string)($m['content'] ?? '')];
        }
        $volcarTools();
        return $out;
    }

    public function listarModelos(): array
    {
        $r = Http::json('GET', self::BASE_URL . '/models?limit=100', $this->cabeceras(), null, 30);
        if (!$r['ok']) return [];
        $out = [];
        foreach (($r['json']['data'] ?? []) as $m) {
            $out[] = [
                'modelo_id'      => $m['id'] ?? '',
                'nombre'         => $m['display_name'] ?? ($m['id'] ?? ''),
                'contexto_max'   => (int)($m['max_input_tokens'] ?? 0),
                'soporta_vision' => !empty($m['capabilities']['image_input']['supported']),
                'soporta_tools'  => true,
            ];
        }
        return $out;
    }

    public function validarCredenciales(): array
    {
        $r = Http::json('GET', self::BASE_URL . '/models?limit=1', $this->cabeceras(), null, 20);
        if ($r['status'] === 401) return ['ok' => false, 'error' => 'API Key inválida', 'modelos' => 0];
        if (!$r['ok'])            return ['ok' => false, 'error' => $r['error'], 'modelos' => 0];
        return ['ok' => true, 'error' => '', 'modelos' => count($r['json']['data'] ?? [])];
    }
}
