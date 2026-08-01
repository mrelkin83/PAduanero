<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * El resultado de mirar lo que devolvió el modelo.
 *
 * Devuelve el **motivo**, no un booleano ni un `null` (`docs/CONTRATOS.md`
 * §Errores 15). Aquí es donde más se paga esa diferencia: cuando Pedro
 * pregunte por qué el bot respondió raro, «acción inválida» no sirve de nada.
 * «El modelo pidió REGISTRAR_CASO con `fecha_acto: "4 de agosto"` y se
 * descartó la fecha» sí — dice qué hacer con el prompt.
 *
 * `camposDescartados` es la mitad más útil y la que un `null` perdía entera:
 * una acción puede ejecutarse y aun así haber perdido por el camino un dato
 * que el contacto sí dio. Eso no es un error, pero es exactamente lo que
 * explica una ficha de caso incompleta.
 */
final readonly class AnalisisAccion
{
    public const OK = 'ok';
    public const SIN_JSON = 'sin_json';
    public const JSON_INVALIDO = 'json_invalido';
    public const ACCION_DESCONOCIDA = 'accion_desconocida';

    /**
     * @param array<string,string> $camposDescartados campo => por qué se cayó
     */
    public function __construct(
        public ?Accion $accion,
        public string $motivo,
        public array $camposDescartados = [],
        /** Lo que el modelo dijo llamarse, aunque no exista. Para el registro. */
        public ?string $nombreCrudo = null,
    ) {
    }

    public function hayAccion(): bool
    {
        return $this->accion !== null;
    }

    /**
     * Una línea para el registro y para la nota de diagnóstico del panel.
     *
     * Se compone sin incluir valores: dice qué campo se cayó y por qué, nunca
     * el contenido. Un `numero_acto` o una `descripcion` en los logs es
     * justamente lo que la regla 13 quiere fuera de ahí.
     */
    public function explicacion(): string
    {
        $base = match ($this->motivo) {
            self::OK => 'acción ' . ($this->accion?->nombre ?? '?'),
            self::SIN_JSON => 'el modelo no emitió ninguna acción (turno conversacional)',
            self::JSON_INVALIDO => 'el modelo emitió algo que no es JSON válido',
            self::ACCION_DESCONOCIDA => 'el modelo inventó la acción «'
                . ($this->nombreCrudo ?? '?') . '»',
            default => $this->motivo,
        };

        if ($this->camposDescartados === []) {
            return $base;
        }

        $partes = [];

        foreach ($this->camposDescartados as $campo => $porQue) {
            $partes[] = "{$campo} ({$porQue})";
        }

        return $base . ' · descartados: ' . implode(', ', $partes);
    }
}
