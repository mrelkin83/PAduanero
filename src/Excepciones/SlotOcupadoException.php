<?php

declare(strict_types=1);

namespace App\Excepciones;

/**
 * El horario se acaba de tomar.
 *
 * Excepción propia y no un `false` porque el motor tiene que decir algo
 * distinto según por qué falló la reserva, y porque las dos líneas de defensa
 * del ADR-015 —la validación de solapamiento y el índice único— llegan aquí
 * por caminos diferentes y deben ser indistinguibles para quien la captura.
 *
 * No es un error del sistema: es el resultado normal de que dos personas
 * quieran la misma hora. Se traduce a «ese horario se acaba de tomar,
 * ¿le sirve alguno de estos?», nunca a un mensaje de fallo técnico.
 */
final class SlotOcupadoException extends \RuntimeException
{
    public function __construct(
        public readonly string $fecha = '',
        public readonly string $horaInicio = '',
    ) {
        parent::__construct('El horario solicitado ya está ocupado.');
    }
}
