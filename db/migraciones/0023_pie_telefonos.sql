-- =====================================================================
-- 0023 — Tres teléfonos en el pie, no uno
--
-- Aditiva y sin cambios de esquema (ADR-013): un JSON_SET + JSON_REMOVE
-- sobre la fila `pie` de `landing_bloques`.
--
-- El campo `telefono` (0019) era un solo texto libre, y en producción
-- Pedro metió DOS números separados por un guion en ese único campo
-- ("(57) 311 477 15 25 - 300 123 4567"). La plantilla arma `tel:` quitando
-- todo lo que no sea dígito, así que el resultado era un solo número sin
-- sentido que concatenaba los dos. El PO pidió (2026-08-25) tres campos de
-- teléfono en vez de uno, para que cada número sea su propio enlace `tel:`.
--
-- `telefonos` nace con tres huecos: el primero se rescata del valor viejo
-- de `telefono` tal cual estaba (aunque venga con dos números pegados —
-- no se intenta adivinar dónde partirlo, eso lo corrige una persona desde
-- el panel), los otros dos vacíos. La lista no nace vacía por la misma
-- razón que `redes` en 0019: el panel clona la forma del primer elemento
-- para añadir más, y una lista vacía no tiene de dónde clonar.
--
-- El guard evita repetir la migración si `telefonos` ya existe.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE landing_bloques
   SET contenido = JSON_REMOVE(
         JSON_SET(
           contenido, '$.telefonos',
           JSON_ARRAY(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.telefono')), ''), '', '')
         ),
         '$.telefono'
       )
 WHERE clave = 'pie'
   AND JSON_EXTRACT(contenido, '$.telefonos') IS NULL;
