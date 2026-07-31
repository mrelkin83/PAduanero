-- =====================================================================
-- 0005 — Endurecimiento del segundo factor (Etapa 3)
--
-- Tres cambios, los tres aditivos y compatibles hacia atrás.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. ANTIRREPLAY DEL TOTP  (RFC 6238 §5.2)
--
--    «The verifier MUST NOT accept the second attempt of the OTP after
--     the successful validation has been issued for the first OTP.»
--
--    Sin esto, un código visto por encima del hombro —o leído de una
--    captura de pantalla, o de un registro de teclado— sirve durante toda
--    su ventana de vida. Se guarda el último contador aceptado y se
--    rechaza cualquiera que no sea estrictamente posterior.
--
--    BIGINT porque el contador es unix_time/30: en 2026 ronda 5,8e7, pero
--    un INT con signo se agotaría en el año 2038 junto con el resto del
--    mundo de 32 bits.
-- ---------------------------------------------------------------------
--    OJO con la sintaxis: `ADD COLUMN IF NOT EXISTS` es de MariaDB. MySQL
--    no lo admite, así que la idempotencia se consigue consultando
--    information_schema y preparando la sentencia solo si hace falta.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'usuarios'
     AND COLUMN_NAME = 'totp_ultimo_contador'
);

SET @sql := IF(@existe = 0,
  'ALTER TABLE usuarios ADD COLUMN totp_ultimo_contador BIGINT UNSIGNED NULL AFTER totp_activo',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. TOPE PROPIO PARA EL SEGUNDO FACTOR
--
--    El segundo factor son SEIS DÍGITOS. Quien llega a este paso ya pasó
--    la contraseña, así que sin un tope propio queda un espacio de un
--    millón de combinaciones abierto a fuerza bruta, protegido solo por
--    el contador de intentos de la contraseña — que mide otra cosa.
--
--    MODIFY sobre un ENUM añadiendo un valor al final es compatible: las
--    filas existentes conservan su valor y su índice.
-- ---------------------------------------------------------------------
ALTER TABLE intentos_acceso
  MODIFY COLUMN accion ENUM('login','totp','webhook_pago','recuperacion') NOT NULL;

-- ---------------------------------------------------------------------
-- 3. RETENCIÓN DE INTENTOS DE ACCESO, COMO CONFIGURACIÓN
--
--    `intentos_acceso` guarda direcciones IP, que son dato personal bajo
--    la Ley 1581 de 2012. Cuánto se conservan es una decisión de
--    tratamiento de datos, no un detalle de implementación: tiene que
--    poder cambiarse sin desplegar.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO configuraciones
  (clave, valor, tipo, grupo, etiqueta, ayuda, rol_minimo, minimo, maximo, opciones)
VALUES
('retencion_intentos_acceso_dias',
 '30','entero','legal','Retención de intentos de acceso (días)',
 'Guardan direcciones IP, que son dato personal. Las purga bin/cron-purgar.php. Bajarlo de 7 deja sin material para investigar un incidente.',
 'abogado',1,365,NULL),

('totp_max_intentos',
 '5','entero','legal','Intentos de código de verificación antes de bloquear',
 'El segundo factor son seis dígitos: sin tope propio se puede forzar por fuerza bruta con la contraseña ya validada.',
 'super_admin',3,20,NULL);
