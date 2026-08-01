<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * Máquina de estados de la conversación (`CLAUDE.md` §3.1).
 *
 * Son diez nodos, no uno. El motor de referencia tenía un solo estado
 * (`IA_ACTIVA`) porque su objetivo era reservar un cupo gratis lo antes
 * posible; aquí el objetivo es calificar, cobrar y agendar, y eso obliga a
 * saber en qué punto del embudo va cada conversación.
 *
 * A `HUMANO` se llega desde **cualquier** estado. Es la propiedad que hace
 * que el escalamiento sea de primera clase y no un caso especial.
 */
enum Estados: string
{
    case NUEVO = 'nuevo';
    case CONSENTIMIENTO = 'consentimiento';
    case TRIAGE = 'triage';
    case CALIFICACION = 'calificacion';
    case PROPUESTA = 'propuesta_enviada';
    case PENDIENTE_PAGO = 'pendiente_pago';
    case AGENDADO = 'agendado';
    case HUMANO = 'humano';
    case FUERA_ALCANCE = 'fuera_alcance';
    case CERRADO = 'cerrado';

    /** ¿La IA puede seguir hablando en este estado? */
    public function admiteIa(): bool
    {
        return !in_array($this, [self::HUMANO, self::FUERA_ALCANCE, self::CERRADO], true);
    }

    public static function desde(?string $valor): self
    {
        return self::tryFrom($valor ?? '') ?? self::NUEVO;
    }
}
