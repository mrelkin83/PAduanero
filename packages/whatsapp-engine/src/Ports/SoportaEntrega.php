<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/** Domicilio o recoger: cambia qué datos hay que pedirle al cliente. */
interface SoportaEntrega
{
    /** ['domicilio','recoger'] — los modos que este negocio acepta. */
    public function modosEntrega(): array;

    /** Costo del envío a esa dirección (0 = gratis). */
    public function costoEntrega(array $datos): float;
}
