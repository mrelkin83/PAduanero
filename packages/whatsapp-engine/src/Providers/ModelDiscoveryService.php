<?php
/**
 * ============================================================================
 * ModelDiscoveryService — descubrimiento de modelos (§13 y §14)
 * ============================================================================
 * Pregunta al proveedor qué modelos ofrece hoy y lo guarda en wa_modelos.
 *
 * LA REGLA QUE MANDA (§14): descubrir un modelo nuevo NO migra a nadie. Se
 * marca como 'nuevo', el panel lo muestra con un aviso, y el administrador
 * decide. Cambiarle el modelo a un negocio en producción por debajo, mientras
 * atiende clientes, sería exactamente lo que el prompt maestro prohíbe.
 *
 * Un modelo que el proveedor deja de listar se marca 'retirado' — nunca se
 * borra: si un negocio lo tiene configurado, hay que poder explicárselo.
 */

namespace ElkinLinan\WhatsappAiEngine\Providers;


class ModelDiscoveryService
{
    private $db;
    private $log;

    public function __construct($db, $log = null)
    {
        $this->db  = $db;
        $this->log = $log;
    }

    /**
     * Sincroniza un proveedor. @return ['ok','total','nuevos','retirados','error']
     */
    public function sincronizar(string $proveedor, string $apiKey): array
    {
        $out = ['ok' => false, 'total' => 0, 'nuevos' => 0, 'retirados' => 0, 'error' => ''];
        $adapter = LlmProviderManager::crear($proveedor, $apiKey, 'placeholder');
        if (!$adapter) { $out['error'] = 'Proveedor no soportado o sin API Key'; return $out; }

        try {
            $modelos = $adapter->listarModelos();
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
        if (!$modelos) {
            // Se le pregunta al proveedor POR QUÉ. listarModelos() devuelve un
            // array vacío tanto si la clave es inválida como si el catálogo no
            // existe o la red falló, y responder «no devolvió modelos» deja al
            // operador sin nada que hacer. validarCredenciales() sí trae el
            // motivo real («API Key inválida», el mensaje del proveedor…).
            $v = $adapter->validarCredenciales();
            $out['error'] = !empty($v['error'])
                ? ('El proveedor respondió: ' . $v['error'])
                : 'El proveedor no expone catálogo de modelos. Escribe el identificador a mano.';
            return $out;
        }

        $primeraVez = (int)($this->db->fetch(
            "SELECT COUNT(*) c FROM wa_modelos WHERE proveedor = ?", [$proveedor])['c'] ?? 0) === 0;

        $vistos = [];
        foreach ($modelos as $m) {
            if (empty($m['modelo_id'])) continue;
            $vistos[] = $m['modelo_id'];
            $existe = $this->db->fetch(
                "SELECT id FROM wa_modelos WHERE proveedor = ? AND modelo_id = ?", [$proveedor, $m['modelo_id']]);
            if ($existe) {
                // Reaparecer devuelve a 'disponible' un modelo antes retirado.
                $this->db->query(
                    "UPDATE wa_modelos SET nombre = ?, contexto_max = ?, soporta_vision = ?, soporta_tools = ?,
                            visto_ultima_vez = NOW(), estado = IF(estado = 'retirado', 'disponible', estado)
                     WHERE id = ?",
                    [$m['nombre'] ?? $m['modelo_id'], (int)($m['contexto_max'] ?? 0),
                     !empty($m['soporta_vision']) ? 1 : 0, !empty($m['soporta_tools']) ? 1 : 0, $existe['id']]
                );
                continue;
            }
            // En la PRIMERA sincronización todo entra como 'disponible': marcar
            // 200 modelos como "nuevos" el día que se configura el proveedor
            // convierte el aviso en ruido y deja de significar nada.
            $this->db->insert(
                "INSERT INTO wa_modelos (proveedor, modelo_id, nombre, contexto_max, soporta_vision, soporta_tools, estado)
                 VALUES (?,?,?,?,?,?,?)",
                [$proveedor, $m['modelo_id'], $m['nombre'] ?? $m['modelo_id'], (int)($m['contexto_max'] ?? 0),
                 !empty($m['soporta_vision']) ? 1 : 0, !empty($m['soporta_tools']) ? 1 : 0,
                 $primeraVez ? 'disponible' : 'nuevo']
            );
            if (!$primeraVez) $out['nuevos']++;
        }

        if ($vistos) {
            $ph = implode(',', array_fill(0, count($vistos), '?'));
            $this->db->query(
                "UPDATE wa_modelos SET estado = 'retirado'
                 WHERE proveedor = ? AND estado != 'retirado' AND modelo_id NOT IN ($ph)",
                array_merge([$proveedor], $vistos)
            );
            $out['retirados'] = (int)($this->db->fetch(
                "SELECT COUNT(*) c FROM wa_modelos WHERE proveedor = ? AND estado = 'retirado'", [$proveedor])['c'] ?? 0);
        }

        $out['ok'] = true;
        $out['total'] = count($vistos);
        if ($this->log) {
            $this->log->log('config', 'Modelos sincronizados: ' . $proveedor, [
                'total' => $out['total'], 'nuevos' => $out['nuevos'], 'retirados' => $out['retirados']]);
        }
        return $out;
    }

    /** Modelos de un proveedor para el desplegable del panel. */
    public function listar(string $proveedor = ''): array
    {
        if ($proveedor !== '') {
            return $this->db->fetchAll(
                "SELECT * FROM wa_modelos WHERE proveedor = ? AND estado != 'retirado' ORDER BY estado = 'nuevo' DESC, modelo_id",
                [$proveedor]);
        }
        return $this->db->fetchAll("SELECT * FROM wa_modelos WHERE estado != 'retirado' ORDER BY proveedor, modelo_id");
    }

    /** Cuántos modelos nuevos hay sin revisar (para el aviso del panel). */
    public function nuevosSinRevisar(): int
    {
        try {
            return (int)($this->db->fetch("SELECT COUNT(*) c FROM wa_modelos WHERE estado = 'nuevo'")['c'] ?? 0);
        } catch (\Throwable $e) { return 0; }
    }

    /** El administrador vio los modelos nuevos: dejan de destacarse. */
    public function marcarRevisados(): void
    {
        $this->db->query("UPDATE wa_modelos SET estado = 'disponible' WHERE estado = 'nuevo'");
    }
}
