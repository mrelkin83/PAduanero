<?php
/**
 * ============================================================================
 * ChannelInterface — abstracción del proveedor de mensajería (§7)
 * ============================================================================
 * Evolution API es HOY el canal, pero no puede ser el único posible. Toda la
 * lógica comercial habla con esta interfaz; la única clase que sabe qué es
 * Evolution es EvolutionClient. Cambiar de proveedor mañana es escribir otra
 * implementación, no tocar el orquestador.
 */

namespace ElkinLinan\WhatsappAiEngine\Channel;

interface ChannelInterface
{
    /** Nombre legible del canal, para la bitácora y el panel. */
    public function nombre(): string;

    /** Credenciales/configuración incompletas: lista de lo que falta. */
    public function requisitosFaltantes(): array;

    /** Estado de la conexión: ['estado'=>'conectado|qr|desconectado|error', 'numero'=>?string, 'mensaje'=>string] */
    public function estado(): array;

    /** Crea la instancia si no existe y devuelve el QR para vincular el teléfono. */
    public function conectar(): array;

    /** Desvincula el teléfono de la instancia. */
    public function desconectar(): array;

    /** Registra la URL del webhook en el proveedor. */
    public function registrarWebhook(string $url): array;

    /** Envía texto. @return ['ok'=>bool,'message_id'=>?string,'error'=>string] */
    public function enviarTexto(string $telefono, string $texto): array;

    /** Envía audio (nota de voz). $audio es el binario ya codificado. */
    public function enviarAudio(string $telefono, string $audioBase64, string $mime = 'audio/ogg'): array;

    /** Envía una imagen con pie de foto opcional. */
    public function enviarImagen(string $telefono, string $imagenBase64, string $caption = ''): array;

    /**
     * Normaliza el cuerpo del webhook a la forma que entiende el motor:
     * ['message_id','telefono','nombre','tipo','texto','media_url','media_mime','from_me','timestamp']
     * Devuelve null si el evento no es un mensaje entrante que debamos procesar.
     */
    public function normalizarWebhook(array $payload): ?array;

    /** Descarga la media de un mensaje entrante. Devuelve el binario o null. */
    public function descargarMedia(array $mensaje): ?string;
}
