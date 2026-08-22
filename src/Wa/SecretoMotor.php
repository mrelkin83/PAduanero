<?php

declare(strict_types=1);

namespace App\Wa;

use App\Soporte\Cifrado;
use ElkinLinan\WhatsappAiEngine\Ports\SecretPort;

/**
 * Los secretos del motor con el cifrado único del sistema (ADR-011).
 *
 * El blob de `Cifrado` es binario y las columnas del motor (wa_config) son
 * TEXT, así que aquí se envuelve en base64. La envoltura es de este puerto,
 * no de Cifrado: el resto del sistema sigue guardando el blob crudo.
 */
final class SecretoMotor implements SecretPort
{
    public function __construct(private readonly Cifrado $cifrado)
    {
    }

    public function cifrar(string $claro): string
    {
        return base64_encode($this->cifrado->cifrar($claro));
    }

    public function descifrar(string $cifrado): string
    {
        $blob = base64_decode($cifrado, true);
        if ($blob === false) {
            return '';
        }

        try {
            return $this->cifrado->descifrar($blob);
        } catch (\Throwable) {
            // Clave equivocada o dato alterado: mejor un secreto vacío —que
            // hace fallar la conexión ruidosamente— que basura silenciosa.
            return '';
        }
    }
}
