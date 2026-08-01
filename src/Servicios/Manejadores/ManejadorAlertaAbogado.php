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
        return [
            'alerta.escalamiento',
            'alerta.modelo_retirado',
            'alerta.pago_confirmado',
            'alerta.pago_huerfano',
        ];
    }

    public function manejar(EventoOutbox $evento): void
    {
        $texto = match ($evento->tipo) {
            'alerta.escalamiento' => $this->textoEscalamiento($evento),
            'alerta.modelo_retirado' => $this->textoModeloRetirado($evento),
            'alerta.pago_confirmado' => $this->textoPagoConfirmado($evento),
            'alerta.pago_huerfano' => $this->textoPagoHuerfano($evento),
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

    private function textoPagoHuerfano(EventoOutbox $evento): string
    {
        // Dinero real sobre un cupo que ya no existe. Es de las pocas
        // alertas que piden acción el mismo día: hay un cliente que pagó y
        // no tiene cita.
        $conv = (int) $evento->dato('chatwoot_conv_id', 0);

        return implode("\n", array_filter([
            '🔴 PAGO SIN CUPO — conciliar hoy',
            'Llegó un pago aprobado sobre una reserva «' . $evento->dato('estado_consulta', '¿?') . '»'
                . ' (era para el ' . $evento->dato('fecha', '¿?') . ' a las ' . $evento->dato('hora', '¿?') . ').',
            'La consulta NO se confirmó sola: hay que reagendar o devolver, a mano.',
            $conv > 0 ? 'Conversación #' . $conv : null,
            $conv > 0 ? $this->enlace($conv) : null,
        ]));
    }

    private function textoPagoConfirmado(EventoOutbox $evento): string
    {
        // Misma disciplina que la regla 14: al teléfono personal de Pedro va
        // la agenda, nunca el contenido del caso. Fecha, hora y dónde mirar.
        $conv = (int) $evento->dato('chatwoot_conv_id', 0);

        return implode("\n", array_filter([
            '💰 Pago confirmado',
            'Asesoría agendada: ' . $evento->dato('fecha', '¿?') . ' a las ' . $evento->dato('hora', '¿?'),
            $conv > 0 ? 'Conversación #' . $conv : null,
            $conv > 0 ? $this->enlace($conv) : null,
        ]));
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
