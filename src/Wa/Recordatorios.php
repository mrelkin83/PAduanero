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
        // La ventana se calcula en PHP y NO con NOW(): la sesión de MySQL va
        // en UTC (BD.php) y `wa_citas.inicio` se guarda en hora de Bogotá —
        // con NOW() el recordatorio saldría cinco horas antes de la cita.
        $desde = date('Y-m-d H:i:s');
        $hasta = date('Y-m-d H:i:s', time() + self::VENTANA_MIN * 60);

        $citas = $this->db->fetchAll(
            "SELECT * FROM wa_citas
              WHERE estado = 'confirmada' AND slot_activo = 1
                AND recordatorio_enviado_at IS NULL
                AND inicio > ? AND inicio <= ?",
            [$desde, $hasta],
        );
        if ($citas === []) {
            return 0;
        }

        $cfg = WaConfig::cargar($this->db);
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
                    $this->canal->enviarTexto((string) $cita['telefono'], $paraCliente);
                } catch (\Throwable) {
                    // El recordatorio no puede tumbar la pasada entera.
                }

                if ($guardia !== '') {
                    $paraAbogado = '⏰ *Recordatorio de cita* — hoy a las ' . $hora . "\n\n"
                        . '👤 ' . ((string) ($cita['nombre'] ?? '') ?: 'Sin nombre') . "\n"
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
                    'Le recordamos su asesoría con Pedro, abogado aduanero y tributario: '
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
