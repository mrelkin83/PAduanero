-- =====================================================================
-- 0041 — Erika Duarte Ruiz entra como consultora del despacho
--
-- Aditiva y sin cambios de esquema (ADR-013): añade un bloque de landing,
-- amplía la lista del índice de situaciones y no toca ninguna tabla.
--
-- TRES CORRECCIONES SOBRE EL BORRADOR QUE LLEGÓ EN `Erika/`:
--
--  1. Número. El borrador venía como `0010_equipo_erika.sql` y el 0010 ya
--     existe (`0010_sin_gate_dorado.sql`, aplicado). El Migrador ordena por
--     nombre de archivo, así que la habría metido ANTES de una migración ya
--     aplicada. Va como 0041, que es el siguiente libre.
--
--  2. `tributario` no vuelve. El borrador reescribía el bloque `casos`
--     entero e incluía de nuevo la columna tributaria que la 0024 retiró
--     por decisión del PO del 2026-08-25 (despacho 100% aduanero — CLAUDE.md
--     §5 y §7). Aquí solo se AÑADEN filas aduaneras a la lista que ya hay.
--
--  3. Nada de una clave `operativo` nueva. El borrador metía las disputas
--     de régimen y documentos en `$.operativo`, y `bloques/casos.php` solo
--     conoce `$.aduanero`: esas cinco filas no se habrían pintado y el
--     bucle no da ningún error (CLAUDE.md §8, trampa conocida). Entran a
--     `$.aduanero`, que es además donde les corresponde — son disputas
--     aduaneras, no una categoría aparte.
--
-- Lo que NO se le nombra a Erika, aquí ni en ningún otro sitio: abogada.
-- Su brochure la titula «Consultora Aduanera y de Comercio Exterior»; es
-- Profesional en Negocios Internacionales con una especialización en
-- Derecho Aduanero, que es un posgrado abierto a no abogados y no habilita
-- el ejercicio del derecho. Presentarla como abogada expone al despacho.
-- La prohibición para el bot vive en `AdaptadorDespacho::reglasDeDominio()`
-- —capa no editable del prompt— y la defiende `ReglasDelBotTest`.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Índice de situaciones: cinco disputas que faltaban
--
-- Una por sentencia y cada una con su guarda, para que quede idempotente
-- y para no pisar lo que Pedro haya editado desde el panel: si la fila ya
-- está —porque esto ya corrió, o porque la escribió él— el UPDATE no toca
-- nada. Un reemplazo del array completo sí le habría borrado sus cambios.
-- ---------------------------------------------------------------------
UPDATE landing_bloques
   SET contenido = JSON_ARRAY_APPEND(contenido, '$.aduanero', 'Importación temporal incumplida')
 WHERE clave = 'casos'
   AND JSON_EXTRACT(contenido, '$.aduanero') IS NOT NULL
   AND JSON_SEARCH(contenido, 'one', 'Importación temporal incumplida', NULL, '$.aduanero') IS NULL;

UPDATE landing_bloques
   SET contenido = JSON_ARRAY_APPEND(contenido, '$.aduanero', 'Problemas con la reimportación')
 WHERE clave = 'casos'
   AND JSON_EXTRACT(contenido, '$.aduanero') IS NOT NULL
   AND JSON_SEARCH(contenido, 'one', 'Problemas con la reimportación', NULL, '$.aduanero') IS NULL;

UPDATE landing_bloques
   SET contenido = JSON_ARRAY_APPEND(contenido, '$.aduanero', 'Inconsistencias en documentos soporte')
 WHERE clave = 'casos'
   AND JSON_EXTRACT(contenido, '$.aduanero') IS NOT NULL
   AND JSON_SEARCH(contenido, 'one', 'Inconsistencias en documentos soporte', NULL, '$.aduanero') IS NULL;

UPDATE landing_bloques
   SET contenido = JSON_ARRAY_APPEND(contenido, '$.aduanero', 'Requisitos de ICA o INVIMA no acreditados')
 WHERE clave = 'casos'
   AND JSON_EXTRACT(contenido, '$.aduanero') IS NOT NULL
   AND JSON_SEARCH(contenido, 'one', 'Requisitos de ICA o INVIMA no acreditados', NULL, '$.aduanero') IS NULL;

UPDATE landing_bloques
   SET contenido = JSON_ARRAY_APPEND(contenido, '$.aduanero', 'Diferencias entre factura, BL y declaración')
 WHERE clave = 'casos'
   AND JSON_EXTRACT(contenido, '$.aduanero') IS NOT NULL
   AND JSON_SEARCH(contenido, 'one', 'Diferencias entre factura, BL y declaración', NULL, '$.aduanero') IS NULL;

-- ---------------------------------------------------------------------
-- 2. Bloque nuevo: `equipo`
--
-- `orden` solo gobierna la lista de edición del panel; el orden real de la
-- página lo manda la lista de `plantillas/landing/pagina.php`, donde
-- `equipo` va justo detrás de `credenciales`. El corrimiento se condiciona
-- a que el bloque no exista todavía: sin esa guarda, correr esto dos veces
-- desplazaría dos veces.
-- ---------------------------------------------------------------------
SET @hay_equipo := (SELECT COUNT(*) FROM landing_bloques WHERE clave = 'equipo');

UPDATE landing_bloques
   SET orden = orden + 1
 WHERE @hay_equipo = 0
   AND orden >= 3;

INSERT IGNORE INTO landing_bloques (clave, titulo, subtitulo, contenido, orden, visible)
VALUES (
  'equipo',
  'Un abogado y una consultora técnica',
  'Un caso aduanero se defiende con argumento jurídico y con dominio del expediente. No son lo mismo, y no los hace la misma persona.',
  JSON_OBJECT(
    'integrantes', JSON_ARRAY(
      JSON_OBJECT(
        'nombre',  'Erika Duarte Ruiz',
        'rol',     'Consultora Aduanera y de Comercio Exterior',
        'imagen',  '/img/erika-consultora.jpg',
        'alt',     'Erika Duarte Ruiz, consultora aduanera y de comercio exterior del despacho',
        'titulos', JSON_ARRAY(
          'Profesional en Negocios Internacionales',
          'Especialista en Derecho Aduanero y Comercio Exterior',
          'Ocho años en operaciones de comercio exterior'
        ),
        'resumen', 'Revisa la parte del expediente donde se decide el caso: clasificación arancelaria, valor en aduana, régimen aplicado y documentos soporte. Su trabajo alimenta la defensa que presenta el abogado.',
        'areas',   JSON_ARRAY(
          'Clasificación arancelaria y arancel de aduanas',
          'Valoración aduanera y valor en aduana',
          'Regímenes de importación y exportación',
          'Control documental: BL, factura, packing list, certificados',
          'Requisitos ante DIAN, ICA e INVIMA'
        )
      )
    ),
    'nota', 'El análisis jurídico, la estrategia y la actuación ante la DIAN los hace el abogado. La asesoría se agenda con él.'
  ),
  3,
  1
);
