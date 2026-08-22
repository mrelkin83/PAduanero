<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Un negocio que vende TIEMPO además de (o en vez de) cosas: una asesoría,
 * una consulta, una valoración. La transacción sigue siendo la de siempre
 * —crearTransaccion reserva, confirmarTransaccion pone a trabajar—; lo que
 * esta interfaz añade es lo único que un pedido de mostrador no tiene: el
 * CUÁNDO.
 *
 * El flujo que el motor arma con esto:
 *
 *   consultar_disponibilidad  →  horariosDisponibles()
 *   registrar_datos_cita      →  se guarda en el contexto de la conversación
 *                                (mismo patrón que registrar_datos_entrega)
 *   crear_pedido              →  crearTransaccion() lee el contexto, valida
 *                                el cupo OTRA VEZ y reserva atómicamente
 *   pago verificado           →  confirmarTransaccion() crea el evento real
 *                                (calendario, sala, lo que el negocio use)
 *
 * La doble validación no es paranoia: entre que el cliente ve los horarios y
 * confirma pueden pasar minutos, y otra conversación pudo quedarse el cupo.
 * horariosDisponibles() es una FOTO; la reserva la decide crearTransaccion(),
 * que para eso es atómica por contrato.
 */
interface SoportaCitas
{
    /**
     * Franjas agendables entre dos instantes, consultadas AHORA contra la
     * agenda real del negocio (su calendario, su tabla de citas, ambos).
     *
     * @param string $desde 'Y-m-d H:i' en hora local del negocio
     * @param string $hasta 'Y-m-d H:i' en hora local del negocio
     * @return array lista de ['inicio' => 'Y-m-d H:i', 'duracion_min' => int];
     *               vacía si no hay nada en ese rango.
     */
    public function horariosDisponibles(string $desde, string $hasta): array;

    /**
     * ¿Esta franja exacta sigue libre? La llama registrar_datos_cita para no
     * guardar en el contexto una hora que ya se fue.
     */
    public function franjaDisponible(string $inicio): bool;

    /**
     * Las citas (confirmadas o reservadas) de UNA conversación, para responder
     * «¿cuándo era lo mío?» sin pedir números.
     */
    public function citasDe(int $conversacionId): array;
}
