<?php

declare(strict_types=1);

namespace App\Servicios\ClientesLlm;

/**
 * Este modelo no respondió. La cascada puede intentar con el siguiente.
 *
 * `reintentable` distingue dos cosas que se parecen y no lo son:
 *
 *  · **Sí** (5xx, timeout, red): el modelo está caído. Bajar al suplente
 *    tiene sentido.
 *  · **No** (400, 401, petición mal formada): el problema es nuestro y el
 *    suplente va a fallar igual. Bajar la cascada solo multiplica la latencia
 *    y el gasto antes de dar el mismo error.
 *
 * Ambos casos dejan fila en `consumo_ia`. Un timeout que no deja huella hace
 * que el gasto y la tasa de error mientan, y con ellos el diagnóstico.
 */
final class FalloProveedor extends \RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly bool $reintentable,
        public readonly int $latenciaMs = 0,
    ) {
        parent::__construct($mensaje);
    }

    public static function red(string $detalle, int $latenciaMs): self
    {
        return new self("sin respuesta: {$detalle}", reintentable: true, latenciaMs: $latenciaMs);
    }

    public static function estado(int $estado, string $cuerpo, int $latenciaMs): self
    {
        // 429 es reintentable en el suplente: el límite de tasa es por
        // proveedor y por clave, así que otro modelo de otro proveedor puede
        // contestar. En el mismo proveedor no ayudará, pero eso lo resuelve
        // el orden de la cascada, no esta clase.
        $reintentable = $estado === 429 || $estado >= 500 || $estado === 0;

        return new self(
            "el proveedor respondió {$estado}: " . mb_substr(trim($cuerpo), 0, 200),
            reintentable: $reintentable,
            latenciaMs: $latenciaMs,
        );
    }

    /**
     * Respuesta vacía o cortada por el tope de tokens.
     *
     * Es reintentable porque suele significar que el modelo consumió su
     * presupuesto de salida pensando y no llegó a escribir. Devolverla como
     * buena dejaría al contacto con un mensaje en blanco.
     */
    public static function vacia(string $motivo, int $latenciaMs): self
    {
        return new self("respuesta inutilizable: {$motivo}", reintentable: true, latenciaMs: $latenciaMs);
    }
}
