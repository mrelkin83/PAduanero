-- =====================================================================
-- 0019 — El pie de página entra al panel
--
-- Aditiva y sin cambios de esquema (ADR-013): una fila nueva en
-- `landing_bloques` y nada más.
--
-- El pie era texto fijo en plantilla y no tenía dónde ponerse el correo,
-- el teléfono ni las redes del despacho: cambiar cualquiera exigía tocar
-- código y desplegar. Con esta fila, `pie.php` lee sus datos del bloque
-- y el panel de contenido lo edita solo — el formulario se genera de la
-- estructura del JSON, como con todos los demás bloques.
--
-- Qué lleva y qué NO lleva:
--
--   · `correo` nace con el buzón real del dominio (info@, en Hostinger),
--     que ya existe y ya se usa para los documentos previos de las citas.
--   · `telefono` nace vacío. El WhatsApp del negocio NO va aquí: vive en
--     `configuraciones` (`whatsapp_numero_negocio`) y pintarlo también en
--     el pie crearía una segunda verdad. Este campo es para un fijo o un
--     conmutador, si Pedro quiere publicarlo.
--   · Las direcciones tampoco van aquí: viven en `confianza.sedes` (0014).
--   · `redes` nace con los nombres usuales y las URL VACÍAS, y la
--     plantilla omite en silencio toda red sin URL (la regla de 0014:
--     mejor una columna corta que una lista de huecos). La lista no nace
--     vacía porque el panel clona la forma del primer elemento para
--     añadir más — una lista vacía no se puede extender.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO landing_bloques (clave, titulo, subtitulo, contenido, orden) VALUES
('pie',
 'Pie de página',
 NULL,
 JSON_OBJECT(
   'correo', 'info@pedroabogadoaduanero.com',
   'telefono', '',
   'redes', JSON_ARRAY(
     JSON_OBJECT('nombre','LinkedIn',  'url',''),
     JSON_OBJECT('nombre','Instagram', 'url',''),
     JSON_OBJECT('nombre','Facebook',  'url',''),
     JSON_OBJECT('nombre','X',         'url',''),
     JSON_OBJECT('nombre','TikTok',    'url',''),
     JSON_OBJECT('nombre','YouTube',   'url','')
   )
 ), 12);
