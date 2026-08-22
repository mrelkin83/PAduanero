<?php

namespace ElkinLinan\WhatsappAiEngine\Defecto;
use ElkinLinan\WhatsappAiEngine\Ports\ConfigPort;

/**
 * Sin URL base: los enlaces salen relativos y el canal se configura por negocio.
 * Pásalo explícito para ANULAR los defaults de plataforma que ConfigDeEntorno
 * leería del entorno (útil en pruebas o en un hospedador que no los quiere).
 */
final class SinUrl implements ConfigPort
{
    public function urlBase(): string { return ''; }
    public function canalUrlPorDefecto(): string { return ''; }
    public function canalApikeyPorDefecto(): string { return ''; }
    public function ttsUrlPorDefecto(): string { return ''; }
    public function ttsModeloPorDefecto(): string { return ''; }
    public function visionUrlPorDefecto(): string { return ''; }
    public function visionModeloPorDefecto(): string { return ''; }
    public function sttUrlPorDefecto(): string { return ''; }
    public function sttModeloPorDefecto(): string { return ''; }
}
