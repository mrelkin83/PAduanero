<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Respuesta HTTP saliente.
 *
 * Se llama `RespuestaHttp` y no `Respuesta` porque `App\Core\Respuesta` ya
 * existe y es la que sale hacia el navegador. Son direcciones opuestas y
 * confundirlas en un `use` cuesta una tarde.
 *
 * `errorRed` distinto de null significa que no hubo respuesta: DNS, TLS o
 * timeout. Es un fallo distinto de un 4xx, y mezclarlos hace que alguien
 * revise unas credenciales cuando lo que pasa es que el firewall bloquea.
 */
final readonly class RespuestaHttp
{
    public function __construct(
        public int $estado,
        public string $cuerpo,
        public ?string $errorRed,
        public int $latenciaMs,
    ) {
    }

    public function ok(): bool
    {
        return $this->errorRed === null && $this->estado >= 200 && $this->estado < 300;
    }

    public function huboRespuesta(): bool
    {
        return $this->errorRed === null && $this->estado > 0;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $datos = json_decode($this->cuerpo, true);

        return is_array($datos) ? $datos : [];
    }
}
