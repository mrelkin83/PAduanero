<?php

declare(strict_types=1);

namespace App\Servicios\Descubridores;

/**
 * Un modelo tal y como lo anuncia su proveedor.
 *
 * Lo que NO trae, y es deliberado: precio. Ningún proveedor lo publica en su
 * endpoint de modelos —Anthropic devuelve identificador, nombre, ventanas y
 * capacidades, y nada más—, así que este objeto no puede inventarlo. El
 * costo lo escribe una persona y queda marcado en `costos_verificados`.
 *
 * Tampoco trae `es_primario` ni `activo`: eso no lo decide el proveedor.
 */
final readonly class ModeloDescubierto
{
    /** @param array<string,mixed> $capacidades */
    public function __construct(
        public string $identificador,
        public string $nombreVisible,
        public string $proposito = 'conversacion',
        public ?int $ventanaContexto = null,
        public ?int $maxSalida = null,
        public array $capacidades = [],
    ) {
    }

    /**
     * Propósito deducido del identificador.
     *
     * Es una heurística y se comporta como tal: acierta en los casos obvios
     * («text-embedding-3-large») y en lo demás cae en `conversacion`. No hay
     * riesgo en fallar porque el modelo nace inactivo: si el propósito está
     * mal, la persona que lo revisa lo corrige antes de activarlo.
     */
    public static function propositoDe(string $identificador): string
    {
        $id = mb_strtolower($identificador);

        return match (true) {
            str_contains($id, 'embed') => 'embeddings',
            str_contains($id, 'rerank') => 'clasificacion',
            default => 'conversacion',
        };
    }
}
