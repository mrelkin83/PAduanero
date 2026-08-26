-- =====================================================================
-- 0029 — El testimonio lleva el logo de la empresa, y el logo enlaza
--
-- Aditiva y sin cambios de esquema (ADR-013): JSON_SET sobre cada elemento
-- de `testimonios.items`, con guarda.
--
-- Pedido del PO: en el testimonio, el logo de la empresa cliente hace de
-- botón hacia su sitio o red social. Dos claves nuevas por elemento:
--
--   · `logo`  — ruta en /img, igual que el resto de imágenes del sitio
--     (§7 de CLAUDE.md: disco `public/img/`, sin mecanismo de subida).
--     Pedro sube el archivo al servidor y escribe el nombre en el panel,
--     como ya hace con `imagen` en hero/credenciales/proceso/cta_final.
--   · `url`   — a dónde lleva el logo al presionarlo. Mismo nombre de
--     clave que ya usa `confianza.verificables[].url` (0014/0027): no hay
--     razón para inventar un segundo nombre para «un enlace».
--
-- Como en 0027, el guard es por índice y evita pisar un valor que alguien
-- ya haya puesto a mano desde el panel entre que se escribió esta migración
-- y se desplegó.
--
-- El límite de 20 elementos es generoso para lo que es hoy una lista que
-- nace con 3 (0015) y se cura a mano uno por uno desde el panel — no hace
-- falta una construcción con bucle en SQL para una lista que no va a tener
-- cientos de posiciones.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[0].logo', '', '$.items[0].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[0]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[0].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[1].logo', '', '$.items[1].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[1]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[1].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[2].logo', '', '$.items[2].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[2]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[2].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[3].logo', '', '$.items[3].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[3]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[3].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[4].logo', '', '$.items[4].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[4]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[4].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[5].logo', '', '$.items[5].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[5]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[5].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[6].logo', '', '$.items[6].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[6]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[6].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[7].logo', '', '$.items[7].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[7]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[7].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[8].logo', '', '$.items[8].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[8]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[8].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[9].logo', '', '$.items[9].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[9]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[9].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[10].logo', '', '$.items[10].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[10]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[10].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[11].logo', '', '$.items[11].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[11]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[11].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[12].logo', '', '$.items[12].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[12]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[12].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[13].logo', '', '$.items[13].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[13]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[13].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[14].logo', '', '$.items[14].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[14]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[14].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[15].logo', '', '$.items[15].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[15]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[15].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[16].logo', '', '$.items[16].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[16]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[16].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[17].logo', '', '$.items[17].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[17]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[17].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[18].logo', '', '$.items[18].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[18]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[18].logo') IS NULL;
UPDATE landing_bloques SET contenido = JSON_SET(contenido, '$.items[19].logo', '', '$.items[19].url', '') WHERE clave = 'testimonios' AND JSON_EXTRACT(contenido, '$.items[19]') IS NOT NULL AND JSON_EXTRACT(contenido, '$.items[19].logo') IS NULL;
