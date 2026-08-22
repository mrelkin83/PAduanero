<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Armar un producto en una pantalla en vez de en la conversación.
 *
 * Preguntar componente por componente funciona con dos o tres opciones. Con
 * cinco componentes y veintidós opciones —un menú del día corriente— el chat
 * se hace interminable y el cliente abandona a mitad. Un enlace le enseña lo
 * mismo que vería sentado en la mesa y lo resuelve en segundos.
 *
 * Es OPCIONAL y complementa a [[SoportaConfigurables]]: un negocio puede
 * preguntar por chat y no ofrecer pantalla. El motor elige el camino, pero
 * quien construye el enlace —y quien decide qué caduca y qué se puede usar dos
 * veces— es el proyecto, porque es él quien tiene las URLs y las sesiones.
 */
interface SoportaArmadoEnPantalla
{
    /**
     * URL para que ESTE cliente arme ESTE producto, o null si no se puede.
     *
     * Va atada a la conversación a propósito: un enlace que se filtre no debe
     * poder pedir a nombre de otra persona.
     */
    public function enlaceParaArmar(int $productoId, int $cantidad, array $conversacion): ?string;
}
