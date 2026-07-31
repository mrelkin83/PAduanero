<?php

declare(strict_types=1);

namespace App\Servicios;

final class SinPermisoException extends \RuntimeException
{
    public function __construct(public readonly string $permiso)
    {
        // El nombre del permiso NO se muestra al usuario: decirle qué permiso
        // le falta le dibuja el mapa de la aplicación. Va al log.
        parent::__construct("Falta el permiso «{$permiso}».");
    }
}
