<?php

declare(strict_types=1);

namespace App\Excepciones;

/**
 * No hay modelo que pueda responder.
 *
 * El motivo importa porque cada uno se traduce a una conducta distinta del
 * motor, y confundirlos produce la conducta equivocada en el momento
 * equivocado:
 *
 *  · `presupuesto` — se agotó el gasto del mes. Es una decisión del negocio,
 *    no una avería. Escala a humano y avisa a Pedro.
 *  · `sin_modelo_autorizado` — el primario cayó y ningún suplente ha pasado el
 *    `GateDorado`. **También escala a humano.** Responder con un modelo sin
 *    firma sería exactamente lo que el gate existe para impedir, y justo el
 *    día que importa.
 *  · `proveedor` — todos los modelos autorizados fallaron. Es una avería.
 *    Escala a humano y se registra para diagnóstico.
 */
final class LlmException extends \RuntimeException
{
    public function __construct(
        public readonly string $motivo,
        string $mensaje,
    ) {
        parent::__construct($mensaje);
    }

    public static function presupuestoAgotado(float $gastado, float $tope): self
    {
        return new self(
            'presupuesto',
            sprintf('Presupuesto de IA agotado: USD %.2f de %.2f este mes.', $gastado, $tope),
        );
    }

    public static function sinModeloAutorizado(string $proposito): self
    {
        return new self(
            'sin_modelo_autorizado',
            "No hay ningún modelo autorizado para «{$proposito}». "
            . 'Un modelo sin costo verificado o sin conjunto dorado en verde no responde.',
        );
    }

    public static function proveedoresCaidos(int $intentos): self
    {
        return new self(
            'proveedor',
            "Fallaron los {$intentos} modelo(s) autorizados de la cascada.",
        );
    }
}
