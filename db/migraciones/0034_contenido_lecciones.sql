-- =====================================================================
-- 0034 — Contenido protegido de las lecciones (sub-proyecto 3)
--
-- Migración aditiva (ADR-013). Implementa
-- docs/superpowers/specs/2026-08-28-contenido-protegido-cursos-design.md.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE curso_lecciones
  ADD COLUMN video_bunny_id   VARCHAR(64)  NULL AFTER vista_previa_gratis,
  ADD COLUMN contenido_texto  MEDIUMTEXT   NULL AFTER video_bunny_id;

CREATE TABLE IF NOT EXISTS curso_materiales (
  id              CHAR(36)      NOT NULL DEFAULT (UUID()),
  leccion_id      CHAR(36)      NOT NULL,
  nombre          VARCHAR(150)  NOT NULL,
  archivo         VARCHAR(80)   NOT NULL,
  extension       VARCHAR(10)   NOT NULL,
  tamanio_bytes   INT UNSIGNED  NOT NULL,
  orden           SMALLINT      NOT NULL DEFAULT 0,
  creado_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_materiales_leccion (leccion_id, orden),
  CONSTRAINT fk_materiales_leccion FOREIGN KEY (leccion_id) REFERENCES curso_lecciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
