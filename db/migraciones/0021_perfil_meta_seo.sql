-- =====================================================================
-- 0021 — Palabras clave en el meta del diagnóstico
--
-- Aditiva y sin cambios de esquema (ADR-013): dos UPDATE sobre filas de
-- `configuraciones` que ya existen (0012), y nada más.
--
-- La auditoría SEO del 2026-08-25 encontró que `/perfil` no menciona ni
-- «abogado aduanero» ni «derecho aduanero» en su título ni su descripción
-- — el título vendía la mecánica («dos minutos») y la descripción se
-- quedaba corta (103 de los 150-160 caracteres recomendados). La landing
-- ya cubre esas búsquedas bien; `/perfil` es la otra URL indexable del
-- sitio y no debía quedarse atrás.
--
-- El WHERE compara contra el valor sembrado por la 0012: si Pedro ya lo
-- cambió desde el panel, esta migración no lo pisa.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE configuraciones
   SET valor = '"Abogado aduanero y tributario: diagnostique su caso"'
 WHERE clave = 'perfil_meta_titulo'
   AND valor = '"Diagnostique su caso ante la DIAN en dos minutos"';

UPDATE configuraciones
   SET valor = '"Identifique su caso aduanero o tributario ante la DIAN en seis preguntas, gratis y sin dejar datos, con un abogado especialista en derecho aduanero."'
 WHERE clave = 'perfil_meta_descripcion'
   AND valor = '"Seis preguntas para saber qué le está pasando con la DIAN y cómo se llama. Sin dejar datos y sin costo."';
