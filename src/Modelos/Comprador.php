<?php

declare(strict_types=1);

namespace App\Modelos;

final readonly class Comprador
{
    public function __construct(
        public string $id,
        public string $nombres,
        public string $apellidos,
        public string $tipoDocumento,
        public string $celular,
        public string $correo,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (string) $fila['id'],
            nombres: (string) $fila['nombres'],
            apellidos: (string) $fila['apellidos'],
            tipoDocumento: (string) $fila['tipo_documento'],
            celular: (string) $fila['celular'],
            correo: (string) $fila['correo'],
        );
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres . ' ' . $this->apellidos);
    }
}
