<?php

declare(strict_types=1);

namespace App\Modelos;

/**
 * Un efecto externo pendiente de ejecutar (ADR-004).
 *
 * Existe para que ningún I/O ocurra dentro de una transacción: escribir el
 * caso y avisar a Pedro son dos cosas, y si la segunda tarda o falla no puede
 * arrastrar a la primera. La transacción escribe la fila; el worker la
 * despacha después.
 */
final readonly class EventoOutbox
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public int $id,
        public string $tipo,
        public array $payload,
        public string $estado,
        public int $intentos,
        public ?string $ultimoError,
        public string $creadoEn,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        $payload = $fila['payload'] ?? null;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return new self(
            id: (int) $fila['id'],
            tipo: (string) $fila['tipo'],
            payload: is_array($payload) ? $payload : [],
            estado: (string) $fila['estado'],
            intentos: (int) $fila['intentos'],
            ultimoError: $fila['ultimo_error'] !== null ? (string) $fila['ultimo_error'] : null,
            creadoEn: (string) $fila['creado_en'],
        );
    }

    public function dato(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->payload[$clave] ?? $porDefecto;
    }
}
