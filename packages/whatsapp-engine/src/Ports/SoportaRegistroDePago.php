<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Dejar constancia del cobro en la contabilidad del negocio: la caja, el
 * arqueo, los reportes.
 *
 * El motor sabe SI un pago está verificado —lo consulta a la pasarela— pero no
 * sabe dónde apunta el dinero este negocio: en un bar es `pagos` con su
 * `cliente_mesa_id`; en una tienda es un movimiento de caja contra una venta.
 * Tenerlo escrito dentro del motor obligaba al segundo proyecto a inventarse
 * las tablas del primero.
 *
 * El negocio que no lo implemente cobra igual; simplemente el asiento lo hace
 * a mano quien corresponda.
 */
interface SoportaRegistroDePago
{
    /**
     * @param string $transaccionId  id de la transacción (el que devolvió crearTransaccion)
     * @param array  $pago  ['monto'=>float, 'referencia'=>string, 'metodo'=>string,
     *                       'metodo_pasarela'=>string, 'proveedor'=>string]
     *   `metodo` viene reducido a tarjeta/transferencia, y `metodo_pasarela` es
     *   el valor crudo de la pasarela (`CARD`, `NEQUI`, `PSE`…) por si el
     *   negocio distingue más casillas. Los dos salen de lo que INFORMA la
     *   pasarela y no del modo configurado: leer el modo era lo que metía una
     *   tarjeta en caja como transferencia y descuadraba el arqueo.
     */
    public function registrarPagoEnCaja(string $transaccionId, array $pago): void;
}
