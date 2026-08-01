<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\Usuario;

/**
 * La bandeja. Todo lo que el motor dice sale por aquí (ADR-001).
 *
 * Nunca por Evolution: así queda en el hilo, Pedro ve exactamente lo que el
 * bot dijo, y el traspaso a humano es instantáneo. La única excepción son las
 * alertas internas a Pedro, que salen directas a su número para no contaminar
 * la bandeja de clientes — y esas no pasan por esta interfaz.
 *
 * **`entregar()` es el método que debe usar el motor**, no `responder()`. La
 * diferencia es la regla de despliegue del `CLAUDE.md` §8: la IA arranca en
 * modo sombra y deja nota privada en vez de enviar. Si esa decisión estuviera
 * en cada sitio que quiere hablar, bastaría un olvido para que un borrador sin
 * revisar saliera hacia un cliente.
 */
interface Chatwoot
{
    /**
     * Entrega una respuesta del motor por el camino que toque.
     *
     * Consulta `motor_modo_sombra` y decide: nota privada (borrador para
     * Pedro) o mensaje al contacto. **Es el único método que el motor debe
     * llamar para hablar.**
     *
     * @return array{id:int,sombra:bool}
     *
     * @throws \App\Excepciones\ChatwootNoDisponible
     */
    public function entregar(int $conversacionId, string $texto): array;

    /**
     * Mensaje visible para el contacto.
     *
     * Público porque el panel lo necesita para el envío manual de un
     * borrador. El motor NO lo llama: llama a `entregar()`.
     *
     * @throws \App\Excepciones\ChatwootNoDisponible
     */
    public function responder(int $conversacionId, string $texto): int;

    /**
     * Nota interna. Solo la ven los agentes.
     *
     * Es el camino principal de la Etapa 4 y el que sostiene el modo sombra.
     *
     * @throws \App\Excepciones\ChatwootNoDisponible
     */
    public function notaPrivada(int $conversacionId, string $texto): int;

    /** @param list<string> $etiquetas */
    public function etiquetar(int $conversacionId, array $etiquetas): void;

    /** urgent | high | medium | low */
    public function cambiarPrioridad(int $conversacionId, string $prioridad): void;

    public function asignarAlAbogado(int $conversacionId): void;

    /** open | pending | resolved */
    public function cambiarEstado(int $conversacionId, string $estado): void;

    /** @param array<string,mixed> $atributos */
    public function setAtributos(int $contactoId, array $atributos): void;

    /** @return int chatwoot_agent_id */
    public function sincronizarAgente(Usuario $usuario): int;
}
