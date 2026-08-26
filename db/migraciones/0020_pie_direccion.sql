-- =====================================================================
-- 0020 — Dirección en el pie de página
--
-- Aditiva y sin cambios de esquema (ADR-013): un JSON_SET sobre la fila
-- `pie` de `landing_bloques` y nada más.
--
-- La 0019 dejó la dirección fuera del pie a propósito, para que viviera
-- solo en `confianza.sedes` (0014) y no hubiera una segunda verdad, como
-- se cuidó con el teléfono. El PO pidió lo contrario (2026-08-25): el pie
-- se repite en todas las páginas públicas —landing, `/perfil`, legales—
-- y `confianza` solo se pinta en la landing, así que hoy la dirección no
-- aparece en ninguna de las otras.
--
-- Nace vacía, como `telefono`: la plantilla omite en silencio el campo sin
-- dato (regla de 0014) y el panel es quien la llena cuando Pedro confirme
-- la sede que quiere publicar ahí. No lleva `pendiente`: el pie nunca usó
-- esa marca para sus campos sueltos, solo para las listas (`redes`).
--
-- JSON_SET no pisa nada si la clave ya existe, así que la migración es
-- segura de repetir aunque alguien ya la haya corrido a mano.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE landing_bloques
   SET contenido = JSON_SET(contenido, '$.direccion', '')
 WHERE clave = 'pie'
   AND JSON_EXTRACT(contenido, '$.direccion') IS NULL;
