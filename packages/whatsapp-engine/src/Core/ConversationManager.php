<?php
/**
 * ============================================================================
 * ConversationManager — conversaciones, mensajes y memoria (§35)
 * ============================================================================
 * Una conversación viva por teléfono. Todo aislado por negocio, porque cada
 * negocio tiene su propia base de datos: no hay forma de que una consulta de
 * aquí alcance a un cliente de otro.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

class ConversationManager
{
    /** Cuántos mensajes de historia se le mandan al modelo. */
    const VENTANA = 20;
    /** Tras este silencio, el siguiente mensaje empieza conversación nueva. */
    const HORAS_INACTIVIDAD = 12;

    private $db;

    public function __construct($db) { $this->db = $db; }

    /**
     * Conversación viva del teléfono, creándola si hace falta.
     *
     * @param string $telefono Identificador de la conversación Y destino de la
     *   respuesta. Ver `normalizar()`.
     */
    public function obtenerOCrear(string $telefono, string $nombreContacto = ''): array
    {
        $tel = self::normalizar($telefono);
        $c   = $this->viva($tel);
        if ($c) {
            if ($nombreContacto !== '' && empty($c['nombre_contacto'])) {
                $this->db->query("UPDATE wa_conversaciones SET nombre_contacto = ? WHERE id = ?", [$nombreContacto, (int)$c['id']]);
                $c['nombre_contacto'] = $nombreContacto;
            }
            return $c;
        }

        $s = Scope::paraInsert();
        $id = $this->db->insert(
            "INSERT INTO wa_conversaciones ({$s['columnas']}telefono, nombre_contacto, estado, ultimo_mensaje_at)
             VALUES ({$s['marcadores']}?, ?, 'IA_ACTIVA', NOW())",
            array_merge($s['valores'], [$tel, $nombreContacto ?: null]));
        return $this->db->fetch("SELECT * FROM wa_conversaciones WHERE id = ?", [(int)$id]);
    }

    /**
     * Los dígitos, o el JID entero cuando WhatsApp solo da un identificador de
     * privacidad (`…@lid`). Quitarle las letras convertía `45939054088265@lid`
     * en un número que no existe y la respuesta se perdía con un 400.
     */
    public static function normalizar(string $telefono): string
    {
        return (strpos($telefono, '@') !== false)
             ? trim($telefono)
             : (string)preg_replace('/\D+/', '', $telefono);
    }

    /**
     * Conversación viva del teléfono, o null. **Cierra la caducada de paso**:
     * un silencio largo cierra la conversación, porque retomar el hilo de
     * anteayer confunde más de lo que ayuda y arrastra un carrito viejo.
     *
     * Vive aquí y no repetida en cada llamador para que `existeViva()` y
     * `obtenerOCrear()` no puedan discrepar. Si discreparan, el cupo del plan
     * daría por «ya existente» una conversación que un instante después se
     * crea nueva — y el cupo dejaría de contar precisamente lo que cuenta.
     */
    private function viva(string $tel): ?array
    {
        // El filtro por negocio va AQUÍ y no en el llamador porque esta es la
        // única puerta a «la conversación viva de este teléfono». Sin él, en un
        // proyecto que guarda a todos los negocios en la misma base, el número
        // que le escribe a dos tiendas distintas caía en la conversación de la
        // primera — con su carrito y su historial.
        $c = $this->db->fetch(
            "SELECT * FROM wa_conversaciones
             WHERE telefono = ? AND estado != 'CERRADA'" . Scope::y() . "
             ORDER BY id DESC LIMIT 1", Scope::mas([$tel]));
        if (!$c) return null;

        if (!empty($c['ultimo_mensaje_at'])
            && strtotime($c['ultimo_mensaje_at']) < time() - self::HORAS_INACTIVIDAD * 3600) {
            $this->db->query("UPDATE wa_conversaciones SET estado = 'CERRADA' WHERE id = ?", [(int)$c['id']]);
            return null;
        }
        return $c;
    }

    /** ¿Este teléfono ya tiene conversación abierta? (para el cupo del plan) */
    public function existeViva(string $telefono): bool
    {
        return $this->viva(self::normalizar($telefono)) !== null;
    }

    /**
     * Guarda un mensaje. Devuelve el id, o 0 si era DUPLICADO.
     *
     * El cero es la señal de idempotencia (§39): el índice único sobre
     * message_id_externo hace que un webhook reintentado por Evolution choque
     * aquí y no llegue a procesarse dos veces.
     */
    public function guardarMensaje(int $convId, string $direccion, array $datos): int
    {
        try {
            return (int)$this->db->insert(
                "INSERT INTO wa_mensajes (conversacion_id, message_id_externo, direccion, tipo, contenido,
                                          media_ruta, media_mime, transcripcion, tokens_entrada, tokens_salida,
                                          proveedor, modelo, latencia_ms)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$convId, $datos['message_id'] ?? null, $direccion, $datos['tipo'] ?? 'texto',
                 $datos['contenido'] ?? null, $datos['media_ruta'] ?? null, $datos['media_mime'] ?? null,
                 $datos['transcripcion'] ?? null, (int)($datos['tokens_in'] ?? 0), (int)($datos['tokens_out'] ?? 0),
                 $datos['proveedor'] ?? null, $datos['modelo'] ?? null, (int)($datos['latencia_ms'] ?? 0)]);
        } catch (\Throwable $e) {
            // Choque contra uq_wa_msg_externo: mensaje ya procesado.
            if (strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'duplicate') !== false) {
                return 0;
            }
            throw $e;
        }
        // (inalcanzable; el return vive dentro del try)
    }

    public function tocar(int $convId): void
    {
        $this->db->query("UPDATE wa_conversaciones SET ultimo_mensaje_at = NOW() WHERE id = ?", [$convId]);
    }

    /**
     * Historia para el modelo, en formato neutro y acotada a VENTANA mensajes.
     * Solo texto: la media ya se convirtió en texto (transcripción o
     * descripción) antes de llegar aquí, y remandar audios en cada turno
     * multiplicaría el coste sin añadir nada.
     */
    public function historial(int $convId, int $limite = self::VENTANA): array
    {
        $rows = $this->db->fetchAll(
            "SELECT direccion, tipo, contenido, transcripcion FROM wa_mensajes
             WHERE conversacion_id = ? AND tipo != 'sistema'
             ORDER BY id DESC LIMIT " . max(1, (int)$limite), [$convId]);
        $rows = array_reverse($rows);

        $out = [];
        foreach ($rows as $r) {
            $texto = trim((string)($r['transcripcion'] ?: $r['contenido']));
            if ($texto === '') continue;
            $out[] = ['role' => $r['direccion'] === 'entrante' ? 'user' : 'assistant', 'content' => $texto];
        }
        return $out;
    }

    public function refrescar(int $convId): ?array
    {
        return $this->db->fetch("SELECT * FROM wa_conversaciones WHERE id = ?", [$convId]);
    }

    public function contexto(array $conv): array
    {
        $c = json_decode($conv['contexto'] ?? '{}', true);
        return is_array($c) ? $c : [];
    }

    public function guardarContexto(int $convId, array $ctx): void
    {
        $this->db->query("UPDATE wa_conversaciones SET contexto = ? WHERE id = ?",
            [json_encode($ctx, JSON_UNESCAPED_UNICODE), $convId]);
    }

    /** Cuenta las conversaciones del mes, para el cupo del plan. */
    public function conversacionesDelMes(): int
    {
        try {
            return (int)($this->db->fetch(
                "SELECT COUNT(*) c FROM wa_conversaciones
                 WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" . Scope::y(),
                Scope::mas())['c'] ?? 0);
        } catch (\Throwable $e) { return 0; }
    }
}
