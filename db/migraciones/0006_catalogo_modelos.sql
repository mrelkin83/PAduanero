-- =====================================================================
-- 0006 — Catálogo de modelos con descubrimiento automático
--
-- El PO pidió que la lista de modelos se actualice sola: si mañana sale
-- Opus 6, que el sistema lo sepa sin que nadie edite código.
--
-- Se implementa como DESCUBRIMIENTO automático y ADOPCIÓN manual, y esa
-- distinción es el contenido entero de esta migración. Dos razones:
--
--  1. ADR-008. Un prompt nace inactivo y requiere aprobación registrada
--     del abogado, porque cambia lo que el bot dice. El modelo cambia lo
--     que el bot dice tanto o más que el prompt. Un modelo que se asciende
--     solo es la única pieza del sistema capaz de alterar el comportamiento
--     del bot sin que aparezca una firma en `auditoria`.
--
--  2. Ningún proveedor devuelve precios en su endpoint de modelos. Anthropic
--     da id, display_name, max_input_tokens, max_tokens y capabilities; no
--     da costo. Y `costo_entrada_usd_1m` es lo que alimenta el corte por
--     `presupuesto_ia_mensual_usd`. Un modelo dado de alta con costo NULL
--     hace que el presupuesto nunca se agote: el guardia deja de guardar y
--     no avisa. De ahí `costos_verificados`, y de ahí que el CHECK impida
--     que un modelo sin costo verificado sea primario.
--
-- Todo aditivo. `modelos_ia` está vacía, así que el CHECK no puede fallar
-- por filas preexistentes.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. PROCEDENCIA Y CICLO DE VIDA DE CADA MODELO
--
--    `visto_en` es la fecha de la última sincronización en que el proveedor
--    todavía listaba el modelo. `retirado_en` se pone cuando deja de
--    aparecer. No se borra la fila: `consumo_ia` apunta a ella y el
--    histórico de gasto tiene que seguir siendo legible dentro de un año.
-- ---------------------------------------------------------------------
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modelos_ia'
      AND COLUMN_NAME = 'origen') = 0,
  'ALTER TABLE modelos_ia
     ADD COLUMN origen ENUM(''manual'',''descubierto'') NOT NULL DEFAULT ''manual'' AFTER proposito,
     ADD COLUMN descubierto_en DATETIME NULL AFTER origen,
     ADD COLUMN visto_en       DATETIME NULL AFTER descubierto_en,
     ADD COLUMN retirado_en    DATETIME NULL AFTER visto_en,
     ADD COLUMN max_salida     INT      NULL AFTER ventana_contexto,
     ADD COLUMN capacidades    JSON     NULL AFTER max_salida',
  'DO 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. VERIFICACIÓN HUMANA DEL COSTO
--
--    Quién y cuándo. No es burocracia: es lo que permite responder «¿desde
--    cuándo estamos cobrando el presupuesto contra un precio equivocado?»
--    cuando la factura del proveedor no cuadre.
-- ---------------------------------------------------------------------
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modelos_ia'
      AND COLUMN_NAME = 'costos_verificados') = 0,
  'ALTER TABLE modelos_ia
     ADD COLUMN costos_verificados     TINYINT(1) NOT NULL DEFAULT 0 AFTER costo_salida_usd_1m,
     ADD COLUMN costos_verificados_por CHAR(36)   NULL AFTER costos_verificados,
     ADD COLUMN costos_verificados_en  DATETIME   NULL AFTER costos_verificados_por',
  'DO 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. UN MODELO SIN COSTO VERIFICADO NO PUEDE SER PRIMARIO
--
--    En base y no en el controlador, por lo mismo que `ux_modelo_primario`:
--    la invariante tiene que sobrevivir al script de mantenimiento que
--    alguien corra a mano a las once de la noche.
--
--    `retirado_en` NO entra en el CHECK, y la omisión es a propósito. Si
--    entrara, el cron no podría marcar el retiro del modelo que está en uso:
--    el UPDATE violaría la restricción y la sincronización fallaría entera
--    justo en el caso que más importa registrar. El retiro es información
--    del proveedor que llega cuando llega; el sistema debe poder anotarla
--    siempre. Lo que se prohíbe es ASCENDER un modelo retirado, y eso lo
--    hace el controlador porque es una decisión de la persona, no un
--    estado imposible de los datos.
--
--    Que un primario retirado no sea una caída lo garantiza la cascada:
--    `Llm` baja por `orden_fallback` ante 5xx o timeout (docs/CONTRATOS.md).
--    El retiro degrada el servicio, no lo apaga.
-- ---------------------------------------------------------------------
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modelos_ia'
      AND CONSTRAINT_NAME = 'ck_modelo_primario_apto') = 0,
  'ALTER TABLE modelos_ia
     ADD CONSTRAINT ck_modelo_primario_apto CHECK (
       es_primario = 0 OR (activo = 1 AND costos_verificados = 1)
     )',
  'DO 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índice para la vista del panel: «lo nuevo primero».
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modelos_ia'
      AND INDEX_NAME = 'ix_modelo_descubierto') = 0,
  'ALTER TABLE modelos_ia ADD INDEX ix_modelo_descubierto (retirado_en, descubierto_en)',
  'DO 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. BITÁCORA DE SINCRONIZACIONES
--
--    Un cron silencioso que lleva tres semanas fallando es peor que no
--    tenerlo, porque además da una falsa sensación de estar al día. Cada
--    corrida deja fila, incluida la que falla, y el panel enseña la última.
--
--    `error` guarda el motivo de red o el estado HTTP. Nunca la credencial:
--    las cabeceras no se registran.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sincronizaciones_modelos (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proveedor_id CHAR(36)    NOT NULL,
  ejecutado_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ok           TINYINT(1)  NOT NULL,
  nuevos       SMALLINT    NOT NULL DEFAULT 0,
  vistos       SMALLINT    NOT NULL DEFAULT 0,
  retirados    SMALLINT    NOT NULL DEFAULT 0,
  latencia_ms  INT         NULL,
  error        VARCHAR(300) NULL,
  PRIMARY KEY (id),
  KEY ix_sincro_prov (proveedor_id, ejecutado_en),
  CONSTRAINT fk_sincro_prov FOREIGN KEY (proveedor_id)
    REFERENCES proveedores_ia(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
