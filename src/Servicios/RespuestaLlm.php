<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * Lo que devolvió el modelo, con lo que hace falta para cobrarlo y auditarlo.
 *
 * `modeloId` y `modeloIdentificador` van juntos a propósito: el primero sirve
 * para cruzar con `consumo_ia` y el segundo para que un humano entienda el
 * registro sin hacer un JOIN. `huboFallback` dice si respondió el primario o
 * un suplente, que es lo que permite notar que el primario lleva una semana
 * caído sin que nadie se haya enterado.
 */
final readonly class RespuestaLlm
{
    public function __construct(
        public string $texto,
        public int $tokensEntrada,
        public int $tokensSalida,
        public string $modeloId,
        public string $modeloIdentificador,
        public int $latenciaMs,
        public bool $huboFallback = false,
    ) {
    }

    public function tokens(): int
    {
        return $this->tokensEntrada + $this->tokensSalida;
    }
}
