-- =====================================================================
-- 0010 — Se retira el gate del conjunto dorado
--
-- Decisión del Product Owner, 2026-08-01, textual: «quita el gate, elegir
-- el modelo debe ser suficiente».
--
-- Deroga las dos mitades del ADR-016 que bloqueaban:
--
--   · `ck_modelo_primario_dorado`, que impedía que un modelo fuera primario
--     de `conversacion` sin corrida dorada en verde.
--   · La exclusividad de `ia.modelos.promover` en el rol `abogado`, que
--     obligaba a cambiar de sesión para poner en uso un modelo recién
--     configurado.
--
-- LO QUE **NO** SE BORRA, y por qué
--
-- Las columnas `dorado_*` se quedan, el corredor se queda y el panel las
-- sigue enseñando. Lo que se retira es el bloqueo, no la evidencia: saber
-- que un modelo pasó o falló el conjunto dorado sigue siendo el único dato
-- objetivo sobre si respeta las reglas inviolables, y borrarlo dejaría al
-- panel sin nada que decir sobre un modelo nuevo.
--
-- Además, ADR-013 manda migraciones aditivas: ningún `DROP COLUMN` en el
-- mismo despliegue que deja de usar la columna.
--
-- LO QUE SE PIERDE, dicho aquí para que quede en el historial
--
-- A partir de ahora un modelo puede empezar a hablar con clientes sin que
-- nadie haya comprobado que no suelta un plazo (regla 2), que no cita una
-- norma numerada (regla 3) y que no promete un resultado (regla 4). Las
-- reglas siguen escritas en el prompt y siguen probadas en el conjunto
-- dorado; lo que ya no existe es la comprobación de que ESE modelo las
-- cumple antes de servir.
--
-- Sigue habiendo dos redes debajo: el modo sombra de la Etapa 4 —la
-- respuesta va a nota privada y Pedro decide si se envía— y la propia
-- corrida dorada, que se puede seguir lanzando cuando se quiera.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Fuera el CHECK
--
--    Idempotente por `information_schema`: MySQL no admite
--    `DROP CONSTRAINT IF EXISTS` (ADR-013, CONTRATOS §Errores 14).
-- ---------------------------------------------------------------------
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modelos_ia'
      AND CONSTRAINT_NAME = 'ck_modelo_primario_dorado') > 0,
  'ALTER TABLE modelos_ia DROP CHECK ck_modelo_primario_dorado',
  'DO 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. `ia.modelos.promover` pasa también al super_admin
--
--    El permiso NO se borra ni se le quita al abogado: sigue pudiendo
--    ascender modelos. Lo que cambia es que deja de ser exclusivo suyo,
--    porque quien configura el proveedor en `/panel/ia` es el perfil
--    técnico y exigirle cambiar de sesión para poner en uso lo que acaba
--    de configurar convierte «elegir el modelo» en dos pasos con dos
--    cuentas.
--
--    Las otras tres asimetrías del ADR-007 —`ia.prompts.aprobar`,
--    `kb.verificar`, `contenido.publicar`— quedan INTACTAS. Esta decisión
--    es sobre el modelo, no sobre el reparto de firmas en general.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
 WHERE r.clave = 'super_admin' AND p.clave = 'ia.modelos.promover';
