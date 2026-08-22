<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/** Cómo se escribe el dinero en el país del proyecto. */
interface FormatPort
{
    public function dinero(float $monto): string;
}
