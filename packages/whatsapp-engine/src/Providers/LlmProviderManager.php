<?php
/**
 * ============================================================================
 * LlmProviderManager — elige proveedor, ejecuta y aplica el respaldo (§42)
 * ============================================================================
 * Ni el orquestador ni las herramientas saben qué proveedor hay detrás. Aquí se
 * decide, y aquí se registra qué se usó realmente.
 *
 * Regla del §42 que se respeta al pie de la letra: usar el respaldo NO cambia la
 * configuración guardada. El negocio sigue teniendo su proveedor principal; solo
 * este mensaje concreto salió por el otro, y queda dicho en la bitácora.
 */

namespace ElkinLinan\WhatsappAiEngine\Providers;

use ElkinLinan\WhatsappAiEngine\Core\WaConfig;


class LlmProviderManager
{
    /**
     * Proveedores que el panel ofrece. La clave es lo que se guarda en BD.
     *
     * Casi todos hablan el protocolo de OpenAI, así que comparten adapter y lo
     * único que cambia es la URL base. Los modelos NO se listan aquí: se piden
     * a la API de cada proveedor (§13). Así, cuando salga un modelo nuevo,
     * aparece pulsando «Buscar modelos» sin tocar una línea de código.
     */
    const PROVEEDORES = [
        'anthropic'  => 'Anthropic (Claude)',
        'openai'     => 'OpenAI',
        'gemini'     => 'Google Gemini',
        'grok'       => 'Grok (xAI)',
        'deepseek'   => 'DeepSeek',
        'kimi'       => 'Kimi (Moonshot)',
        'kimi_cn'    => 'Kimi (Moonshot China)',
        'glm'        => 'GLM (Z.ai)',
        'glm_cn'     => 'GLM (Zhipu China)',
        'qwen'       => 'Qwen (Alibaba)',
        'qwen_cn'    => 'Qwen (Alibaba China)',
        'minimax'    => 'MiniMax',
        'minimax_cn' => 'MiniMax (China)',
        'groq'       => 'Groq',
        'mistral'    => 'Mistral',
        'openrouter' => 'OpenRouter',
    ];

    private $db;
    private $log;

    public function __construct($db, $log = null)
    {
        $this->db  = $db;
        $this->log = $log;
    }

    /** Construye el adapter de un proveedor concreto. */
    public static function crear(string $proveedor, string $apiKey, string $modelo): ?LlmAdapterInterface
    {
        if ($apiKey === '' || $modelo === '') return null;
        switch ($proveedor) {
            case 'anthropic': return new AnthropicAdapter($apiKey, $modelo);
            case 'gemini':    return new GeminiAdapter($apiKey, $modelo);
            default:
                // Todo lo demás habla el protocolo de OpenAI. Se comprueba
                // contra la tabla de URLs en vez de enumerar los casos: así,
                // añadir un proveedor es una línea en OpenAiAdapter::BASES y no
                // hay que acordarse de tocar también este switch — que es
                // exactamente el olvido que deja un proveedor visible en el
                // desplegable pero muerto al usarlo.
                return isset(OpenAiAdapter::BASES[$proveedor])
                    ? new OpenAiAdapter($apiKey, $modelo, $proveedor)
                    : null;
        }
    }

    /** Adapter principal del negocio, o null si no está configurado. */
    public function principal(): ?LlmAdapterInterface
    {
        $cfg = WaConfig::cargar($this->db);
        if (!$cfg) return null;
        return self::crear((string)$cfg['llm_proveedor'], WaConfig::secreto($cfg, 'llm_api_key'), (string)$cfg['llm_modelo']);
    }

    /**
     * ¿El fallo es un límite de tasa (429) y no un problema de verdad?
     * Público porque los scripts de diagnóstico chocan con los mismos topes que
     * el motor, y duplicar esta detección es garantizar que se desincronicen.
     */
    public static function esLimiteDeTasa(array $res): bool
    {
        if ((int)($res['http_status'] ?? 0) === 429) return true;
        $e = mb_strtolower((string)($res['error'] ?? ''));
        return strpos($e, 'rate limit') !== false
            || strpos($e, 'rate_limit') !== false
            || strpos($e, 'too many requests') !== false;
    }

    /** Más de esto no se espera: hay un cliente mirando el chat. */
    const ESPERA_MAXIMA = 10.0;

    /**
     * Cuánto falta, según lo que dice el propio proveedor.
     *
     * Los mensajes traen el dato en formatos distintos: «try again in 3.28s»,
     * «try again in 29m5.28s», «Please retry in 53.27631766s». Devuelve los
     * segundos tal cual, SIN decidir si merece la pena esperarlos: eso es
     * política del llamador, y no es la misma para el motor —que tiene un
     * cliente mirando el chat— que para un script de diagnóstico, que puede
     * esperar un minuto sin que a nadie le importe.
     *
     * 1.0 cuando no se puede saber: casi siempre es un tope por minuto.
     */
    public static function segundosDeEspera(string $error): float
    {
        if (preg_match('/(?:try again|retry) in\s+(?:(\d+)\s*h)?\s*(?:(\d+)\s*m(?!s))?\s*(?:([\d.]+)\s*(ms|s))?/i', $error, $m)) {
            $seg = 0.0;
            if (!empty($m[1])) $seg += (float)$m[1] * 3600;
            if (!empty($m[2])) $seg += (float)$m[2] * 60;
            if (!empty($m[3])) $seg += (strtolower($m[4] ?? 's') === 'ms') ? ((float)$m[3] / 1000) : (float)$m[3];
            if ($seg > 0) return max(0.5, $seg + 0.3);
        }
        return 1.0;
    }

    /** Adapter de respaldo, o null si el negocio no configuró ninguno. */
    public function respaldo(): ?LlmAdapterInterface
    {
        $cfg = WaConfig::cargar($this->db);
        if (!$cfg || empty($cfg['llm_fallback_proveedor'])) return null;
        // Sin clave propia se reutiliza la principal: es lo normal cuando el
        // respaldo es otro modelo DEL MISMO proveedor.
        $clave = WaConfig::secreto($cfg, 'llm_fallback_api_key') ?: WaConfig::secreto($cfg, 'llm_api_key');
        return self::crear((string)$cfg['llm_fallback_proveedor'], $clave, (string)$cfg['llm_fallback_modelo']);
    }

    /**
     * Ejecuta el chat con respaldo automático. Devuelve la respuesta del adapter
     * más 'proveedor_usado' y 'fue_respaldo'.
     */
    public function chat(array $params, ?int $conversacionId = null): array
    {
        $cfg = WaConfig::cargar($this->db);
        $params['max_tokens']  = $params['max_tokens'] ?? (int)($cfg['llm_max_tokens'] ?? 2048);
        // NULL significa "no enviar": el adapter de Anthropic lo ignora siempre.
        $params['temperature'] = $params['temperature'] ?? (isset($cfg['llm_temperatura']) && $cfg['llm_temperatura'] !== null
                                    ? (float)$cfg['llm_temperatura'] : null);

        $inicio  = microtime(true);
        $adapter = $this->principal();
        if (!$adapter) {
            return ['ok' => false, 'texto' => '', 'tool_calls' => [], 'tokens_in' => 0, 'tokens_out' => 0,
                    'error' => 'No hay proveedor de IA configurado', 'proveedor_usado' => '', 'fue_respaldo' => false];
        }

        $res = $adapter->chat($params);

        // Límite de tasa: se ESPERA Y SE REINTENTA, no se escala.
        //
        // Un 429 dice "vuelve en 3 segundos", no "esto está roto". Sin este
        // reintento, un pico de mensajes hacía que el motor transfiriera
        // conversaciones perfectamente normales a una persona — y en un plan
        // gratuito de cualquier proveedor eso pasa constantemente.
        if (!$res['ok'] && self::esLimiteDeTasa($res)) {
            $espera = self::segundosDeEspera($res['error'] ?? '');
            // Un tope por minuto se aguanta; una cuota agotada durante horas no:
            // ahí se va derecho al respaldo en vez de dormir y fallar igual.
            if ($espera <= self::ESPERA_MAXIMA) {
                if ($this->log) {
                    $this->log->log('llm', 'Límite de tasa del proveedor; se reintenta en ' . $espera . 's',
                        ['proveedor' => $cfg['llm_proveedor'] ?? ''], $conversacionId);
                }
                usleep((int)($espera * 1000000));
                $res = $adapter->chat($params);
            } elseif ($this->log) {
                // Cuota agotada por horas: reintentar es tirar una llamada a la
                // basura. Se va derecho al respaldo.
                $this->log->log('llm', 'Cuota del proveedor agotada; no se reintenta, se pasa al respaldo',
                    ['proveedor' => $cfg['llm_proveedor'] ?? ''], $conversacionId);
            }
        }

        $res['proveedor_usado'] = $cfg['llm_proveedor'] ?? '';
        $res['fue_respaldo']    = false;
        $res['latencia_ms']     = (int)round((microtime(true) - $inicio) * 1000);

        if (!$res['ok']) {
            $respaldo = $this->respaldo();
            if ($respaldo) {
                if ($this->log) {
                    $this->log->log('llm', 'Proveedor principal falló; se usa el respaldo', [
                        'principal' => $cfg['llm_proveedor'] ?? '', 'error' => $res['error'],
                        'respaldo'  => $cfg['llm_fallback_proveedor'] ?? '',
                    ], $conversacionId);
                }
                $inicio2 = microtime(true);
                $res2 = $respaldo->chat($params);
                $res2['proveedor_usado'] = $cfg['llm_fallback_proveedor'] ?? '';
                $res2['fue_respaldo']    = true;
                $res2['latencia_ms']     = (int)round((microtime(true) - $inicio2) * 1000);
                if ($res2['ok']) return $res2;
                // Los dos fallaron: se conserva el error del principal, que es el
                // que el operador tiene que arreglar.
                $res['error'] .= ' (el respaldo también falló: ' . $res2['error'] . ')';
            }
            if ($this->log) {
                $this->log->error('Fallo del proveedor de IA: ' . $res['error'], ['modelo' => $res['modelo'] ?? ''], $conversacionId);
            }
        }
        return $res;
    }
}
