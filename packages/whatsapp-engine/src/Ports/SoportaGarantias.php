<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Postventa de producto duradero: un bar no tiene esto, una tienda de
 * tecnología lo tiene como pregunta diaria («¿qué garantía trae?»).
 */
interface SoportaGarantias
{
    /** Por IMEI, número de serie o número de garantía. */
    public function consultarGarantia(string $identificador): array;
}
