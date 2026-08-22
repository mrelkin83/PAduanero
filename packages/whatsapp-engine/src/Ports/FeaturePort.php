<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * «¿Este negocio tiene contratada esta función?».
 *
 * En un proyecto con planes de suscripción consulta el plan; en uno sin ellos,
 * `return true;` y listo. El motor lo usa para no ofrecerle al modelo
 * herramientas que el negocio no puede usar — lo que además ahorra tokens en
 * cada mensaje.
 */
interface FeaturePort
{
    public function tiene(string $funcion): bool;

    /**
     * Un límite numérico del plan («cuántas conversaciones al mes»), o null si
     * no aplica: sin planes, sin cupo, o ilimitado.
     *
     * Existe porque el motor traía escrita la consulta
     * `SELECT … FROM planes JOIN tenants …`: el esquema de suscripciones del
     * proyecto donde nació, dentro de un paquete que también tiene que servir a
     * uno que llama a esas tablas de otra forma. Allí no fallaba y aquí tampoco
     * —está en un try/catch—, pero el cupo simplemente no se aplicaba nunca, en
     * silencio, que es la peor forma de no funcionar.
     *
     * FALLA ABIERTO a propósito: ante la duda, null. Un error leyendo el plan no
     * puede dejar sin atender a un negocio que sí paga.
     */
    public function limite(string $clave): ?int;
}
