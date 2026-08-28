<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Modelos\Comprador;
use App\Repositorios\CompraCursoRepo;

/**
 * Único punto de verdad de «¿puede este comprador ver esta lección?».
 *
 * Toda ruta que sirva contenido protegido (página de lección, descarga de
 * material, token de video) llama aquí antes de mostrar nada — así la regla
 * vive en un solo lugar, no repetida en cada controlador.
 */
final class AccesoLeccion
{
    public function __construct(private readonly CompraCursoRepo $compras)
    {
    }

    /** @param array<string,mixed> $leccion fila de curso_lecciones (al menos vista_previa_gratis) */
    public function puedeVer(?Comprador $comprador, array $leccion, string $cursoId): bool
    {
        if ((int) $leccion['vista_previa_gratis'] === 1) {
            return true;
        }

        return $comprador !== null && $this->compras->tienePagada($comprador->id, $cursoId);
    }
}
