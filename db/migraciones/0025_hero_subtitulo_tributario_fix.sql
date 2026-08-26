-- =====================================================================
-- 0025 — Corrige la 0024: el REPLACE del subtítulo del hero no distingue
-- mayúsculas
--
-- Aditiva y sin cambios de esquema (ADR-013).
--
-- `REPLACE()` en MySQL compara byte a byte y NO usa la collation de la
-- columna para mayúsculas/minúsculas (a diferencia de `=` o `LIKE`, que sí
-- la respetan). El subtítulo real del hero en producción tenía «Derecho
-- Aduanero, Comercio Exterior» con mayúsculas (alguien lo editó así desde
-- el panel en algún momento), distinto del texto en minúsculas que trae
-- la semilla (0003) y que la 0024 buscaba — así que la 0024 no tocó esta
-- fila ahí, aunque sí corrigió todo lo demás (meta, credenciales, casos,
-- JSON-LD, pie, wa_agentes).
--
-- Dos REPLACE, uno por cada capitalización posible, cada uno con su guard.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE landing_bloques
   SET subtitulo = REPLACE(
         subtitulo,
         'Derecho Aduanero, Comercio Exterior y tributario',
         'Derecho Aduanero y Comercio Exterior'
       )
 WHERE clave = 'hero' AND subtitulo LIKE '%Derecho Aduanero, Comercio Exterior y tributario%';

UPDATE landing_bloques
   SET subtitulo = REPLACE(
         subtitulo,
         'derecho aduanero, comercio exterior y tributario',
         'derecho aduanero y comercio exterior'
       )
 WHERE clave = 'hero' AND subtitulo LIKE '%derecho aduanero, comercio exterior y tributario%';
