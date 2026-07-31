<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * Secretos de terceros (Wompi, LLM, Chatwoot, Evolution, SMTP).
 *
 * La regla que gobierna todo este servicio: `obtener()` no se expone por HTTP
 * jamás. La API del panel devuelve `mascara` y nada más.
 */
interface Credenciales
{
    /**
     * Descifra y devuelve el valor real. Registra la lectura en `auditoria`.
     *
     * @throws CredencialNoEncontradaException
     */
    public function obtener(string $servicio, string $clave, string $entorno = 'produccion'): string;

    /** @return array{mascara:string} lo único que puede viajar al navegador */
    public function guardar(
        string $servicio,
        string $clave,
        string $valor,
        string $entorno,
        string $usuarioId,
    ): array;

    /** @return array{ok:bool,mensaje:string} conectividad real contra el proveedor */
    public function probar(string $servicio, string $entorno): array;

    public function rotarClaveMaestra(string $nuevaClave): void;
}
