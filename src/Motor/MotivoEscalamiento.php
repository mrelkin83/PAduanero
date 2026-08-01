<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * Los seis motivos de escalamiento a humano (`CLAUDE.md` §3.1).
 *
 * `docs/PLAN_BUILD.md` exige en la Etapa 6 probarlos los seis, así que están
 * enumerados y no como cadenas sueltas: una lista cerrada se puede recorrer
 * en una prueba; un `string` no.
 */
enum MotivoEscalamiento: string
{
    case URGENCIA = 'urgencia';
    case SOLICITUD_EXPRESA = 'solicitud_expresa';
    case CASO_SENSIBLE = 'caso_sensible';
    case INCONFORMIDAD = 'inconformidad';
    case LIMITE_TURNOS = 'limite_turnos';
    case ERROR_TECNICO = 'error_tecnico';

    /** ¿Merece sacar a Pedro de lo que esté haciendo? */
    public function esUrgente(): bool
    {
        return $this === self::URGENCIA;
    }

    public function prioridadChatwoot(): string
    {
        return $this->esUrgente() ? 'urgent' : 'high';
    }

    public static function desde(?string $valor): self
    {
        return self::tryFrom($valor ?? '') ?? self::SOLICITUD_EXPRESA;
    }
}
