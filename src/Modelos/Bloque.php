<?php

declare(strict_types=1);

namespace App\Modelos;

/**
 * Un bloque de la landing, tal como lo edita el abogado desde el panel.
 *
 * `contenido` es JSON libre a propósito: cada bloque tiene su forma (el hero
 * lleva imagen y CTA, `casos` lleva dos listas, `proceso` lleva pasos). La
 * plantilla de cada bloque sabe qué esperar; el modelo solo da acceso seguro.
 */
final readonly class Bloque
{
    /** @param array<string,mixed> $contenido */
    public function __construct(
        public string $clave,
        public ?string $titulo,
        public ?string $subtitulo,
        public array $contenido,
        public int $orden,
        public bool $visible,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        $contenido = json_decode((string) $fila['contenido'], true);

        return new self(
            clave: (string) $fila['clave'],
            titulo: $fila['titulo'] !== null ? (string) $fila['titulo'] : null,
            subtitulo: $fila['subtitulo'] !== null ? (string) $fila['subtitulo'] : null,
            contenido: is_array($contenido) ? $contenido : [],
            orden: (int) $fila['orden'],
            visible: (bool) $fila['visible'],
        );
    }

    public function texto(string $clave, string $porDefecto = ''): string
    {
        $valor = $this->contenido[$clave] ?? null;

        return is_string($valor) && $valor !== '' ? $valor : $porDefecto;
    }

    /** @return list<mixed> */
    public function lista(string $clave): array
    {
        $valor = $this->contenido[$clave] ?? null;

        return is_array($valor) ? array_values($valor) : [];
    }
}
