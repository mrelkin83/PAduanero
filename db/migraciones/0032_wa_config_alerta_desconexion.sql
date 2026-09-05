-- =====================================================================
-- 0032 — Alerta de sesión de WhatsApp caída, configurable en el panel
--
-- bin/wa-vigilar.php (cron cada 5 min) pregunta a Evolution el estado REAL
-- de la instancia y avisa cuando cambia. El aviso NO puede salir por la
-- misma instancia que se cayó, así que sale por una instancia DISTINTA de
-- Evolution — un número de respaldo, en otro teléfono — y opcionalmente por
-- correo. El PO pidió que esto se configure desde el panel, no en el .env,
-- para no tener que tocar el servidor cada vez que cambie el número.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `wa_config`
  ADD COLUMN `alerta_whatsapp_numero` varchar(25) DEFAULT NULL
  COMMENT 'E.164 sin +. Recibe el aviso de bin/wa-vigilar.php. Debe ser distinto del número del negocio.'
  AFTER `handoff_numero`,
  ADD COLUMN `alerta_whatsapp_instancia` varchar(100) DEFAULT NULL
  COMMENT 'Instancia de Evolution que ENVÍA la alerta (otro teléfono). Vacío = esa alerta no sale por WhatsApp.'
  AFTER `alerta_whatsapp_numero`,
  ADD COLUMN `alerta_correo` varchar(190) DEFAULT NULL
  COMMENT 'Correo que recibe la misma alerta cuando hay SMTP configurado (App\\Soporte\\Smtp). Independiente del canal de WhatsApp.'
  AFTER `alerta_whatsapp_instancia`;
