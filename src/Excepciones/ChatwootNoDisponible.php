<?php

declare(strict_types=1);

namespace App\Excepciones;

/**
 * Chatwoot no aceptó la escritura tras agotar los reintentos.
 *
 * Quien la captura la encola en `eventos_outbox` (ADR-004). Que sea excepción
 * y no un `false` es deliberado: un booleano ignorado deja el mensaje perdido
 * en silencio, y «nunca se pierde» es justamente lo que el outbox promete.
 *
 * `agotoReintentos` distingue el fallo transitorio —al que tiene sentido
 * volver desde el worker— del rechazo definitivo, que va a fallar igual
 * dentro de una hora y solo llenaría la cola.
 */
final class ChatwootNoDisponible extends \RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly bool $agotoReintentos,
        public readonly int $intentos = 1,
    ) {
        parent::__construct($mensaje);
    }
}
