-- =====================================================================
-- 0009 — Deduplicación de los reintentos del webhook de Chatwoot
--
-- Aprobada por el PO (2026-08-01). Aditiva.
--
-- EL FALLO QUE CIERRA
--
-- Chatwoot reintenta un `message_created` cuando nuestra respuesta tarda. La
-- guarda que había comparaba el texto contra el buffer de ráfaga, lo que
-- cubre el reintento inmediato —el frecuente— y **no** el que llega después
-- de que la ventana se cerrara. Ese produce un segundo turno: otra llamada al
-- modelo, otro cobro, y otra respuesta en el hilo.
--
-- Hoy eso son dos borradores para Pedro. Con el envío automático de la Etapa 6
-- son dos mensajes al cliente, y es de los fallos que no se leen como error:
-- nadie piensa «el webhook se reintentó», piensa «el bot está raro».
--
-- POR QUÉ UNA COLUMNA Y NO REUSAR OTRA
--
-- Se consideró marcarlo dentro de un campo existente, como se hizo con
-- `eventos_outbox.disponible_en`. Se descarta: esa reutilización ya está
-- anotada como deuda con su disparador (`docs/PLAN_BUILD.md` §Etapa 5),
-- precisamente porque una columna con dos significados hace que la siguiente
-- consulta se escriba mal. Repetir el patrón para ahorrarse un `ALTER` sería
-- pagar dos veces el mismo error.
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversacion_estado'
      AND COLUMN_NAME = 'ultimo_mensaje_chatwoot_id') = 0,
  'ALTER TABLE conversacion_estado
     ADD COLUMN ultimo_mensaje_chatwoot_id BIGINT NULL AFTER ultimo_mensaje_en',
  'DO 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
