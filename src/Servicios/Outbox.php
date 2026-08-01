<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\EventoOutbox;
use App\Motor\MotivoEscalamiento;

/**
 * La cola de efectos externos (ADR-004).
 *
 * `encolar()` es una escritura y nada más: se puede llamar **dentro** de una
 * transacción sin miedo. Todo lo que sale a la red lo hace el worker, fuera.
 *
 * REGLA 14 Y POR QUÉ HAY UN MÉTODO ESPECÍFICO PARA EL ESCALAMIENTO
 *
 * La regla 14 dice «cero texto del mensaje: ni descripción, ni extracto, ni
 * resumen, **en ninguna tabla, cola o notificación**». El outbox es
 * literalmente esa cola.
 *
 * Un `encolar('alerta.escalamiento', $payload)` genérico deja la puerta
 * abierta a que alguien meta el texto del mensaje en el payload con toda la
 * buena intención del mundo — para que Pedro no tenga que abrir Chatwoot, por
 * ejemplo. Por eso existe `encolarAlertaEscalamiento()`, que construye el
 * payload él mismo a partir de argumentos tipados: teléfono, motivo, momento y
 * `chatwoot_conv_id`. No hay ningún parámetro por el que quepa un texto.
 *
 * Es el mismo recurso que `GateDorado::registrarCorrida()`, que no recibe el
 * prompt sino que lo mira: cuando una regla no debe poder saltarse, la firma
 * del método es mejor sitio que un comentario.
 */
interface Outbox
{
    /**
     * Encola un efecto. Escritura pura: seguro dentro de una transacción.
     *
     * @param  array<string,mixed> $payload
     * @param  int                 $retrasoSegundos para programarlo a futuro
     * @return int                 id del evento
     */
    public function encolar(string $tipo, array $payload, int $retrasoSegundos = 0): int;

    /**
     * Alerta de escalamiento **sin carga útil** (regla 14).
     *
     * Deliberadamente no acepta texto. La alerta dice «escalamiento urgente,
     * revise la conversación #123»; el contenido lo lee Pedro en Chatwoot,
     * que es donde el contacto ya lo escribió por voluntad propia.
     */
    public function encolarAlertaEscalamiento(
        string $telefonoContacto,
        MotivoEscalamiento $motivo,
        int $chatwootConvId,
    ): int;

    /**
     * Reclama hasta `$limite` eventos listos para despachar.
     *
     * Los marca `procesando` en la misma transacción en que los selecciona,
     * de modo que dos workers en paralelo nunca tomen el mismo.
     *
     * @return list<EventoOutbox>
     */
    public function tomar(int $limite = 20): array;

    public function marcarEnviado(int $id): void;

    /** Vuelve a `pendiente` con la siguiente espera del backoff. */
    public function reprogramar(int $id, string $error): void;

    /** Definitivo: no se vuelve a intentar. */
    public function marcarFallido(int $id, string $error): void;

    /**
     * Devuelve a la cola lo que quedó `procesando` de un worker muerto.
     *
     * @return int cuántos se recuperaron
     */
    public function recuperarAtascados(int $minutos = 15): int;
}
