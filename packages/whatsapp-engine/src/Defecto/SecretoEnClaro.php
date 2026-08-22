<?php

namespace ElkinLinan\WhatsappAiEngine\Defecto;
use ElkinLinan\WhatsappAiEngine\Ports\SecretPort;

/**
 * Sin cifrado: devuelve lo que le dan.
 *
 * Es el defecto porque el motor tiene que arrancar en un proyecto que aún no ha
 * decidido cómo administra sus claves, pero NO es aceptable en producción: en
 * esa columna acaban las API keys del negocio. El proyecto serio conecta su
 * propio SecretPort.
 */
final class SecretoEnClaro implements SecretPort
{
    public function cifrar(string $claro): string { return $claro; }
    public function descifrar(string $cifrado): string { return $cifrado; }
}
