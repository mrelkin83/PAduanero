-- =====================================================================
-- 0017 — Recordatorio de citas (decisión del PO, 2026-08-22)
--
-- El bot ya agenda; ahora la cita avisa: al confirmarse se le notifica al
-- abogado por WhatsApp (eso no necesita esquema), y un cron
-- (bin/wa-recordatorios.php, cada 5 minutos) recuerda la cita al cliente y
-- al abogado cuando faltan menos de 30 minutos — por WhatsApp siempre, por
-- correo cuando el SMTP esté configurado.
--
-- La columna marca qué citas ya se recordaron: sin ella, cada pasada del
-- cron repetiría el aviso y el recordatorio se volvería spam. Se marca
-- ANTES de enviar, a propósito: perder un recordatorio por una caída rara
-- es mejor que mandarlo dos veces. Aditiva, como todas (ADR-013).
-- =====================================================================

SET NAMES utf8mb4;

-- `ADD COLUMN IF NOT EXISTS` es de MariaDB; en MySQL la idempotencia se
-- consigue consultando information_schema (mismo patrón de la 0005).
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'wa_citas'
     AND COLUMN_NAME = 'recordatorio_enviado_at'
);

SET @sql := IF(@existe = 0,
  'ALTER TABLE wa_citas ADD COLUMN recordatorio_enviado_at DATETIME NULL AFTER gcal_meet_url',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
