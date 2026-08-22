-- =====================================================================
-- 0017 — Datos de transferencia por campos, no por texto libre
--
-- El campo único «pago_datos_transferencia» no decía QUÉ poner: ¿el Nequi?,
-- ¿el banco?, ¿todo junto y en qué orden? (observación del PO, 2026-08-22).
--
-- El motor no cambia: sigue leyendo `pago_datos_transferencia` como el TEXTO
-- que el bot le dicta al cliente («dale EXACTAMENTE estos datos»). Lo que se
-- añade es la fuente estructurada de ese texto: el panel pinta un formulario
-- por método (Nequi, Daviplata, Bre-B, banco, titular), guarda aquí el JSON,
-- y COMPONE el texto. Editar vuelve a partir del JSON — parsear el texto
-- compuesto de vuelta sería adivinar.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `wa_config`
  ADD COLUMN `pago_transferencia_json` text DEFAULT NULL
  COMMENT 'Fuente estructurada de pago_datos_transferencia; la edita el panel, el motor no la lee'
  AFTER `pago_datos_transferencia`;
