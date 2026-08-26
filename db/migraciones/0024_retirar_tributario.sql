-- =====================================================================
-- 0024 — Retirar «tributario» del sitio y del bot
--
-- Aditiva y sin cambios de esquema (ADR-013): puro UPDATE sobre contenido
-- ya sembrado, nada de estructura nueva.
--
-- Decisión del PO (2026-08-25): el despacho pasa a ser 100% aduanero.
-- `Catalogo::TRIBUTARIO` y el catálogo §5 de CLAUDE.md ya se retiraron en
-- código; esto retira la palabra del contenido editable — landing,
-- `/perfil` (meta), páginas legales y el prompt del bot de WhatsApp
-- (`wa_agentes`, hoy apagado pero con el texto correcto para cuando se
-- encienda).
--
-- Cada UPDATE lleva su propio guard (LIKE contra el texto viejo, o
-- JSON_EXTRACT/JSON_UNQUOTE donde hace falta comparar un JSON): si el
-- contenido ya no menciona tributario —porque esta migración ya corrió, o
-- porque alguien lo editó a mano desde el panel— el UPDATE no toca nada.
-- =====================================================================

SET NAMES utf8mb4;

-- ── Landing: hero ───────────────────────────────────────────────────────
-- El reemplazo es de la frase completa y no de « y tributario» a secas:
-- quitar solo esas dos palabras deja una coma huérfana («aduanero, comercio
-- exterior.») donde debía quedar una «y» («aduanero y comercio exterior.»).
UPDATE landing_bloques
   SET subtitulo = REPLACE(
         subtitulo,
         'derecho aduanero, comercio exterior y tributario',
         'derecho aduanero y comercio exterior'
       ),
       contenido = JSON_SET(
         contenido, '$.alt',
         REPLACE(JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.alt')), 'derecho aduanero y tributario', 'derecho aduanero y comercio exterior')
       )
 WHERE clave = 'hero' AND (COALESCE(subtitulo, '') LIKE '%tributari%' OR contenido LIKE '%tributari%');

-- ── Landing: credenciales — quita el item de Derecho Tributario ────────
UPDATE landing_bloques
   SET contenido = JSON_SET(contenido, '$.items', JSON_ARRAY(
         JSON_OBJECT('titulo', 'Especialista en Derecho Aduanero y Comercio Exterior', 'detalle', ''),
         JSON_OBJECT('titulo', 'Más de 15 años de experiencia', 'detalle', 'Litigio y procedimiento administrativo ante la DIAN')
       ))
 WHERE clave = 'credenciales' AND contenido LIKE '%Tributario%';

-- ── Landing: casos — quita la columna tributaria entera ─────────────────
UPDATE landing_bloques
   SET contenido = JSON_REMOVE(contenido, '$.tributario')
 WHERE clave = 'casos' AND JSON_EXTRACT(contenido, '$.tributario') IS NOT NULL;

-- ── Landing: confianza — la sede de Teusaquillo ya no menciona tributario
UPDATE landing_bloques
   SET contenido = JSON_REPLACE(
         contenido, '$.sedes[1].detalle',
         REPLACE(JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.sedes[1].detalle')), 'casos tributarios', 'casos')
       )
 WHERE clave = 'confianza' AND contenido LIKE '%casos tributarios%';

-- ── Meta / SEO ───────────────────────────────────────────────────────────
UPDATE configuraciones
   SET valor = '"Abogado aduanero · Defensa ante la DIAN"'
 WHERE clave = 'landing_meta_titulo'
   AND JSON_UNQUOTE(valor) = 'Abogado aduanero y tributario · Defensa ante la DIAN';

UPDATE configuraciones
   SET valor = '"Defensa jurídica en aprehensión de mercancía, requerimientos y fiscalización de la DIAN. Más de 15 años en derecho aduanero y comercio exterior."'
 WHERE clave = 'landing_meta_descripcion'
   AND JSON_UNQUOTE(valor) = 'Defensa jurídica en aprehensión de mercancía, requerimientos y fiscalización de la DIAN. Más de 15 años en derecho aduanero y tributario.';

UPDATE configuraciones
   SET valor = '"Abogado aduanero: diagnostique su caso ante la DIAN"'
 WHERE clave = 'perfil_meta_titulo'
   AND JSON_UNQUOTE(valor) = 'Abogado aduanero y tributario: diagnostique su caso';

UPDATE configuraciones
   SET valor = '"Identifique su caso aduanero ante la DIAN en seis preguntas, gratis y sin dejar datos, con un abogado especialista en derecho aduanero y comercio exterior."'
 WHERE clave = 'perfil_meta_descripcion'
   AND JSON_UNQUOTE(valor) = 'Identifique su caso aduanero o tributario ante la DIAN en seis preguntas, gratis y sin dejar datos, con un abogado especialista en derecho aduanero.';

UPDATE configuraciones
   SET valor = '["aduanero"]'
 WHERE clave = 'areas_practica' AND valor LIKE '%tributario%';

-- ── El bot de WhatsApp (wa_agentes, apagado hoy) ────────────────────────
UPDATE wa_agentes
   SET rol = REPLACE(rol, ', y en derecho tributario', ''),
       instrucciones = REPLACE(
         instrucciones,
         ') o tributario (requerimiento especial, liquidación oficial, fiscalización de renta o IVA, sanción, devolución)?',
         ')?'
       ),
       saludo_inicial = REPLACE(saludo_inicial, 'abogado aduanero y tributario', 'abogado aduanero')
 WHERE id = 1 AND (rol LIKE '%tributari%' OR instrucciones LIKE '%tributari%' OR saludo_inicial LIKE '%tributari%');
