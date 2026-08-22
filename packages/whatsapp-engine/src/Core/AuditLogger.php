<?php
/**
 * ============================================================================
 * AuditLogger — bitácora del motor (wa_eventos), calcada de fe_eventos
 * ============================================================================
 * §40 exige registrar mensaje, agente, proveedor, modelo, herramienta,
 * resultado, pedido, pago, transferencia y error. Y prohíbe guardar secretos.
 *
 * Por eso TODO payload pasa por sanitizar() antes de tocar la BD. No es una
 * cortesía: una API Key en una tabla que se vuelca en cada backup, se exporta
 * y se lee desde el panel es una fuga permanente.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

class AuditLogger
{
    /** Nombres de clave que nunca se escriben, se casen como se casen. */
    const PATRONES_SECRETOS = [
        'key', 'apikey', 'api_key', 'token', 'secret', 'password', 'passwd',
        'clave', 'authorization', 'auth', 'signature', 'firma', 'p12', 'pass',
    ];

    private $db;

    public function __construct($db) { $this->db = $db; }

    /**
     * Elimina de forma recursiva cualquier valor cuya CLAVE parezca un secreto.
     * Se sustituye por un marcador en vez de borrarse para que en la bitácora
     * siga viéndose que ese campo iba en la petición.
     */
    public static function sanitizar($valor, int $prof = 0)
    {
        if ($prof > 8) return '[…]';
        if (is_array($valor)) {
            $out = [];
            foreach ($valor as $k => $v) {
                $clave = strtolower((string)$k);
                $esSecreto = false;
                foreach (self::PATRONES_SECRETOS as $p) {
                    if (strpos($clave, $p) !== false) { $esSecreto = true; break; }
                }
                $out[$k] = $esSecreto ? '[oculto]' : self::sanitizar($v, $prof + 1);
            }
            return $out;
        }
        if (is_string($valor)) {
            // Red de seguridad por si un secreto viaja en un valor y no en una
            // clave (una cabecera serializada, una URL firmada).
            $valor = preg_replace('/\b(sk-|pk_|prv_|pub_|Bearer\s+)[A-Za-z0-9_\-]{8,}/i', '$1[oculto]', $valor);
            return mb_substr($valor, 0, 4000);
        }
        return $valor;
    }

    /**
     * Registra un evento. Nunca lanza: una bitácora rota no puede tumbar una
     * conversación con un cliente. Si falla, queda en el log de errores.
     */
    public function log(string $tipo, string $descripcion, $payload = null, ?int $conversacionId = null, ?int $usuarioId = null): void
    {
        try {
            $json = null;
            if ($payload !== null) {
                $json = json_encode(self::sanitizar($payload), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            $s = Scope::paraInsert();
            $this->db->insert(
                "INSERT INTO wa_eventos ({$s['columnas']}conversacion_id, tipo, descripcion, payload, usuario_id)
                 VALUES ({$s['marcadores']}?,?,?,?,?)",
                array_merge($s['valores'],
                    [$conversacionId, $tipo, mb_substr($descripcion, 0, 255), $json,
                     $usuarioId ?: ($_SESSION['admin_id'] ?? null)])
            );
        } catch (\Throwable $e) {
            error_log('[WhatsApp][AuditLogger] ' . $e->getMessage() . ' — evento perdido: ' . $tipo . ' ' . $descripcion);
        }
    }

    /** Atajo para errores: además deja rastro en el log de la aplicación. */
    public function error(string $descripcion, $payload = null, ?int $conversacionId = null): void
    {
        error_log('[WhatsApp] ' . $descripcion);
        $this->log('error', $descripcion, $payload, $conversacionId);
    }
}
