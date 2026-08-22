<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Productos que el cliente ARMA antes de comprarlos.
 *
 * Un menú del día se elige por partes —entrada, principio, proteína,
 * acompañamientos, bebida— y una hamburguesa lleva adicionales que cuestan
 * aparte. No es propio de la comida: un teléfono con capacidad y color es el
 * mismo problema, y por eso vive en el motor y no en un adaptador.
 *
 * El negocio manda las reglas (cuántas opciones se pueden elegir de cada
 * componente) y el motor las respeta al preguntar. La validación de verdad
 * ocurre igualmente al crear la transacción: lo que el modelo diga es una
 * propuesta, nunca la última palabra.
 */
interface SoportaConfigurables
{
    /**
     * Componentes de un producto, con sus opciones y sus reglas.
     *
     * Cada componente:
     *   ['id', 'name', 'description', 'required' (0|1), 'min_select', 'max_select',
     *    'opciones' => [ ['id', 'name', 'description', 'extra_price'], ... ]]
     *
     * Vacío = el producto no se configura.
     */
    public function componentesDe(int $productoId): array;
}
