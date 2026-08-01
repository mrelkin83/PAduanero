<?php

declare(strict_types=1);

namespace App\Servicios\ClientesLlm;

use App\Servicios\RespuestaLlm;

/**
 * Habla con un proveedor concreto. Uno por `proveedores_ia.formato_api`.
 *
 * Interfaz y no `switch`, por lo mismo que `Probador` y `Descubridor`: cada
 * proveedor tiene su forma de petición y de respuesta, y añadir uno no debe
 * obligar a tocar el servicio `Llm`.
 *
 * Contrato de fallo: **excepción**. Una respuesta vacía no es una respuesta;
 * devolverla haría que la cascada de fallback diera por bueno un turno en el
 * que el bot no dijo nada.
 */
interface ClienteLlm
{
    /** Valor de `proveedores_ia.formato_api` que atiende. */
    public function formato(): string;

    /**
     * @param  array<int,array{role:string,content:string}> $mensajes
     * @param  array<string,mixed>                          $modelo fila de `modelos_ia`
     * @throws FalloProveedor
     */
    public function chat(
        string $baseUrl,
        ?string $secreto,
        array $modelo,
        string $systemPrompt,
        array $mensajes,
        int $maxTokens,
    ): RespuestaLlm;
}
