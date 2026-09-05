-- =====================================================================
-- 0037 — Plantilla del certificado, configurable desde el panel
--
-- Hasta ahora el diseño del certificado vivía fijo en CertificadoPdf. El PO
-- (2026-09-05) quiere una plantilla editable: se sube una IMAGEN DE FONDO
-- (el diseño hecho en Canva o por un diseñador) y el motor estampa encima
-- los datos —nombre, curso, código, fecha, documento— en posiciones
-- configurables.
--
-- Una sola plantilla GLOBAL para todos los cursos (fila única id = 1). Si no
-- hay imagen de fondo cargada, CertificadoPdf cae a su diseño de siempre, así
-- que esto no rompe los certificados ya emitidos ni obliga a configurar nada
-- para que sigan saliendo.
--
-- `campos_json` guarda, por cada dato, su posición vertical (% desde arriba),
-- alineación, margen lateral (% para left/right), tamaño en pt, color y si se
-- muestra. Lo administra el panel; el motor solo lo lee.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `certificado_plantilla` (
  `id` tinyint unsigned NOT NULL DEFAULT 1,
  `imagen_fondo` varchar(255) DEFAULT NULL COMMENT 'Archivo en storage/cursos/certificados/. NULL = usar el diseño por defecto del código.',
  `campos_json` text DEFAULT NULL COMMENT 'Posición y estilo de cada dato estampado. JSON administrado por el panel.',
  `actualizado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `certificado_plantilla` (`id`, `imagen_fondo`, `campos_json`) VALUES (1, NULL, NULL);
