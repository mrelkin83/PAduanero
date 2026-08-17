-- =====================================================================
-- 0013 — Contenido nuevo del rediseño de la landing
--
-- Aditiva y sin cambios de esquema (ADR-013): solo mete dos claves en el
-- JSON de bloques que ya existen. No se pueden sembrar con el `INSERT
-- IGNORE` de 0012 porque las filas `hero` y `cta_final` vienen de 0004 y
-- ya están; un INSERT las ignoraría enteras y las claves nunca llegarían.
--
-- Idempotente por el `IS NULL` del WHERE y no por el `IGNORE`: si la
-- migración se reaplicara sobre una base donde el abogado ya editó las
-- cifras desde el panel, sobreescribirlas sería perderle el trabajo.
-- Aquí «ya existe» significa «no la toques», no «reemplázala».
--
-- Las dos plantillas traen además el mismo valor por defecto en código, así
-- que un entorno sin esta migración pinta lo mismo. La migración no existe
-- para que la página funcione: existe para que el contenido sea editable
-- sin tocar una plantilla, que es la frontera del panel (ADR-006).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Las cifras del hero
--
-- Son datos verificables, y esa es toda la razón por la que pueden ir al
-- tamaño al que van: «15+ años ante la DIAN» se comprueba, «el mejor
-- abogado» no cabría aquí ni bajo la Ley 1123 de 2007 (regla 4). Ninguna
-- promete un resultado y ninguna nombra un plazo.
--
-- `CO` no es una cifra sino un código de país, y va en la misma fila a
-- propósito: en esta página lo monoespaciado es lo administrativamente
-- real, y la cobertura territorial lo es tanto como los años.
-- ---------------------------------------------------------------------
UPDATE landing_bloques
   SET contenido = JSON_SET(
         contenido,
         '$.cifras',
         JSON_ARRAY(
           JSON_OBJECT('cifra', '15+', 'nota', 'años ante la DIAN'),
           JSON_OBJECT('cifra', '02',  'nota', 'especializaciones'),
           JSON_OBJECT('cifra', 'CO',  'nota', 'todo el territorio')
         )
       )
 WHERE clave = 'hero'
   AND JSON_EXTRACT(contenido, '$.cifras') IS NULL;

-- ---------------------------------------------------------------------
-- 2. La salida al diagnóstico desde el cierre
--
-- Quien llega al final sin escribir suele ser quien todavía no sabe cómo
-- se llama lo que le pasa. A $400.000 la hora esa persona no pulsa el botón
-- verde, pero seis preguntas sí las contesta; el diagnóstico es la rampa.
--
-- El `hero` ya traía `cta_secundario` desde 0004 y por eso no se toca: el
-- suyo baja a «cómo funciona», que es lo que hace falta al principio de la
-- página, no al final.
-- ---------------------------------------------------------------------
UPDATE landing_bloques
   SET contenido = JSON_SET(
         contenido,
         '$.cta_secundario',
         'O diagnostique su caso primero'
       )
 WHERE clave = 'cta_final'
   AND JSON_EXTRACT(contenido, '$.cta_secundario') IS NULL;
