<?php

namespace ElkinLinan\WhatsappAiEngine\Defecto;
use ElkinLinan\WhatsappAiEngine\Ports\TenantPort;

/** Un solo negocio: no hay id que resolver ni base que elegir. */
final class NegocioUnico implements TenantPort
{
    public function id(): ?int { return null; }
    public function nombre(): string { return 'el negocio'; }
    public function baseDatos(): ?string { return null; }
    public function esMultiNegocio(): bool { return false; }
    public function scopeFila(): ?array { return null; }
}
