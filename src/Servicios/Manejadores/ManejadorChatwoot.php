<?php

declare(strict_types=1);

namespace App\Servicios\Manejadores;

use App\Excepciones\ChatwootNoDisponible;
use App\Modelos\EventoOutbox;
use App\Servicios\Chatwoot;

/**
 * Escrituras en la bandeja que no pudieron hacerse en caliente.
 *
 * Llegan aquí cuando `ChatwootApi` agotó sus tres reintentos rápidos. La
 * división de trabajo entre los dos: el servicio insiste durante un segundo
 * por si es un hipo de red; el outbox insiste durante horas por si Chatwoot
 * está caído de verdad.
 *
 * **Se despacha por `entregar()`**, no por `responder()`. Un evento encolado
 * ayer se entrega con el modo sombra de HOY, no con el que había cuando se
 * encoló. Es lo correcto: si Pedro apagó el modo sombra esta mañana, un
 * borrador de anoche que salga ahora debe salir como mensaje; y si lo volvió a
 * encender, no debe salir. Guardar la decisión en el payload congelaría un
 * criterio caducado.
 */
final class ManejadorChatwoot implements ManejadorEvento
{
    public function __construct(private readonly Chatwoot $chatwoot)
    {
    }

    public function tipos(): array
    {
        return ['chatwoot.entregar', 'chatwoot.etiquetar', 'chatwoot.escalar'];
    }

    public function manejar(EventoOutbox $evento): void
    {
        $conv = (int) $evento->dato('chatwoot_conv_id', 0);

        if ($conv <= 0) {
            throw new EventoDescartado('Evento de Chatwoot sin chatwoot_conv_id.');
        }

        try {
            match ($evento->tipo) {
                'chatwoot.entregar' => $this->entregar($evento, $conv),
                'chatwoot.etiquetar' => $this->etiquetar($evento, $conv),
                'chatwoot.escalar' => $this->escalar($evento, $conv),
                default => throw new EventoDescartado("Tipo no soportado: {$evento->tipo}"),
            };
        } catch (ChatwootNoDisponible $e) {
            // Un rechazo definitivo —401, 404, conversación borrada— va a
            // fallar igual dentro de seis horas. Reintentarlo solo retrasa a
            // los eventos que sí podían salir.
            if (!$e->agotoReintentos) {
                throw new EventoDescartado($e->getMessage(), 0, $e);
            }

            throw $e;
        }
    }

    private function entregar(EventoOutbox $evento, int $conv): void
    {
        $texto = $evento->dato('texto');

        if (!is_string($texto) || trim($texto) === '') {
            throw new EventoDescartado('Evento de entrega sin texto.');
        }

        $this->chatwoot->entregar($conv, $texto);
    }

    private function etiquetar(EventoOutbox $evento, int $conv): void
    {
        $etiquetas = $evento->dato('etiquetas');

        if (!is_array($etiquetas) || $etiquetas === []) {
            throw new EventoDescartado('Evento de etiquetado sin etiquetas.');
        }

        $this->chatwoot->etiquetar($conv, array_values(array_filter($etiquetas, is_string(...))));
    }

    /**
     * El escalamiento visible en la bandeja: prioridad, asignación y estado.
     *
     * Los tres van juntos en un evento y no en tres, para que no puedan
     * quedarse a medias: una conversación marcada urgente pero sin asignar es
     * peor que una sin marcar, porque parece atendida.
     *
     * Aquí NO va texto del contacto. Lo que se escribe en la bandeja es
     * metadato (regla 14).
     */
    private function escalar(EventoOutbox $evento, int $conv): void
    {
        $prioridad = $evento->dato('prioridad');

        $this->chatwoot->cambiarPrioridad(
            $conv,
            in_array($prioridad, ['urgent', 'high', 'medium', 'low'], true) ? $prioridad : 'high',
        );

        $this->chatwoot->asignarAlAbogado($conv);
        $this->chatwoot->cambiarEstado($conv, 'open');
    }
}
