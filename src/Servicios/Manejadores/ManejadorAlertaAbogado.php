<?php

declare(strict_types=1);

namespace App\Servicios\Manejadores;

use App\Modelos\EventoOutbox;
use App\Motor\MotivoEscalamiento;
use App\Servicios\EvolucionAlertas;

/**
 * Avisos a Pedro por WhatsApp. Atiende dos tipos de evento.
 *
 * REGLA 14: EL MENSAJE SE COMPONE AQUÍ, NO SE RECIBE
 *
 * El texto que sale hacia el teléfono personal de Pedro se construye en este
 * archivo a partir del motivo y el número de conversación. **No se toma
 * ningún texto del payload**, y no es una precaución teórica: el teléfono de
 * Pedro es un dispositivo que este sistema no controla, no puede purgar y no
 * aparece en ninguna política de retención. Un extracto del caso que llegue
 * ahí se queda ahí para siempre.
 *
 * La alerta dice qué pasó y dónde mirarlo. El contenido lo lee en Chatwoot,
 * que es donde el contacto ya lo escribió por voluntad propia.
 */
final class ManejadorAlertaAbogado implements ManejadorEvento
{
    public function __construct(
        private readonly EvolucionAlertas $evolucion,
        /** Base del panel/bandeja, para poder enlazar el hilo. */
        private readonly string $urlChatwoot = '',
        private readonly string $cuentaChatwoot = '1',
    ) {
    }

    public function tipos(): array
    {
        return ['alerta.escalamiento', 'alerta.modelo_retirado'];
    }

    public function manejar(EventoOutbox $evento): void
    {
        $texto = match ($evento->tipo) {
            'alerta.escalamiento' => $this->textoEscalamiento($evento),
            'alerta.modelo_retirado' => $this->textoModeloRetirado($evento),
            default => throw new EventoDescartado("Tipo no soportado: {$evento->tipo}"),
        };

        if (!$this->evolucion->avisar($texto)) {
            // Excepción, no descarte: WhatsApp caído es transitorio y el
            // worker debe volver. Un escalamiento urgente que se pierde
            // porque Evolution estaba reiniciándose es justo lo que el outbox
            // existe para impedir.
            throw new \RuntimeException('Evolution no aceptó la alerta.');
        }
    }

    private function textoEscalamiento(EventoOutbox $evento): string
    {
        $conv = (int) $evento->dato('chatwoot_conv_id', 0);

        if ($conv <= 0) {
            throw new EventoDescartado('Alerta de escalamiento sin chatwoot_conv_id.');
        }

        $motivo = MotivoEscalamiento::desde(
            is_string($evento->dato('motivo')) ? (string) $evento->dato('motivo') : null,
        );

        $encabezado = $motivo->esUrgente()
            ? '🔴 ESCALAMIENTO URGENTE'
            : '🟠 Escalamiento';

        // Sin texto del contacto. Solo el motivo del catálogo, que es una
        // constante nuestra, y dónde mirar.
        return implode("\n", array_filter([
            $encabezado,
            'Motivo: ' . str_replace('_', ' ', $motivo->value),
            'Conversación #' . $conv,
            $this->enlace($conv),
            '',
            'El detalle está en el hilo. La IA quedó apagada en esa conversación.',
        ]));
    }

    private function textoModeloRetirado(EventoOutbox $evento): string
    {
        $modelo = is_string($evento->dato('modelo')) ? (string) $evento->dato('modelo') : '(desconocido)';

        return implode("\n", [
            '⚠️ Modelo primario retirado',
            $modelo . ' ya no aparece en el catálogo de su proveedor.',
            '',
            'El bot sigue respondiendo desde el suplente de la cascada, así que '
            . 'no hay caída visible — por eso conviene mirarlo hoy.',
            'Elija sustituto en /panel/ia.',
        ]);
    }

    private function enlace(int $conversacionId): string
    {
        if ($this->urlChatwoot === '') {
            return '';
        }

        return rtrim($this->urlChatwoot, '/')
            . "/app/accounts/{$this->cuentaChatwoot}/conversations/{$conversacionId}";
    }
}
