-- =====================================================================
-- 0026 — Icono por teléfono en el pie
--
-- Aditiva y sin cambios de esquema (ADR-013): un UPDATE sobre la fila
-- `pie` de `landing_bloques`, con guard.
--
-- El pie gana iconos de Font Awesome (self-hosted, `Iconos::svg()`) para
-- dirección, teléfono y redes. Dirección y correo tienen un único icono
-- posible y no necesitan campo nuevo; las redes ya traen `nombre`
-- (LinkedIn, Instagram…) y de ahí se deduce el glifo, así que tampoco.
--
-- El teléfono es distinto: nada en el dato dice si un número es fijo o es
-- el WhatsApp del despacho, y con tres números (0023) puede haber de los
-- dos. `telefonos` pasa de lista de tres textos a lista de tres objetos
-- `{numero, icono}` — el panel ya sabe pintar objetos dentro de una lista,
-- así que el formulario no necesita tocarse aparte del selector de icono
-- (`contenido_editar.php`, que trata `icono` como caso especial).
--
-- El guard mira si el primer elemento de `telefonos` ya es un objeto: si
-- lo es, esta migración ya corrió (o el panel ya guardó en el formato
-- nuevo) y no hay nada que transformar.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE landing_bloques
   SET contenido = JSON_SET(
         contenido, '$.telefonos',
         JSON_ARRAY(
           JSON_OBJECT('numero', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.telefonos[0]')), ''), 'icono', 'telefono'),
           JSON_OBJECT('numero', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.telefonos[1]')), ''), 'icono', 'telefono'),
           JSON_OBJECT('numero', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.telefonos[2]')), ''), 'icono', 'telefono')
         )
       )
 WHERE clave = 'pie'
   AND JSON_TYPE(JSON_EXTRACT(contenido, '$.telefonos[0]')) = 'STRING';
