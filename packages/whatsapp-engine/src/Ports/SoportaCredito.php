<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/** Fiado / crédito del cliente. */
interface SoportaCredito
{
    public function saldoCliente(int $clienteId): array;
}
