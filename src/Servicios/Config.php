<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\Configuracion;

/**
 * Parámetros operativos del negocio. Viven en la tabla `configuraciones`, no
 * en el .env ni en constantes: añadir uno nuevo es un INSERT y el panel pinta
 * el formulario solo, desde los metadatos de la propia fila.
 */
interface Config
{
    public function get(string $clave, mixed $porDefecto = null): mixed;

    /** @throws \InvalidArgumentException si el valor no pasa la validación de la fila */
    public function set(string $clave, mixed $valor, string $usuarioId, ?string $motivo = null): void;

    /** @return list<Configuracion> para pintar el formulario del panel */
    public function getGrupo(string $grupo): array;

    public function invalidarCache(?string $clave = null): void;
}
