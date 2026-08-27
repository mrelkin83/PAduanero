-- =====================================================================
-- 0031 — `intentos_acceso.accion` admite 'login_comprador'
--
-- AutenticacionComprador (Task 5 del plan de cobro y cuenta de comprador)
-- reutiliza IntentoAccesoRepo tal cual, con 'login_comprador' como su
-- $accion — mismo repositorio que el panel, pero con su propio contador
-- para no mezclar los intentos de fuerza bruta contra cuentas de
-- comprador con los del panel.
--
-- MODIFY sobre un ENUM añadiendo un valor al final es compatible: las
-- filas existentes conservan su valor y su índice (mismo razonamiento que
-- 0005 al añadir 'totp').
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE intentos_acceso
  MODIFY COLUMN accion ENUM('login','totp','webhook_pago','recuperacion','login_comprador') NOT NULL;
