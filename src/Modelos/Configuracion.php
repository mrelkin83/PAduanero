<?php

declare(strict_types=1);

namespace App\Modelos;

/**
 * Una fila de `configuraciones`. Lleva su propia validación encima: el
 * formulario del panel se genera desde estos metadatos, así que añadir un
 * parámetro nuevo es un INSERT, no un cambio de código.
 */
final readonly class Configuracion
{
    /** @param list<string>|null $opciones */
    public function __construct(
        public string $clave,
        public mixed $valor,
        public string $tipo,
        public string $grupo,
        public string $etiqueta,
        public ?string $ayuda = null,
        public ?float $minimo = null,
        public ?float $maximo = null,
        public ?array $opciones = null,
        public string $rolMinimo = 'abogado',
        public bool $requiereReinicio = false,
        public bool $editableUi = true,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        $opciones = null;
        if (is_string($fila['opciones'] ?? null) && $fila['opciones'] !== '') {
            $decodificado = json_decode((string) $fila['opciones'], true);
            $opciones = is_array($decodificado) ? array_values($decodificado) : null;
        }

        return new self(
            clave: (string) $fila['clave'],
            valor: json_decode((string) $fila['valor'], true),
            tipo: (string) $fila['tipo'],
            grupo: (string) $fila['grupo'],
            etiqueta: (string) $fila['etiqueta'],
            ayuda: $fila['ayuda'] !== null ? (string) $fila['ayuda'] : null,
            minimo: $fila['minimo'] !== null ? (float) $fila['minimo'] : null,
            maximo: $fila['maximo'] !== null ? (float) $fila['maximo'] : null,
            opciones: $opciones,
            rolMinimo: (string) ($fila['rol_minimo'] ?? 'abogado'),
            requiereReinicio: (bool) ($fila['requiere_reinicio'] ?? false),
            editableUi: (bool) ($fila['editable_ui'] ?? true),
        );
    }

    /**
     * Valida un valor nuevo contra el tipo, el rango y las opciones de esta
     * misma fila.
     *
     * @throws \InvalidArgumentException con el motivo en español, que el
     *         panel muestra tal cual
     */
    public function validar(mixed $valor): mixed
    {
        $valor = match ($this->tipo) {
            'entero' => $this->comoEntero($valor),
            'decimal' => $this->comoDecimal($valor),
            'booleano' => $this->comoBooleano($valor),
            'texto', 'lista' => is_string($valor)
                ? $valor
                : throw new \InvalidArgumentException("«{$this->etiqueta}» debe ser texto."),
            'fecha' => $this->comoFecha($valor),
            'json' => is_array($valor)
                ? $valor
                : throw new \InvalidArgumentException("«{$this->etiqueta}» debe ser una estructura JSON."),
            default => throw new \InvalidArgumentException("Tipo desconocido: {$this->tipo}"),
        };

        if ($this->opciones !== null && !in_array($valor, $this->opciones, true)) {
            throw new \InvalidArgumentException(
                "«{$this->etiqueta}» debe ser uno de: " . implode(', ', $this->opciones) . '.'
            );
        }

        return $valor;
    }

    private function comoEntero(mixed $valor): int
    {
        if (!is_int($valor) && !(is_string($valor) && preg_match('/^-?\d+$/', $valor) === 1)) {
            throw new \InvalidArgumentException("«{$this->etiqueta}» debe ser un número entero.");
        }

        return $this->enRango((int) $valor);
    }

    private function comoDecimal(mixed $valor): float
    {
        if (!is_numeric($valor)) {
            throw new \InvalidArgumentException("«{$this->etiqueta}» debe ser un número.");
        }

        return $this->enRango((float) $valor);
    }

    private function comoBooleano(mixed $valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (in_array($valor, ['true', '1', 1], true)) {
            return true;
        }

        if (in_array($valor, ['false', '0', 0], true)) {
            return false;
        }

        throw new \InvalidArgumentException("«{$this->etiqueta}» debe ser verdadero o falso.");
    }

    private function comoFecha(mixed $valor): string
    {
        if (!is_string($valor) || \DateTimeImmutable::createFromFormat('!Y-m-d', $valor) === false) {
            throw new \InvalidArgumentException("«{$this->etiqueta}» debe tener el formato AAAA-MM-DD.");
        }

        return $valor;
    }

    private function enRango(int|float $valor): int|float
    {
        if ($this->minimo !== null && $valor < $this->minimo) {
            throw new \InvalidArgumentException(
                "«{$this->etiqueta}» no puede ser menor que {$this->minimo}."
            );
        }

        if ($this->maximo !== null && $valor > $this->maximo) {
            throw new \InvalidArgumentException(
                "«{$this->etiqueta}» no puede ser mayor que {$this->maximo}."
            );
        }

        return $valor;
    }
}
