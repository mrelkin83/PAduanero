-- =====================================================================
-- 0027 — Los enlaces de «confianza» llevan al sitio de verificación real
--
-- Aditiva y sin cambios de esquema (ADR-013): dos JSON_SET sobre la fila
-- `confianza`, cada uno con su guard.
--
-- «Tarjeta profesional» nació sin url (0014 la sembró vacía) y el NIT traía
-- en producción un enlace de búsqueda específico de RUES
-- (rues.org.co/buscar/RM/...) que no se puede confirmar que funcione: el
-- buscador de RUES es una aplicación de una sola página, y una ruta con el
-- término de búsqueda en la URL no garantiza que la cargue sin que medie
-- JavaScript propio del sitio. El PO pidió (2026-08-25) que los dos
-- enlaces lleven al sitio de verificación en sí, no a un resultado de
-- búsqueda que puede llegar en blanco:
--
--   · Tarjeta profesional → SIRNA, el sistema de consulta en línea del
--     Registro Nacional de Abogados del Consejo Superior de la Judicatura
--     (sirna.ramajudicial.gov.co).
--   · NIT → la portada de RUES (rues.org.co), la misma que ya traía la
--     semilla original (0014/0015) antes del enlace de búsqueda.
--
-- El guard mira la etiqueta Y la url actual: si alguien ya puso otro
-- enlace a mano desde el panel, esta migración no lo pisa.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE landing_bloques
   SET contenido = JSON_SET(contenido, '$.verificables[0].url', 'https://sirna.ramajudicial.gov.co/Paginas/Inicio.aspx')
 WHERE clave = 'confianza'
   AND JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.verificables[0].etiqueta')) = 'Tarjeta profesional'
   AND JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.verificables[0].url')) = '';

UPDATE landing_bloques
   SET contenido = JSON_SET(contenido, '$.verificables[1].url', 'https://www.rues.org.co/')
 WHERE clave = 'confianza'
   AND JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.verificables[1].etiqueta')) LIKE 'NIT%'
   AND JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.verificables[1].url')) LIKE '%/buscar/%';
