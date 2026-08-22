<?php

namespace ElkinLinan\WhatsappAiEngine\Defecto;
use ElkinLinan\WhatsappAiEngine\Ports\FormatPort;

/** Pesos colombianos, que es donde vive esto hoy. */
final class PesosColombianos implements FormatPort
{
    public function dinero(float $monto): string
    {
        return '$' . number_format($monto, 0, ',', '.');
    }
}
