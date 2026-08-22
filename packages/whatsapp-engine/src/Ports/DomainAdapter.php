<?php
/**
 * ============================================================================
 * DomainAdapter — la frontera entre el motor y el negocio
 * ============================================================================
 * De todo el paquete, ESTE es el contrato que decide si el motor sirve para un
 * proyecto nuevo o hay que rehacerlo. Por eso está diseñado mirando dos
 * negocios reales y muy distintos, no uno:
 *
 *   · un bar                   → vende platos, stock por cantidad, manda a cocina
 *   · una tienda de tecnología → vende equipos con IMEI (unidad única), tiene
 *                                garantías y órdenes de servicio técnico
 *
 * DE AHÍ VIENEN LAS DOS DECISIONES QUE MÁS SE NOTAN AQUÍ:
 *
 * 1. El vocabulario es NEUTRO. No hay menu() ni platos: hay buscarItems(). No
 *    hay crearPedido() ni enviarACocina(): hay crearTransaccion() y
 *    confirmarTransaccion(). Un nombre calcado del bar obliga a la tienda a
 *    fingir que tiene cocina.
 *
 * 2. Lo que no es universal NO está aquí. El menú del día es del bar; las
 *    garantías, de la tienda. Meterlos obligaría a cada proyecto a implementar
 *    métodos vacíos para funciones que no tiene — y un método vacío es una
 *    mentira que el modelo acaba ofreciéndole al cliente. Van en interfaces
 *    aparte (Soporta*) y el negocio declara en capacidades() lo que sabe hacer.
 *
 * LA PRUEBA DE FUEGO: al escribir el adaptador del segundo proyecto no debería
 * hacer falta tocar el paquete. Si hay que tocarlo, este contrato estaba mal.
 */

namespace ElkinLinan\WhatsappAiEngine\Ports;

interface DomainAdapter
{
    /**
     * Quién es quien escribe, con lo que el negocio sepa de él: nombre,
     * historial, saldo, lo que sea. Va al prompt, así que devuelve poco y útil.
     */
    public function contextoCliente(array $conversacion): array;

    /* ── Catálogo ─────────────────────────────────────────────────────── */

    /**
     * Lo que el negocio vende, filtrado.
     *
     * $filtros es LIBRE a propósito: el bar filtra por categoría, la tienda de
     * tecnología por marca, capacidad o color. Cada adaptador entiende los
     * suyos e ignora el resto; el motor solo pasa lo que el modelo pidió.
     *
     * Cada item: ['id','nombre','descripcion','precio','disponible',...].
     */
    public function buscarItems(?string $busqueda = null, array $filtros = [], int $limite = 60): array;

    /** Un item con todo su detalle, o null si no existe. */
    public function detalleItem(string $id): ?array;

    /**
     * Unidades vendibles AHORA, o null si el negocio no controla existencias.
     * null y 0 son cosas distintas: null es «no lo controlo», 0 es «se agotó».
     */
    public function disponibilidad(string $id): ?int;

    /* ── La transacción ───────────────────────────────────────────────── */

    /**
     * Crea la transacción y RESERVA lo que haga falta (descontar stock, marcar
     * un IMEI). Debe ser atómica: dos conversaciones a la vez no pueden vender
     * la misma última unidad.
     *
     * @return array{id:string,total:float}
     * @throws \InvalidArgumentException si algo no está disponible — el mensaje
     *         se le enseña al cliente, así que escríbelo para él.
     */
    public function crearTransaccion(array $conversacion, array $items, array $datos = []): array;

    /**
     * Cotiza un carrito SIN crear nada. El precio lo pone el servidor: lo que
     * diga el modelo no se usa jamás para cobrar. $extra es lo que se suma por
     * fuera de las líneas (el envío, un recargo); el negocio que no cobre nada
     * de eso lo ignora.
     *
     * @return array{lineas:array,subtotal:float,total:float}
     * @throws \InvalidArgumentException si algún item no existe — el mensaje se
     *         le enseña al cliente, así que escríbelo para él.
     */
    public function calcularTotal(array $items, float $extra = 0.0): array;

    /** Estado legible para contarle al cliente por dónde va lo suyo. */
    public function estadoTransaccion(string $id): array;

    /**
     * Las transacciones abiertas de UNA conversación.
     *
     * El motor la usa para responder «¿cómo va lo mío?» sin obligar al cliente
     * a dar un número, y para contarle al prompt cuántas cosas tiene en curso.
     */
    public function transaccionesDe(int $conversacionId): array;

    /** Cancela y DEVUELVE lo reservado. */
    public function cancelarTransaccion(string $id): array;

    /**
     * El punto exacto en que el negocio empieza a trabajar: la comanda entra a
     * cocina, el equipo pasa a despacho. El motor solo llama aquí cuando el
     * pago está verificado (o el negocio cobra contra entrega).
     */
    public function confirmarTransaccion(string $id): bool;

    /**
     * Qué sabe hacer este negocio, de esta lista:
     *   'entrega' · 'promociones' · 'menu_del_dia' · 'garantias'
     *   'servicio_tecnico' · 'credito'
     *
     * El motor SOLO le enseña al modelo las herramientas declaradas aquí. No es
     * cosmética: cada herramienta que sobra son tokens en todos los mensajes y
     * una puerta que el modelo puede ofrecerle a un cliente para nada.
     */
    public function capacidades(): array;
}

