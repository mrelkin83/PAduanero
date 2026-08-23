-- =====================================================================
-- 0018 — El recordatorio de citas se configura desde el panel
--
-- La 0017 dejó el recordatorio cableado a 30 minutos y sin pantalla; el
-- PO pidió verlo y gobernarlo desde el panel (sección Horario y agenda).
-- Aditiva, como todas (ADR-013).
-- =====================================================================

SET NAMES utf8mb4;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'wa_config'
     AND COLUMN_NAME = 'recordatorio_minutos'
);

SET @sql := IF(@existe = 0,
  'ALTER TABLE wa_config ADD COLUMN recordatorio_minutos INT NOT NULL DEFAULT 30
     COMMENT ''Minutos antes de la cita para recordarla; 0 = sin recordatorio''',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
