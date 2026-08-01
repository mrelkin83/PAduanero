<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * Puntaje de lead, 0–100 (`CLAUDE.md` §3.2).
 *
 * Sirve para **una sola cosa**: ordenar la bandeja de Pedro para que atienda
 * primero lo que más lo necesita. Explícitamente NO se usa para negar
 * atención ni para variar el precio, y **nunca se le muestra al contacto**.
 * Un número que mide capacidad de pago no puede llegar a la persona que
 * acaba de perder su mercancía.
 */
final class Puntaje
{
    public static function calcular(
        ?bool $tieneActoAdmin,
        ?string $tipoPersona,
        ?int $valorEstimadoCop,
        ?string $urgencia,
        ?string $entidad,
    ): int {
        $p = 0;

        // Que exista un acto administrativo es la señal más fuerte: significa
        // que hay un procedimiento en marcha con términos corriendo, no una
        // consulta hipotética.
        if ($tieneActoAdmin === true) {
            $p += 25;
        }

        if ($tipoPersona === 'juridica') {
            $p += 15;
        }

        $valor = $valorEstimadoCop ?? 0;
        $p += match (true) {
            $valor >= 500_000_000 => 30,
            $valor >= 100_000_000 => 22,
            $valor >= 20_000_000 => 14,
            $valor > 0 => 6,
            default => 0,
        };

        $p += match ($urgencia) {
            'critica' => 25,
            'alta' => 15,
            'media' => 6,
            default => 0,
        };

        if (in_array($entidad, ['dian', 'polfa'], true)) {
            $p += 5;
        }

        return min(100, $p);
    }
}
