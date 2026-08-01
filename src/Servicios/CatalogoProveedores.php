<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * Proveedores de LLM conocidos, para darlos de alta sin teclear URLs.
 *
 * QUÉ RESUELVE
 *
 * `proveedores_ia` se sembró con tres filas en una migración, así que añadir
 * DeepSeek o Groq exigía escribir SQL. Esto lo convierte en elegir de una
 * lista en el panel.
 *
 * Es un catálogo de **conveniencia**, no una fuente de verdad: la verdad son
 * las filas de `proveedores_ia`. Un proveedor que no esté aquí se da de alta
 * igual, escribiendo su `base_url` a mano — cualquier endpoint compatible con
 * OpenAI sirve.
 *
 * EL DETALLE QUE PARECE UN CAPRICHO Y NO LO ES: `campo_max`
 *
 * OpenAI renombró el parámetro que acota la respuesta: sus modelos recientes
 * exigen `max_completion_tokens` y **rechazan `max_tokens` con un 400**. El
 * resto de proveedores compatibles siguen esperando `max_tokens`. Enviar el
 * equivocado no degrada nada: rompe todas las llamadas a ese proveedor.
 *
 * Vive aquí y no en una columna porque es un detalle del dialecto de cada
 * API, no un dato del negocio, y añadir columna exigía cambio de esquema. Un
 * proveedor personalizado usa `max_tokens`, que es lo que espera la inmensa
 * mayoría.
 *
 * LOS MODELOS DE REFERENCIA NO SE INSERTAN
 *
 * `modelos` es una lista para **enseñar en pantalla** cuando todavía no se ha
 * podido descubrir nada —sin credencial, o con el proveedor caído— para que
 * la ficha no aparezca vacía y sin explicación. **No crea filas en
 * `modelos_ia`.** Un modelo entra al catálogo cuando el proveedor lo anuncia
 * (ADR-016); dar de alta desde una lista escrita a mano sería inventarse que
 * el proveedor lo ofrece, y esa lista envejece.
 */
final class CatalogoProveedores
{
    /**
     * @var array<string,array{
     *     nombre:string, base_url:string, formato_api:string,
     *     campo_max:string, pais_servidor:string, modelos:list<string>
     * }>
     */
    public const CONOCIDOS = [
        'anthropic' => [
            'nombre' => 'Anthropic',
            'base_url' => 'https://api.anthropic.com',
            'formato_api' => 'anthropic',
            'campo_max' => 'max_tokens',
            'pais_servidor' => 'Estados Unidos',
            'modelos' => [
                'claude-opus-5', 'claude-sonnet-5', 'claude-opus-4-8',
                'claude-haiku-4-5',
            ],
        ],
        'openai' => [
            'nombre' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'formato_api' => 'openai_compatible',
            // El único de la lista que pide el nombre nuevo.
            'campo_max' => 'max_completion_tokens',
            'pais_servidor' => 'Estados Unidos',
            'modelos' => ['gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4o', 'gpt-4o-mini'],
        ],
        'deepseek' => [
            'nombre' => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com',
            'formato_api' => 'openai_compatible',
            'campo_max' => 'max_tokens',
            // Dato de cumplimiento, no adorno: si el contenido de los casos
            // sale de Colombia, el aviso de habeas data tiene que declarar
            // transferencia internacional (CLAUDE.md §9).
            'pais_servidor' => 'China',
            'modelos' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'gemini' => [
            'nombre' => 'Google Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'formato_api' => 'openai_compatible',
            'campo_max' => 'max_tokens',
            'pais_servidor' => 'Estados Unidos',
            'modelos' => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'],
        ],
        'openrouter' => [
            'nombre' => 'OpenRouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'formato_api' => 'openai_compatible',
            'campo_max' => 'max_tokens',
            'pais_servidor' => 'Estados Unidos (enruta a terceros)',
            'modelos' => ['anthropic/claude-sonnet-5', 'openai/gpt-4o-mini', 'google/gemini-2.5-flash'],
        ],
        'groq' => [
            'nombre' => 'Groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'formato_api' => 'openai_compatible',
            'campo_max' => 'max_tokens',
            'pais_servidor' => 'Estados Unidos',
            'modelos' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant'],
        ],
        'mistral' => [
            'nombre' => 'Mistral',
            'base_url' => 'https://api.mistral.ai/v1',
            'formato_api' => 'openai_compatible',
            'campo_max' => 'max_tokens',
            'pais_servidor' => 'Francia (UE)',
            'modelos' => ['mistral-large-latest', 'mistral-small-latest'],
        ],
        'xai' => [
            'nombre' => 'xAI (Grok)',
            'base_url' => 'https://api.x.ai/v1',
            'formato_api' => 'openai_compatible',
            'campo_max' => 'max_tokens',
            'pais_servidor' => 'Estados Unidos',
            'modelos' => ['grok-4', 'grok-3', 'grok-3-mini'],
        ],
        'ollama' => [
            'nombre' => 'Ollama (local)',
            'base_url' => 'http://127.0.0.1:11434',
            'formato_api' => 'ollama',
            'campo_max' => 'max_tokens',
            // La única opción sin transferencia internacional de datos.
            'pais_servidor' => 'Colombia (VPS propio)',
            'modelos' => [],
        ],
    ];

    /** Nombre del parámetro que acota la respuesta, por clave de proveedor. */
    public static function campoMax(string $clave): string
    {
        return self::CONOCIDOS[$clave]['campo_max'] ?? 'max_tokens';
    }

    /**
     * Modelos de referencia para enseñar cuando no hay descubrimiento.
     *
     * @return list<string>
     */
    public static function modelosDeReferencia(string $clave): array
    {
        return self::CONOCIDOS[$clave]['modelos'] ?? [];
    }

    /**
     * Los que todavía no están dados de alta, para el desplegable.
     *
     * @param  list<string> $yaDadosDeAlta claves de `proveedores_ia`
     * @return array<string,array<string,mixed>>
     */
    public static function disponibles(array $yaDadosDeAlta): array
    {
        return array_diff_key(self::CONOCIDOS, array_flip($yaDadosDeAlta));
    }
}
