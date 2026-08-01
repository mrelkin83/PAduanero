<?php

declare(strict_types=1);

namespace App\Servicios\Descubridores;

/**
 * Lista los modelos que un proveedor anuncia hoy.
 *
 * Uno por `proveedores_ia.formato_api`. Es interfaz y no `switch` por la
 * misma razón que `Probador`: cada proveedor pagina y nombra distinto, y
 * añadir uno no debe obligar a tocar el servicio de catálogo.
 *
 * Contrato de fallo: **excepción**, nunca lista vacía. Un proveedor que
 * responde 401 y otro que sinceramente no tiene modelos son situaciones
 * opuestas, y confundirlas haría que una credencial caducada apareciera en
 * el panel como «todos los modelos fueron retirados».
 */
interface Descubridor
{
    /** Valor de `proveedores_ia.formato_api` que atiende. */
    public function formato(): string;

    /**
     * Clave de `credenciales` con la que autenticarse, o `null` si el
     * proveedor no pide nada (Ollama en la propia máquina).
     */
    public function claveCredencial(): ?string;

    /**
     * @param  string      $baseUrl de `proveedores_ia.base_url`
     * @param  string|null $secreto ya descifrado, o null si no aplica
     * @return list<ModeloDescubierto>
     *
     * @throws DescubrimientoFallido
     */
    public function listar(string $baseUrl, ?string $secreto): array;
}
