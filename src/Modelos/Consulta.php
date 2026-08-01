<?php

declare(strict_types=1);

namespace App\Modelos;

/**
 * Una asesoría reservada.
 *
 * `precioCop` va en **pesos enteros** (ADR-010) y está congelado al reservar:
 * subir la tarifa desde el panel no toca las reservas vivas. Esa es la razón
 * de que el precio esté duplicado entre `modalidades_asesoria` y `consultas`.
 */
final readonly class Consulta
{
    public function __construct(
        public string $id,
        public string $casoId,
        public string $contactoId,
        public string $modalidadId,
        public string $fecha,
        public string $horaInicio,
        public string $horaFin,
        public string $estado,
        public int $precioCop,
        public ?string $reservaExpira,
        public ?string $enlaceReunion,
        public string $creadoEn,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (string) $fila['id'],
            casoId: (string) $fila['caso_id'],
            contactoId: (string) $fila['contacto_id'],
            modalidadId: (string) $fila['modalidad_id'],
            fecha: (string) $fila['fecha'],
            horaInicio: (string) $fila['hora_inicio'],
            horaFin: (string) $fila['hora_fin'],
            estado: (string) $fila['estado'],
            precioCop: (int) $fila['precio_cop'],
            reservaExpira: $fila['reserva_expira'] !== null ? (string) $fila['reserva_expira'] : null,
            enlaceReunion: $fila['enlace_reunion'] !== null ? (string) $fila['enlace_reunion'] : null,
            creadoEn: (string) $fila['creado_en'],
        );
    }

    /** Ocupa el cupo: cuenta para el solapamiento y para el índice único. */
    public function viva(): bool
    {
        return in_array($this->estado, ['reservada', 'pagada', 'realizada'], true);
    }

    /**
     * Solo el webhook verificado por firma de la pasarela la pone en `pagada`
     * (regla 6). Nunca la palabra del contacto ni la del modelo.
     */
    public function pagada(): bool
    {
        return $this->estado === 'pagada' || $this->estado === 'realizada';
    }
}
