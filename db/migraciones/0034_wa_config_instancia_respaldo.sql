-- =====================================================================
-- 0034 — Instancia de respaldo para failover manual del número de WhatsApp
--
-- `evolution_instancia` es SIEMPRE la instancia ACTIVA: la que el webhook y
-- los envíos usan (todo el código ya lee esa columna). El failover no añade
-- una segunda ruta en el código — solo INTERCAMBIA el valor de estas dos
-- columnas cuando el operador conmuta. Así el número de respaldo pasa a ser
-- el activo sin tocar el paquete ni el borde.
--
-- Failover MANUAL (decisión del PO, 2026-09-05): el vigilante avisa de la
-- caída y una persona pulsa «Conmutar» — no hay conmutación automática, para
-- no cambiar el número público por un parpadeo de red.
--
-- OJO WhatsApp: el failover de número NO es transparente. Un cliente que
-- escribió al número caído no migra solo al de respaldo (su chat quedó en el
-- número muerto). Sirve para los clientes NUEVOS, que llegan por la landing
-- una vez esta apunta al número activo — por eso conmutar actualiza también
-- `configuraciones.whatsapp_numero_negocio`.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `wa_config`
  ADD COLUMN `evolution_instancia_respaldo` varchar(100) DEFAULT NULL
  COMMENT 'Instancia de Evolution de RESERVA para el failover manual. Se intercambia con evolution_instancia al conmutar. Debe estar vinculada a OTRO teléfono.'
  AFTER `evolution_instancia`;
