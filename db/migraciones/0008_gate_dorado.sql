-- =====================================================================
-- 0008 — La firma del abogado sobre el modelo, y el gate que la hace
--        significativa
--
-- Decisión del PO (2026-07-31), completando ADR-016.
--
-- DOS CAMBIOS QUE VAN JUNTOS
--
--  1. `ia.modelos.promover` es del ABOGADO, no del super_admin. La simetría
--     con ADR-008 es exacta: el super_admin descubre, configura, verifica
--     costos y prueba conexión —todo el trabajo técnico— pero no puede
--     ascender un modelo, igual que no puede aprobar un prompt. Lo que el
--     abogado firma no es la calidad técnica del modelo: es que asume la
--     responsabilidad profesional de lo que el bot diga a partir de ese
--     momento.
--
--  2. Esa firma no vale nada si es sobre un nombre. Un modelo no puede ser
--     primario de `conversacion` sin que el conjunto dorado haya corrido en
--     verde CONTRA ÉSE modelo. Así Pedro no aprueba «claude-opus-6»: aprueba
--     un modelo que ya demostró no soltar un plazo, no citar una norma
--     numerada y no prometer un resultado.
--
--     Efecto lateral y deseado: el conjunto dorado deja de perder valor
--     cuando cambia el modelo, porque cambiar de modelo obliga a recorrerlo.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. RESULTADO DE LA CORRIDA DORADA, POR MODELO
--
--    `dorado_prompt_id` es lo que permite detectar que la corrida quedó
--    obsoleta: si el prompt activo cambió después, el verde de ayer ya no
--    dice nada sobre lo que el bot diría hoy. Esa comprobación cruza dos
--    tablas y por tanto no cabe en un CHECK; la hace `GateDorado`.
--
--    `dorado_detalle` guarda el recuento por categoría de regla, no el texto
--    de las conversaciones.
-- ---------------------------------------------------------------------
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modelos_ia'
      AND COLUMN_NAME = 'dorado_estado') = 0,
  'ALTER TABLE modelos_ia
     ADD COLUMN dorado_estado ENUM(''sin_correr'',''verde'',''rojo'')
                NOT NULL DEFAULT ''sin_correr'' AFTER costos_verificados_en,
     ADD COLUMN dorado_en        DATETIME  NULL AFTER dorado_estado,
     ADD COLUMN dorado_prompt_id CHAR(36)  NULL AFTER dorado_en,
     ADD COLUMN dorado_casos     SMALLINT  NULL AFTER dorado_prompt_id,
     ADD COLUMN dorado_fallos    SMALLINT  NULL AFTER dorado_casos,
     ADD COLUMN dorado_detalle   JSON      NULL AFTER dorado_fallos,
     ADD COLUMN dorado_por       CHAR(36)  NULL AFTER dorado_detalle',
  'DO 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. SIN CORRIDA VERDE NO HAY PRIMARIO DE CONVERSACIÓN
--
--    Constraint aparte y no ampliando `ck_modelo_primario_apto`, porque son
--    dos reglas distintas: aquélla dice «un primario tiene que ser usable y
--    tener precio»; ésta dice «un primario que habla con clientes tiene que
--    haber pasado las reglas inviolables». Separadas, el mensaje de error
--    dice cuál se violó.
--
--    Solo aplica a `conversacion`. Un modelo de embeddings no le dice nada a
--    nadie: exigirle el conjunto dorado sería teatro.
--
--    Lo que este CHECK NO puede ver es si la corrida quedó obsoleta al
--    cambiar el prompt, porque eso vive en otra tabla. Esa mitad la impone
--    `GateDorado`.
-- ---------------------------------------------------------------------
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modelos_ia'
      AND CONSTRAINT_NAME = 'ck_modelo_primario_dorado') = 0,
  'ALTER TABLE modelos_ia
     ADD CONSTRAINT ck_modelo_primario_dorado CHECK (
       es_primario = 0
       OR proposito <> ''conversacion''
       OR dorado_estado = ''verde''
     )',
  'DO 0'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. EL PERMISO
--
--    Tercera asimetría del ADR-007, junto a `ia.prompts.aprobar`,
--    `kb.verificar` y `contenido.publicar`: el super_admin NO lo tiene.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, modulo) VALUES ('ia.modelos.promover','ia');

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
 WHERE r.clave = 'abogado' AND p.clave = 'ia.modelos.promover';

-- Si alguien se lo concedió al super_admin a mano, se revoca. La matriz es
-- normativa y este es el punto donde se aplica.
DELETE rp FROM roles_permisos rp
  JOIN roles r    ON r.id = rp.rol_id
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE r.clave = 'super_admin' AND p.clave = 'ia.modelos.promover';
