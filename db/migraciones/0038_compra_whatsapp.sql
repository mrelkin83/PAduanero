-- =====================================================================
-- 0038 — Número de WhatsApp del comprador en la compra
--
-- El formulario de compra pedía solo nombre y correo. El PO (2026-09-05)
-- pide también el WhatsApp: es el canal por el que el despacho contacta y
-- notifica al comprador, y queda a la mano desde el momento de la compra —
-- antes se pedía recién al completar la cuenta.
--
-- Aditiva (ADR-013). NULL para las compras viejas que no lo tienen.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `compras_curso`
  ADD COLUMN `whatsapp` varchar(25) DEFAULT NULL
  COMMENT 'WhatsApp del comprador, solo dígitos con indicativo (ej. 573001234567).'
  AFTER `correo`;
