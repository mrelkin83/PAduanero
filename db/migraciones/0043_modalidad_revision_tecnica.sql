-- =====================================================================
-- 0043 — La revisión técnica como modalidad, y un interruptor para el bot
--
-- Decisión del PO (2026-09-11): «por ahora deja el mismo precio de los
-- 400.000 de la revisión técnica, pero que se pueda configurar cada valor
-- en el panel administrativo».
--
-- El precio ya se configura desde `/panel/tarifas` para cualquier fila de
-- esta tabla (ADR-010: el precio se define ahí, nunca en código), así que
-- lo único que faltaba era la fila. Pero sembrarla activa, tal cual,
-- rompía dos cosas — y el bot está ENCENDIDO en producción
-- (`wa_config.activo = 1`), así que se habrían roto en vivo:
--
--  1. **El bot habría empezado a vender la revisión técnica.** Su catálogo
--     es literalmente `modalidades_asesoria WHERE activo = 1`
--     (`AdaptadorDespacho::buscarItems()`). Con la fila dentro, el modelo
--     la ofrece y la agenda — justo lo que prohíben las reglas de dominio:
--     la asesoría se agenda con Pedro, y nunca se ofrece una cita con la
--     consultora.
--
--  2. **La red del catálogo de un solo servicio.** `detalleItem()` resuelve
--     un id irreconocible al único servicio activo cuando hay uno solo. Esa
--     red existe por un fallo real del 2026-08-22: el modelo mutiló el UUID
--     al confirmar y la venta murió en «no está en el catálogo», tres
--     rechazos y traspaso a humano. Con dos filas activas la red deja de
--     aplicar y ese fallo vuelve a ser posible.
--
-- De ahí `ofrece_bot`: separa «la modalidad existe y se cobra» de «el bot
-- la ofrece». Por defecto 1, que es el comportamiento de siempre para la
-- fila que ya estaba; la revisión técnica entra con 0. El catálogo del bot
-- pasa a ser `activo = 1 AND ofrece_bot = 1`, así que vuelve a tener un solo
-- servicio y la red sigue en pie.
--
-- `activo = 1` y `requiere_pago = 1`: la modalidad existe, tiene precio y se
-- cobra. Lo que todavía NO existe es un camino público hacia ella — la rama
-- preventiva de `/perfil` sigue saliendo por `fuera_alcance`, porque eso
-- depende de dos decisiones que siguen abiertas (si Erika factura al
-- despacho o directamente, y si la revisión puede derivar en caso jurídico).
-- Ver CLAUDE.md §7, pendientes.
--
-- Aditiva (ADR-013): una columna nueva con default y una fila nueva.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `modalidades_asesoria`
  ADD COLUMN `ofrece_bot` TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Si el bot de WhatsApp puede ofrecerla y agendarla. La revisión técnica no: la asesoría se agenda con el abogado.'
  AFTER `activo`;

INSERT INTO modalidades_asesoria
  (nombre, descripcion, duracion_min, precio_cop, modalidad, requiere_pago, activo, ofrece_bot, orden)
SELECT 'Revisión técnica de operación',
       'Revisión documental y de clasificación de una operación de comercio exterior con la consultora aduanera del despacho: subpartida, valor en aduana, régimen aplicado y documentos soporte. No es asesoría jurídica.',
       60, 400000, 'virtual', 1, 1, 0, 2
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT nombre FROM modalidades_asesoria) AS m
     WHERE m.nombre = 'Revisión técnica de operación'
);
