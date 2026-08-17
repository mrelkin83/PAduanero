<?php

declare(strict_types=1);

namespace App\Modelos;

final readonly class Usuario
{
    public function __construct(
        public string $id,
        public string $email,
        public string $nombre,
        public string $rol,
        public int $rolId,
        public bool $totpActivo,
        public bool $activo,
        public int $intentosFallidos,
        public ?string $bloqueadoHasta,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (string) $fila['id'],
            email: (string) $fila['email'],
            nombre: (string) $fila['nombre'],
            rol: (string) ($fila['rol'] ?? ''),
            rolId: (int) $fila['rol_id'],
            totpActivo: (bool) $fila['totp_activo'],
            activo: (bool) $fila['activo'],
            intentosFallidos: (int) $fila['intentos_fallidos'],
            bloqueadoHasta: $fila['bloqueado_hasta'] !== null ? (string) $fila['bloqueado_hasta'] : null,
        );
    }

    /**
     * `super_admin` y `abogado` manejan credenciales de pasarela y aprueban
     * lo que dice el bot. Para esos dos el TOTP no es opcional
     * (docs/PANEL_ADMIN.md §4.1).
     */
    public function exigeTotp(): bool
    {
        return in_array($this->rol, ['super_admin', 'abogado'], true);
    }

    public function primerNombre(): string
    {
        return explode(' ', trim($this->nombre))[0];
    }
}
