<?php

namespace ElkinLinan\WhatsappAiEngine\Defecto;
use ElkinLinan\WhatsappAiEngine\Ports\FeaturePort;

/** Un proyecto sin planes de suscripción: todo está incluido. */
final class TodoPermitido implements FeaturePort
{
    public function tiene(string $funcion): bool { return true; }

    /** Sin planes no hay techos: null = ilimitado. */
    public function limite(string $clave): ?int { return null; }
}
