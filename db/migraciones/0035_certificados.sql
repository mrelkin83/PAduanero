-- =====================================================================
-- 0035 — Certificado de finalización (sub-proyecto 4)
--
-- Migración aditiva (ADR-013). Implementa
-- docs/superpowers/specs/2026-08-29-certificado-cursos-design.md.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS curso_progreso (
  id            CHAR(36)   NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)   NOT NULL,
  leccion_id    CHAR(36)   NOT NULL,
  visto_en      TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ix_progreso_unico (comprador_id, leccion_id),
  CONSTRAINT fk_progreso_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id) ON DELETE CASCADE,
  CONSTRAINT fk_progreso_leccion FOREIGN KEY (leccion_id) REFERENCES curso_lecciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS certificados (
  id                    CHAR(36)      NOT NULL DEFAULT (UUID()),
  compra_id             CHAR(36)      NOT NULL,
  codigo_verificacion   VARCHAR(20)   NOT NULL,
  emitido_en            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ix_certificados_compra (compra_id),
  UNIQUE KEY ix_certificados_codigo (codigo_verificacion),
  CONSTRAINT fk_certificados_compra FOREIGN KEY (compra_id) REFERENCES compras_curso(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
