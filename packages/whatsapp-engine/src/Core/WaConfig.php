<?php
/**
 * ============================================================================
 * WaConfig — acceso a la configuración del motor (tabla wa_config, una fila)
 * ============================================================================
 * Mismo patrón que dian_config: una sola fila por negocio y los secretos
 * cifrados en su propia columna con encryptSecret() (AES-256, clave DEL TENANT).
 *
 * OJO — dependencia real: encryptSecret()/decryptSecret() resuelven la clave con
 * feEncryptionKey(), que lee `global $db`. Cualquier camino que no venga de
 * index.php (el webhook, los scripts de cron) DEBE dejar $GLOBALS['db']
 * apuntando a la BD del negocio ANTES de descifrar nada. Si no, se descifra con
 * la clave de otro negocio y sale basura — silenciosamente.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

class WaConfig
{
    /** Columnas cuyo contenido se guarda cifrado y JAMÁS se devuelve al frontend. */
    const SECRETOS = [
        'evolution_apikey', 'llm_api_key', 'llm_fallback_api_key',
        'stt_api_key', 'tts_api_key', 'vision_api_key',
        'wompi_private_key', 'wompi_events_secret', 'wompi_integrity_secret',
    ];

    private static $cache = [];

    /** Fila de configuración tal cual está en la BD (secretos aún cifrados). */
    public static function cargar($db, $refrescar = false)
    {
        // El id del negocio entra en la clave de caché: sin él, un proceso que
        // recorre varios negocios (el cron) se quedaba con la configuración del
        // primero y se la aplicaba a todos.
        $clave = spl_object_hash($db) . '#' . (string)(Scope::valor() ?? '');
        if (!$refrescar && isset(self::$cache[$clave])) return self::$cache[$clave];
        try {
            $row = Scope::aplica()
                ? $db->fetch("SELECT * FROM wa_config WHERE " . Scope::columna() . " = ?", [Scope::valor()])
                : $db->fetch("SELECT * FROM wa_config WHERE id = 1");
        } catch (\Throwable $e) {
            // Negocio sin migrar todavía: el motor simplemente no existe para él.
            return self::$cache[$clave] = null;
        }
        return self::$cache[$clave] = ($row ?: null);
    }

    /** Descifra un secreto de la fila. Devuelve '' si no hay nada guardado. */
    public static function secreto(?array $cfg, string $campo): string
    {
        if (!$cfg || empty($cfg[$campo])) return '';
        $v = \ElkinLinan\WhatsappAiEngine\Engine::secretos()->descifrar((string)$cfg[$campo]);
        return is_string($v) ? $v : '';
    }

    /**
     * ¿El motor puede atender? Exige las tres condiciones a la vez, y en este
     * orden: que el plan lo incluya, que el negocio lo haya encendido y que
     * haya un proveedor de IA configurado. Apagar cualquiera lo detiene.
     */
    public static function operativo($db): bool
    {
        $cfg = self::cargar($db);
        if (!$cfg || (int)$cfg['activo'] !== 1) return false;
        if (!\ElkinLinan\WhatsappAiEngine\Engine::funciones()->tiene('permite_whatsapp_ia')) return false;
        return !empty($cfg['llm_proveedor']) && !empty($cfg['llm_modelo']);
    }

    /**
     * Guarda cambios cifrando lo que toca. Un secreto que llega VACÍO no se
     * pisa: el frontend nunca recibe la clave real (§12), así que un formulario
     * enviado sin tocar ese campo borraría la credencial guardada.
     */
    public static function guardar($db, array $campos): void
    {
        $sets = []; $vals = [];
        foreach ($campos as $col => $val) {
            if (!preg_match('/^[a-z_]+$/', $col)) continue;
            if (in_array($col, self::SECRETOS, true)) {
                if ($val === '' || $val === null) continue;      // no tocar lo ya guardado
                $val = \ElkinLinan\WhatsappAiEngine\Engine::secretos()->cifrar((string)$val);
            }
            $sets[] = "`{$col}` = ?";
            $vals[] = $val;
        }
        if (!$sets) return;
        if (Scope::aplica()) {
            $col = Scope::columna();
            $db->query("INSERT IGNORE INTO wa_config ({$col}, activo) VALUES (?, 0)", [Scope::valor()]);
            $vals[] = Scope::valor();
            $db->query("UPDATE wa_config SET " . implode(', ', $sets) . " WHERE {$col} = ?", $vals);
        } else {
            $db->query("INSERT IGNORE INTO wa_config (id, activo) VALUES (1, 0)");
            $db->query("UPDATE wa_config SET " . implode(', ', $sets) . " WHERE id = 1", $vals);
        }
        self::$cache = [];
    }

    /**
     * Versión apta para el panel: los secretos salen como un booleano
     * ("hay clave guardada: sí/no"), nunca como texto — ni truncado (§12).
     */
    public static function paraFrontend($db): array
    {
        $cfg = self::cargar($db, true);
        if (!$cfg) return [];
        $out = [];
        foreach ($cfg as $k => $v) {
            if (in_array($k, self::SECRETOS, true)) {
                $out[$k . '_configurado'] = !empty($v);
                continue;
            }
            if ($k === 'webhook_token_hash') continue;           // ni el hash sale
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Genera un token de webhook nuevo y lo publica en el índice de la master.
     * Devuelve el token EN CLARO una sola vez: es lo único que se le enseña al
     * operador para pegarlo en Evolution. Después solo queda el hash.
     */
    public static function regenerarWebhookToken($db): string
    {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        self::guardar($db, ['webhook_token_hash' => $hash]);
        self::publicarEnMaster($db, $hash);
        return $token;
    }

    /**
     * Mantiene wa_instancias (master) al día. Es lo que permite que un webhook
     * sin cookies ni subdominio sepa a qué negocio pertenece.
     * En instalación de un solo negocio (sin tabla `tenants`) no hace nada:
     * ahí no hay ambigüedad posible.
     */
    public static function publicarEnMaster($db, ?string $hash = null): void
    {
        if (!\ElkinLinan\WhatsappAiEngine\Engine::negocio()->esMultiNegocio()) return;
        // Un proyecto que separa negocios POR COLUMNA no tiene base master ni
        // nada que enrutar: el token ya está en su propia fila de wa_config y
        // el webhook lo encuentra ahí. Escribir un índice de bases que no
        // existen solo dejaría un error en el log en cada guardado.
        if (Scope::aplica()) return;
        $cfg = self::cargar($db, true);
        if (!$cfg) return;
        $hash = $hash ?: ($cfg['webhook_token_hash'] ?? '');
        if (!$hash) return;

        $tenantId = \ElkinLinan\WhatsappAiEngine\Engine::negocio()->id();
        $dbName   = \ElkinLinan\WhatsappAiEngine\Engine::negocio()->baseDatos() ?: ($_SESSION['admin_tenant_db'] ?? '');
        if (!$tenantId || !$dbName) {
            error_log('[WaConfig] No se pudo publicar la instancia: tenant sin resolver');
            return;
        }
        try {
            $master = \ElkinLinan\WhatsappAiEngine\Engine::db()->maestra();
            $master->query(
                "INSERT INTO wa_instancias (tenant_id, db_name, webhook_token_hash, evolution_instancia, numero_whatsapp, activo)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE db_name = VALUES(db_name),
                     webhook_token_hash = VALUES(webhook_token_hash),
                     evolution_instancia = VALUES(evolution_instancia),
                     numero_whatsapp = VALUES(numero_whatsapp),
                     activo = VALUES(activo)",
                [(int)$tenantId, $dbName, $hash,
                 $cfg['evolution_instancia'] ?: null, $cfg['numero_whatsapp'] ?: null,
                 (int)$cfg['activo']]
            );
        } catch (\Throwable $e) {
            error_log('[WaConfig] publicarEnMaster: ' . $e->getMessage());
        }
    }

    /**
     * Resuelve el NEGOCIO a partir del token del webhook. Es el punto más
     * delicado del motor: aquí se decide en qué base de datos se va a escribir.
     *
     * Devuelve ['db' => \Database, 'tenant_id' => ?int, 'db_name' => ?string]
     * o null si el token no corresponde a nadie — el llamador responde 404 seco,
     * sin cuerpo y sin pistas.
     */
    public static function resolverPorToken(string $token): ?array
    {
        // OJO: esta función devuelve la conexión CRUDA del proyecto, no un
        // DbPort, y es a propósito. Lo que devuelve acaba en $GLOBALS['db'],
        // de donde encryptSecret()/decryptSecret() sacan la clave del negocio:
        // ponerle ahí una envoltura dejaría los secretos descifrándose mal y en
        // silencio. El enrutado multi-negocio es del hospedador, no del motor.

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $hash = hash('sha256', $token);

        // Instalación de un solo negocio: no hay master que consultar.
        if (!\ElkinLinan\WhatsappAiEngine\Engine::negocio()->esMultiNegocio()) {
            $db  = \Database::getInstance();
            $cfg = self::cargar($db);
            if (!$cfg || !hash_equals((string)$cfg['webhook_token_hash'], $hash)) return null;
            return ['db' => $db, 'tenant_id' => null, 'db_name' => null];
        }

        try {
            $row = \ElkinLinan\WhatsappAiEngine\Engine::db()->maestra()->fetch(
                "SELECT tenant_id, db_name FROM wa_instancias WHERE webhook_token_hash = ? AND activo = 1 LIMIT 1",
                [$hash]
            );
        } catch (\Throwable $e) {
            error_log('[WaConfig] resolverPorToken: ' . $e->getMessage());
            return null;
        }
        if (!$row) return null;

        try {
            $db = \Database::getInstance($row['db_name']);
        } catch (\Throwable $e) {
            error_log('[WaConfig] BD del negocio no accesible: ' . $e->getMessage());
            return null;
        }
        return ['db' => $db, 'tenant_id' => (int)$row['tenant_id'], 'db_name' => $row['db_name']];
    }

    /** Horario de atención decodificado. [] = sin horario definido (atiende siempre). */
    public static function horario(?array $cfg): array
    {
        if (!$cfg || empty($cfg['horario_atencion'])) return [];
        $h = json_decode($cfg['horario_atencion'], true);
        return is_array($h) ? $h : [];
    }

    /**
     * ¿Está abierto AHORA? El agente nunca razona esto: lo consulta.
     * Formato esperado: {"1":{"desde":"08:00","hasta":"22:00"}, …} con 0=domingo.
     */
    public static function abiertoAhora(?array $cfg): bool
    {
        $h = self::horario($cfg);
        if (!$h) return true;                       // sin horario configurado: siempre abierto
        $dia   = (string)(int)date('w');
        $franja = $h[$dia] ?? null;
        if (!$franja || empty($franja['desde']) || empty($franja['hasta'])) return false;
        $ahora = date('H:i');
        // Franja que cruza la medianoche (un bar que cierra a las 2 a.m.)
        if ($franja['hasta'] < $franja['desde']) {
            return $ahora >= $franja['desde'] || $ahora <= $franja['hasta'];
        }
        return $ahora >= $franja['desde'] && $ahora <= $franja['hasta'];
    }
}
