<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * Lo que la agenda le devuelve al motor tras ejecutar una acción.
 *
 *  · `apendice` — texto de plantilla (horarios reales, enlace de pago,
 *    confirmación) que el motor añade DESPUÉS del texto del modelo. Va
 *    aparte para que lo factual nunca dependa de lo generado.
 *  · `nuevoEstado` — transición de la máquina, si la acción la implica
 *    (una propuesta con enlace pasa a `pendiente_pago`).
 *  · `escalarPagada` — la acción tocó una asesoría ya pagada: eso lo
 *    resuelve una persona, y el motor debe avisar a Pedro.
 */
final readonly class ResultadoAgenda
{
    public function __construct(
        public ?string $apendice,
        public ?Estados $nuevoEstado = null,
        public bool $escalarPagada = false,
    ) {
    }

    public static function nada(): self
    {
        return new self(null);
    }

    public static function apendice(string $texto, bool $escalarPagada = false): self
    {
        return new self($texto, null, $escalarPagada);
    }
}
