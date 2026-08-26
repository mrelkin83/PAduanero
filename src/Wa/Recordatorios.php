<?php

declare(strict_types=1);

namespace App\Wa;

use App\Soporte\Fechas;
use App\Soporte\Smtp;
use ElkinLinan\WhatsappAiEngine\Channel\ChannelInterface;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use ElkinLinan\WhatsappAiEngine\Ports\DbPort;

/**
 * Recordatorio de citas próximas (decisión del PO, 2026-08-22).
 *
 * Lo dispara bin/wa-recordatorios.php desde el cron cada 5 minutos: toda
 * cita confirmada a la que le falten 30 minutos o menos se recuerda UNA vez
 * — al cliente por WhatsApp (y por correo si hay SMTP), y al abogado por
 * WhatsApp al número de guardia. Con el cron cada 5 minutos, el aviso llega
 * en la práctica entre 25 y 30 minutos antes, que es la ventana pedida.
 *
 * La marca `recordatorio_enviado_at` se pone ANTES de enviar: perder un
 * recordatorio por una caída a mitad de camino es mejor que repetírselo al
 * cliente en cada pasada. El UPDATE condicionado además deja fuera a un
 * segundo proceso que pise la misma cita.
 */
final class Recordatorios
{
    /** Ventana por defecto; la manda `wa_config.recordatorio_minutos` (panel). */
    public const VENTANA_MIN = 30;

    public function __construct(
        private readonly DbPort $db,
        private readonly ?ChannelInterface $canal,
        private readonly ?Smtp $correo = null,
    ) {
    }

    /** @return int citas recordadas en esta pasada */
    public function enviar(): int
    {
        $cfg = WaConfig::cargar($this->db);
        $ventana = (int) ($cfg['recordatorio_minutos'] ?? self::VENTANA_MIN);
        if ($ventana <= 0) {
            return 0;   // recordatorio apagado desde el panel
        }

        // La ventana se calcula en PHP y NO con NOW(): la sesión de MySQL va
        // en UTC (BD.php) y `wa_citas.inicio` se guarda en hora de Bogotá —
        // con NOW() el recordatorio saldría cinco horas antes de la cita.
        $desde = date('Y-m-d H:i:s');
        $hasta = date('Y-m-d H:i:s', time() + $ventana * 60);

        // El JID del chat viene de la conversación: `wa_citas.telefono` es el
        // número de CONTACTO que dictó el cliente (puede ser otro, o el chat
        // ser un @lid), y el recordatorio de WhatsApp va al chat.
        $citas = $this->db->fetchAll(
            "SELECT c.*, v.telefono AS chat_telefono
               FROM wa_citas c
               JOIN wa_conversaciones v ON v.id = c.conversacion_id
              WHERE c.estado = 'confirmada' AND c.slot_activo = 1
                AND c.recordatorio_enviado_at IS NULL
                AND c.inicio > ? AND c.inicio <= ?",
            [$desde, $hasta],
        );
        if ($citas === []) {
            return 0;
        }

        $guardia = trim((string) ($cfg['handoff_numero'] ?? ''));
        $enviadas = 0;

        foreach ($citas as $cita) {
            $tomada = $this->db->query(
                'UPDATE wa_citas SET recordatorio_enviado_at = NOW()
                  WHERE id = ? AND recordatorio_enviado_at IS NULL',
                [(int) $cita['id']],
            );
            if ($tomada === 0) {
                continue;
            }

            $hora = Fechas::horaNatural(substr((string) $cita['inicio'], 11, 5));
            $meet = trim((string) ($cita['gcal_meet_url'] ?? ''));

            if ($this->canal !== null) {
                $paraCliente = '⏰ Le recordamos su asesoría con Pedro, abogado aduanero: hoy a las ' . $hora . '.'
                    . ($meet !== '' ? "\n\nEnlace de la videollamada: " . $meet : '')
                    . "\n\nLo esperamos.";
                try {
                    $this->canal->enviarTexto(
                        (string) ($cita['chat_telefono'] ?? '') ?: (string) $cita['telefono'],
                        $paraCliente,
                    );
                } catch (\Throwable) {
                    // El recordatorio no puede tumbar la pasada entera.
                }

                if ($guardia !== '') {
                    $contacto = (string) ($cita['telefono'] ?? '');
                    $paraAbogado = '⏰ *Recordatorio de cita* — hoy a las ' . $hora . "\n\n"
                        . '👤 ' . ((string) ($cita['nombre'] ?? '') ?: 'Sin nombre') . "\n"
                        . '📱 ' . (str_contains($contacto, '@lid')
                            ? 'oculto por WhatsApp — el chat vive en el panel'
                            : ($contacto ?: 'Sin teléfono')) . "\n"
                        . '📝 ' . ((string) ($cita['motivo'] ?? '') ?: 'Sin motivo registrado')
                        . ($meet !== '' ? "\n🔗 " . $meet : '');
                    try {
                        $this->canal->enviarTexto($guardia, $paraAbogado);
                    } catch (\Throwable) {
                    }
                }
            }

            $correoCliente = trim((string) ($cita['correo'] ?? ''));
            if ($this->correo !== null && $correoCliente !== '') {
                $this->correo->enviar(
                    $correoCliente,
                    'Recordatorio: su asesoría es hoy a las ' . $hora,
                    'Le recordamos su asesoría con Pedro, abogado aduanero: '
                    . 'hoy a las ' . $hora . '.'
                    . ($meet !== '' ? "\n\nEnlace de la videollamada: " . $meet : '')
                    . "\n\nEste es un mensaje automático; si necesita reprogramar, responda por WhatsApp.",
                );
            }

            $enviadas++;
        }

        return $enviadas;
    }
}
