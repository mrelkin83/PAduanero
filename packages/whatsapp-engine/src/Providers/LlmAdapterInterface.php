<?php
/**
 * ============================================================================
 * LlmAdapterInterface — un proveedor de IA, calcado de FeEmisorInterface (DIAN)
 * ============================================================================
 * §11 del prompt maestro pide chat/stream/listModels/validateCredentials. Se
 * omite stream() a propósito: WhatsApp no muestra texto token a token — el
 * mensaje se envía entero. Añadirlo sería código muerto (§59).
 *
 * ── FORMATO NEUTRO ─────────────────────────────────────────────────────────
 * Ningún adapter impone su forma al resto del motor. Todos traducen desde y
 * hacia esta estructura:
 *
 *  mensajes[] = [
 *    ['role'=>'user',      'content'=>'texto'],
 *    ['role'=>'assistant', 'content'=>'texto', 'tool_calls'=>[['id','name','arguments'=>[]]]],
 *    ['role'=>'tool',      'tool_call_id'=>'…', 'name'=>'…', 'content'=>'json del resultado'],
 *  ]
 *  tools[] = [['name'=>'…', 'description'=>'…', 'parameters'=><JSON Schema>]]
 *
 * chat() devuelve:
 *  ['ok'=>bool, 'texto'=>string, 'tool_calls'=>[['id','name','arguments'=>array]],
 *   'tokens_in'=>int, 'tokens_out'=>int, 'modelo'=>string, 'error'=>string]
 */

namespace ElkinLinan\WhatsappAiEngine\Providers;

interface LlmAdapterInterface
{
    /** Nombre legible del proveedor. */
    public function nombre(): string;

    /** Una llamada de chat con herramientas. Nunca lanza: los fallos van en 'error'. */
    public function chat(array $params): array;

    /** Modelos que ofrece la API del proveedor. [] si no expone catálogo. */
    public function listarModelos(): array;

    /** Prueba de conexión real. ['ok'=>bool, 'error'=>string, 'modelos'=>int] */
    public function validarCredenciales(): array;
}
