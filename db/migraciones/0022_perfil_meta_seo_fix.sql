-- =====================================================================
-- 0022 — Corrige la 0021: comparar JSON con `=` no castea sola
--
-- Aditiva y sin cambios de esquema (ADR-013).
--
-- `configuraciones.valor` es de tipo JSON. `valor = '"texto"'` en el WHERE
-- no hace el cast implícito que sí hace, por ejemplo, un INSERT — la 0021
-- se marcó "Aplicada" pero actualizó CERO filas, en local y en el VPS.
-- Comprobado a mano:
--   SELECT valor = '"..."' -> 0
--   SELECT valor = CAST('"..."' AS JSON) -> 1
-- Aquí se usa JSON_UNQUOTE(valor) contra el texto plano, sin comillas de
-- JSON alrededor — evita el problema entero y es más legible.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE configuraciones
   SET valor = '"Abogado aduanero y tributario: diagnostique su caso"'
 WHERE clave = 'perfil_meta_titulo'
   AND JSON_UNQUOTE(valor) = 'Diagnostique su caso ante la DIAN en dos minutos';

UPDATE configuraciones
   SET valor = '"Identifique su caso aduanero o tributario ante la DIAN en seis preguntas, gratis y sin dejar datos, con un abogado especialista en derecho aduanero."'
 WHERE clave = 'perfil_meta_descripcion'
   AND JSON_UNQUOTE(valor) = 'Seis preguntas para saber qué le está pasando con la DIAN y cómo se llama. Sin dejar datos y sin costo.';
