<?php
/**
 * ============================================================================
 * HumanHandoff — transferencia a una persona (§36)
 * ============================================================================
 *   IA_ACTIVA ──transferir──► HUMANO_ATENDIENDO ──liberar──► IA_ACTIVA
 *        └──────────── IA_PAUSADA (apagada a mano) ─────────────┘
 *
 * Mientras hay un humano atendiendo, la IA NO escribe ni una palabra. Sigue
 * guardando lo que llega para que el hilo quede completo, pero no responde:
 * dos voces contestando al mismo cliente a la vez es peor que ninguna.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

class HumanHandoff
{
    private $db;
    private $log;

    public function __construct($db, $log = null)
    {
        $this->db  = $db;
        $this->log = $log;
    }

    /** ¿Puede responder la IA en esta conversación? */
    public static function iaPuedeResponder(array $conv): bool
    {
        return ($conv['estado'] ?? 'IA_ACTIVA') === 'IA_ACTIVA';
    }

    /** Pasa la conversación a una persona y avisa al número de guardia. */
    public function transferir(int $convId, string $motivo, $canal = null, ?array $cfg = null): void
    {
        $this->db->query(
            "UPDATE wa_conversaciones SET estado = 'HUMANO_ATENDIENDO' WHERE id = ? AND estado != 'CERRADA'", [$convId]);

        $conv = $this->db->fetch("SELECT * FROM wa_conversaciones WHERE id = ?", [$convId]);
        if ($this->log) {
            $this->log->log('handoff', 'Transferida a una persona: ' . $motivo,
                ['telefono' => $conv['telefono'] ?? '', 'motivo' => $motivo], $convId);
        }

        // Aviso dentro del sistema: aunque nadie mire WhatsApp, el panel lo ve.
        //
        // Lo escribe EL NEGOCIO, no el motor. Aquí había un `INSERT INTO alertas
        // (mesa_id, …)` —la tabla de un bar— envuelto en un try/catch: en
        // cualquier otro proyecto fallaba en silencio y el resultado era que al
        // cliente se le decía «le paso tu caso a una persona» y nadie se
        // enteraba. Es exactamente el fallo que no puede repetirse.
        $dominio = \ElkinLinan\WhatsappAiEngine\Engine::tiene('dominio')
            ? \ElkinLinan\WhatsappAiEngine\Engine::dominio() : null;
        if ($dominio instanceof \ElkinLinan\WhatsappAiEngine\Ports\SoportaAvisoInterno) {
            try {
                $dominio->avisarEquipo($conv ?: [], $motivo);
            } catch (\Throwable $e) {
                // El aviso interno no puede tumbar la transferencia, pero SÍ
                // tiene que dejar rastro: en silencio volvía a no avisar a nadie.
                if ($this->log) {
                    $this->log->error('No se pudo avisar al equipo de la transferencia: ' . $e->getMessage(),
                        ['motivo' => $motivo], $convId);
                }
            }
        }

        // Aviso al número de guardia, si el negocio configuró uno.
        $numero = $cfg['handoff_numero'] ?? null;
        if ($numero && $canal) {
            $texto = "🔔 *Un cliente necesita atención*\n\n"
                   . '👤 ' . ($conv['nombre_contacto'] ?: 'Sin nombre') . "\n"
                   . '📱 ' . ($conv['telefono'] ?? '') . "\n"
                   . '📝 ' . $motivo . "\n\n"
                   . 'Responde desde el panel: WhatsApp IA → Conversaciones.';
            try { $canal->enviarTexto($numero, $texto); } catch (\Throwable $e) { /* sin ruido */ }
        }
    }

    /** Una persona toma la conversación desde el panel. */
    public function tomar(int $convId, int $usuarioId): void
    {
        $this->db->query(
            "UPDATE wa_conversaciones SET estado = 'HUMANO_ATENDIENDO', atendida_por = ? WHERE id = ?",
            [$usuarioId, $convId]);
        if ($this->log) $this->log->log('handoff', 'Conversación tomada por un usuario', ['usuario_id' => $usuarioId], $convId);
    }

    /** Se devuelve el control a la IA. */
    public function liberar(int $convId): void
    {
        $this->db->query(
            "UPDATE wa_conversaciones SET estado = 'IA_ACTIVA', atendida_por = NULL WHERE id = ?", [$convId]);
        if ($this->log) $this->log->log('handoff', 'Conversación devuelta a la IA', null, $convId);
    }

    /** El negocio apaga la IA para este cliente sin que nadie la atienda. */
    public function pausar(int $convId): void
    {
        $this->db->query("UPDATE wa_conversaciones SET estado = 'IA_PAUSADA' WHERE id = ?", [$convId]);
        if ($this->log) $this->log->log('handoff', 'IA pausada en la conversación', null, $convId);
    }
}
