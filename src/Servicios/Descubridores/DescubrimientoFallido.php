<?php

declare(strict_types=1);

namespace App\Servicios\Descubridores;

/**
 * El proveedor no pudo ser consultado.
 *
 * Se distingue explícitamente de «el proveedor respondió y no tiene
 * modelos», que no ocurre nunca en la práctica y que, si ocurriera,
 * significaría algo muy distinto. Ver `Descubridor`.
 */
final class DescubrimientoFallido extends \RuntimeException
{
    public static function red(string $proveedor, string $detalle): self
    {
        return new self("No hubo respuesta de {$proveedor}: {$detalle}");
    }

    public static function estado(string $proveedor, int $estado): self
    {
        $pista = match (true) {
            $estado === 401 || $estado === 403 => ' (credencial rechazada)',
            $estado === 404 => ' (¿base_url equivocada?)',
            $estado >= 500 => ' (fallo del proveedor)',
            default => '',
        };

        return new self("{$proveedor} respondió {$estado}{$pista}");
    }

    public static function cuerpo(string $proveedor): self
    {
        return new self("{$proveedor} respondió algo que no es el catálogo esperado");
    }
}
