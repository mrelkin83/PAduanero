<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\Csrf;
use App\Core\Peticion;
use App\Modelos\Usuario;
use App\Servicios\Permisos;

/**
 * Lo que todo controlador del panel necesita a mano.
 *
 * Se pasa por constructor en vez de tirar de estado global para que los
 * controladores se puedan probar sin levantar una petición HTTP entera.
 */
final class Contexto
{
    public function __construct(
        public readonly Peticion $peticion,
        public readonly ?Usuario $usuario,
        public readonly Permisos $permisos,
        public readonly Csrf $csrf,
        /** Token de sesión en claro, para poder revocarlo al salir. */
        public readonly ?string $tokenSesion = null,
    ) {
    }

    public function puede(string $permiso): bool
    {
        return $this->permisos->puede($this->usuario, $permiso);
    }

    public function ip(): ?string
    {
        return $this->peticion->ip !== '' ? $this->peticion->ip : null;
    }

    public function actor(): string
    {
        return $this->usuario?->email ?? 'anonimo';
    }

    /** Valor de formulario, ya recortado. */
    public function campo(string $nombre, string $porDefecto = ''): string
    {
        $valor = $this->peticion->formulario[$nombre] ?? $porDefecto;

        return is_string($valor) ? trim($valor) : $porDefecto;
    }

    /**
     * Entrada cruda de $_FILES para ese campo, o [] si no se envió nada.
     *
     * @return array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}
     */
    public function archivo(string $nombre): array
    {
        $valor = $this->peticion->archivos[$nombre] ?? [];

        return is_array($valor) ? $valor : [];
    }
}
