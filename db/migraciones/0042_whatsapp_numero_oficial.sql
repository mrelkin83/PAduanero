-- =====================================================================
-- 0042 — Un solo número de WhatsApp: 573112335405
--
-- Decisión del PO (2026-09-11): «el número oficial es ese, lo demás eran de
-- prueba». El sitio anunciaba tres:
--
--   · `configuraciones.whatsapp_numero_negocio` = 573112335405  ← el bueno
--   · bloque `pie`, `telefonos[0]`              = (57) 310 223 5405
--   · semilla 0003 y CLAUDE.md §7               = 573159923676
--
-- Los dos primeros se parecían demasiado —311 233 / 310 223— y era eso: un
-- dígito transpuesto al teclearlo en el panel. El tercero es el valor con
-- que se sembró la base y que nadie llegó a corregir.
--
-- Importa más de lo que parece porque el embudo entero termina en ese
-- enlace (CLAUDE.md §0): un número equivocado ahí no da ningún error, solo
-- manda los clientes al teléfono de otra persona, y desde este lado el
-- síntoma es que no contesta nadie.
--
-- LO QUE ESTA MIGRACIÓN NO TOCA, a propósito:
--
--   `wa_config.handoff_numero` (hoy 573124132002). No es el número público
--   del embudo: es el teléfono al que el bot traspasa la conversación
--   cuando escala a un humano, y tiene que ser DISTINTO del número en el
--   que corre el bot — si fueran el mismo, el traspaso sería a sí mismo.
--   Se edita desde `/panel/whatsapp` y lo decide quien esté de guardia.
--
-- Aditiva y sin cambios de esquema (ADR-013). Cada UPDATE lleva su guarda:
-- si el valor ya es el correcto, no toca nada.
-- =====================================================================

SET NAMES utf8mb4;

-- ── El número del botón, del diagnóstico y de las páginas legales ──────
UPDATE configuraciones
   SET valor = '"573112335405"'
 WHERE clave = 'whatsapp_numero_negocio'
   AND JSON_UNQUOTE(valor) <> '573112335405';

-- ── El teléfono del pie ────────────────────────────────────────────────
-- `telefonos[0]` es el único con valor; el segundo está vacío a propósito
-- (la plantilla omite los vacíos uno por uno — ver 0023). El formato con
-- separaciones es el que lee la persona; la plantilla arma el `tel:`
-- quitándole todo lo que no sea dígito.
UPDATE landing_bloques
   SET contenido = JSON_REPLACE(contenido, '$.telefonos[0].numero', '(57) 311 233 5405')
 WHERE clave = 'pie'
   AND JSON_EXTRACT(contenido, '$.telefonos[0].numero') IS NOT NULL
   AND JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.telefonos[0].numero')) <> '(57) 311 233 5405';
