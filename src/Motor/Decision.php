<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * Qué hizo el motor con un turno, y por qué.
 *
 * Devuelve el motivo, no un booleano (`docs/CONTRATOS.md` §Errores 15). Sin
 * esto, la respuesta a «¿por qué el bot no contestó?» sería «no contestó», que
 * cubre siete situaciones distintas: la IA estaba apagada, el kill switch
 * estaba puesto, faltaba consentimiento, se agotó el presupuesto, se acumuló
 * una ráfaga, se escaló, o se pasó el tope de turnos. Cada una pide algo
 * distinto de quien la lee.
 */
final readonly class Decision
{
    /** Se respondió (nota privada en modo sombra). */
    public const RESPONDIO = 'respondio';

    /** Se escaló a humano; la IA queda apagada en esa conversación. */
    public const ESCALO = 'escalo';

    /** Mensaje acumulado en el buffer de ráfaga; aún no toca responder. */
    public const ACUMULADO = 'acumulado';

    /** No se hizo nada, y el motivo dice cuál de las razones fue. */
    public const SILENCIO = 'silencio';

    public function __construct(
        public string $tipo,
        public string $motivo,
        public ?string $casoId = null,
        public ?string $textoEntregado = null,
        public ?AnalisisAccion $analisis = null,
    ) {
    }

    public static function respondio(
        string $texto,
        ?string $casoId = null,
        ?AnalisisAccion $analisis = null,
    ): self {
        return new self(self::RESPONDIO, 'ok', $casoId, $texto, $analisis);
    }

    public static function escalo(MotivoEscalamiento $motivo, ?string $casoId = null): self
    {
        return new self(self::ESCALO, $motivo->value, $casoId);
    }

    public static function acumulado(int $enBuffer): self
    {
        return new self(self::ACUMULADO, "ráfaga: {$enBuffer} mensaje(s) en espera");
    }

    public static function silencio(string $motivo): self
    {
        return new self(self::SILENCIO, $motivo);
    }
}
