<?php

declare(strict_types=1);

namespace App\Excepciones;

final class CredencialNoEncontradaException extends \RuntimeException
{
    public static function para(string $servicio, string $clave, string $entorno): self
    {
        // Servicio, clave y entorno son nombres, no secretos: se pueden decir.
        return new self("No hay credencial activa para {$servicio}.{$clave} en {$entorno}.");
    }
}
