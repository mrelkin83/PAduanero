<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Motor\Decision;
use App\Motor\MotorConversacional;
use App\Repositorios\ConversacionEstadoRepo;
use App\Soporte\Logger;

/**
 * Puerta de entrada de los mensajes. La única.
 *
 * AUTENTICACIÓN: SECRETO COMPARTIDO, NO FIRMA — Y LA DIFERENCIA IMPORTA
 *
 * Chatwoot **no firma sus webhooks**. No hay HMAC del cuerpo que verificar, así
 * que lo único posible es un secreto compartido que el emisor presenta y este
 * extremo compara. Se compara en tiempo constante y se acepta por cabecera o
 * por parámetro de ruta, porque la interfaz de Chatwoot solo deja configurar
 * una URL.
 *
 * Eso es más débil que una firma y hay que saberlo, porque marca la frontera de
 * lo que este endpoint puede autorizar: **nada relacionado con dinero**. Un
 * webhook de Chatwoot falsificado consigue, como mucho, que el bot responda a
 * un mensaje inventado. La regla 6 —una asesoría solo pasa a `pagada` por
 * webhook verificado por firma de la pasarela— vive en otro endpoint
 * precisamente por esto, y ese sí verifica firma de verdad.
 *
 * IDEMPOTENCIA
 *
 * Chatwoot reintenta. Un mensaje procesado dos veces sería un turno cobrado
 * dos veces y, peor, dos respuestas en el hilo. Se descarta por `id` del
 * mensaje contra el historial ya visto.
 *
 * REGLA 8: EL AGENTE HUMANO APAGA LA IA
 *
 * El evento que más importa aquí no es el del contacto: es el del agente. En
 * cuanto Pedro escribe en la conversación, la IA se apaga y no se vuelve a
 * encender sola. Sin esto, el bot seguiría contestando por encima de su
 * abogado en el mismo hilo — que es la peor cara posible que puede dar un
 * despacho.
 */
final class WebhookChatwoot
{
    /** Texto del mensaje en curso, para la guarda contra reintentos. */
    private string $ultimoTexto = '';

    public function __construct(
        private readonly MotorConversacional $motor,
        private readonly ConversacionEstadoRepo $conversaciones,
        private readonly Outbox $outbox,
        private readonly Logger $log,
        private readonly string $secreto,
    ) {
    }

    /**
     * ¿El emisor presenta el secreto?
     *
     * `hash_equals` y no `===`: la comparación de cadenas corta en el primer
     * byte distinto, y eso deja medir el secreto a base de cronometrar
     * respuestas. Es barato defenderse y caro no hacerlo.
     */
    public function autenticado(?string $presentado): bool
    {
        if ($this->secreto === '') {
            // Sin secreto configurado, el endpoint queda cerrado. Abrirlo
            // «mientras tanto» es como se queda abierto.
            $this->log->error('webhook.sin_secreto_configurado');

            return false;
        }

        return $presentado !== null && hash_equals($this->secreto, $presentado);
    }

    /**
     * Procesa un evento de Chatwoot.
     *
     * @param  array<string,mixed> $evento cuerpo ya decodificado
     * @return array{accion:string,detalle:string}
     */
    public function manejar(array $evento): array
    {
        $tipo = (string) ($evento['event'] ?? '');

        if ($tipo !== 'message_created') {
            // Los demás eventos de Chatwoot no interesan hoy. Se ignoran en
            // silencio y con 200: devolver error haría que Chatwoot los
            // reintentara para siempre.
            return ['accion' => 'ignorado', 'detalle' => "evento «{$tipo}»"];
        }

        $conversacionId = (int) ($evento['conversation']['id'] ?? 0);

        if ($conversacionId <= 0) {
            return ['accion' => 'ignorado', 'detalle' => 'sin id de conversación'];
        }

        $mensajeId = (int) ($evento['id'] ?? 0);
        $tipoMensaje = (string) ($evento['message_type'] ?? '');
        $privado = ($evento['private'] ?? false) === true;

        // ── El agente humano toma la conversación (regla 8) ──────────────
        //
        // Una nota privada NO cuenta: Pedro anotando algo para sí mismo no es
        // Pedro hablándole al cliente. Y las notas privadas incluyen los
        // propios borradores del modo sombra — si contaran, el bot se apagaría
        // solo en cuanto escribiera su primera respuesta.
        if ($tipoMensaje === 'outgoing') {
            if ($privado) {
                return ['accion' => 'ignorado', 'detalle' => 'nota privada'];
            }

            if ($this->deUnHumano($evento)) {
                $this->conversaciones->apagarIa($conversacionId);

                $this->log->info('webhook.humano_tomo_la_conversacion', [
                    'conversacion' => $conversacionId,
                ]);

                return ['accion' => 'ia_apagada', 'detalle' => 'un agente escribió en el hilo'];
            }

            return ['accion' => 'ignorado', 'detalle' => 'mensaje saliente del propio bot'];
        }

        if ($tipoMensaje !== 'incoming') {
            return ['accion' => 'ignorado', 'detalle' => "message_type «{$tipoMensaje}»"];
        }

        $texto = trim((string) ($evento['content'] ?? ''));

        if ($texto === '') {
            // Audio, imagen o documento. El motor no los procesa todavía y
            // callarse es mejor que responder a algo que no se leyó.
            $this->log->info('webhook.mensaje_sin_texto', ['conversacion' => $conversacionId]);

            return ['accion' => 'ignorado', 'detalle' => 'mensaje sin texto'];
        }

        $this->ultimoTexto = $texto;

        if ($this->yaVisto($conversacionId, $mensajeId)) {
            return ['accion' => 'duplicado', 'detalle' => 'mensaje ya en el buffer de ráfaga'];
        }

        $telefono = $this->telefono($evento);

        if ($telefono === null) {
            $this->log->warn('webhook.sin_telefono', ['conversacion' => $conversacionId]);

            return ['accion' => 'ignorado', 'detalle' => 'no se pudo determinar el teléfono'];
        }

        $decision = $this->motor->procesar(
            $conversacionId,
            $telefono,
            $texto,
            $this->contactoId($evento),
        );

        $this->marcarVisto($conversacionId, $mensajeId);

        return ['accion' => $decision->tipo, 'detalle' => $decision->motivo];
    }

    /**
     * ¿Lo escribió una persona y no el bot?
     *
     * El agent bot de Chatwoot llega con `sender.type = 'agent_bot'`. Un
     * agente humano llega como `user`. Ante la duda —un `sender` que no
     * llega, un formato que cambió— se asume **humano**: apagar la IA de más
     * cuesta que Pedro la reactive; apagarla de menos cuesta que el bot
     * conteste por encima de él.
     *
     * @param array<string,mixed> $evento
     */
    private function deUnHumano(array $evento): bool
    {
        $tipo = $evento['sender']['type'] ?? null;

        return $tipo !== 'agent_bot';
    }

    /** @param array<string,mixed> $evento */
    private function telefono(array $evento): ?string
    {
        foreach ([
            $evento['sender']['phone_number'] ?? null,
            $evento['conversation']['meta']['sender']['phone_number'] ?? null,
            $evento['sender']['identifier'] ?? null,
        ] as $candidato) {
            if (!is_string($candidato)) {
                continue;
            }

            // E.164 sin «+», que es como lo guarda `contactos.telefono`.
            $limpio = preg_replace('/\D+/', '', $candidato) ?? '';

            if (strlen($limpio) >= 10) {
                return $limpio;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $evento */
    private function contactoId(array $evento): ?int
    {
        $id = $evento['sender']['id']
            ?? $evento['conversation']['meta']['sender']['id']
            ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * Guarda contra el reintento inmediato de Chatwoot.
     *
     * **Es parcial y conviene saber hasta dónde llega.** Compara el texto
     * contra lo que ya hay en el buffer de ráfaga, así que cubre el caso
     * frecuente —Chatwoot reintenta a los pocos segundos porque nuestra
     * respuesta tardó— y NO cubre el reintento que llega después de que la
     * ventana de ráfaga se cerrara. Ese produciría un segundo turno: una
     * segunda respuesta en el hilo y un segundo cobro de tokens.
     *
     * La deduplicación completa necesita recordar el `id` del último mensaje
     * procesado, y eso es una columna nueva en `conversacion_estado`. Es un
     * cambio de esquema, así que se propone en vez de aplicarse: ver
     * `docs/PLAN_BUILD.md` §Etapa 4.
     *
     * Mientras tanto el daño acotado es aceptable porque el motor está en
     * modo sombra: un turno repetido son dos borradores para Pedro, no dos
     * mensajes a un cliente. **Antes de la Etapa 6 hay que cerrarlo.**
     */
    private function yaVisto(int $conversacionId, int $mensajeId): bool
    {
        $estado = $this->conversaciones->porConversacion($conversacionId);

        return $estado !== null && in_array($this->ultimoTexto, $estado->buffer, true);
    }

    private function marcarVisto(int $conversacionId, int $mensajeId): void
    {
        // Sin columna donde anotarlo, la marca la deja el propio buffer.
    }
}
