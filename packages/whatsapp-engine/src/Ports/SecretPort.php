<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Cifrado de los secretos que el negocio guarda (claves de IA, de la pasarela,
 * del canal). Nunca se guardan en claro, y esa decisión no es del motor: la
 * clave de cifrado la administra el proyecto.
 */
interface SecretPort
{
    public function cifrar(string $claro): string;
    public function descifrar(string $cifrado): string;
}
