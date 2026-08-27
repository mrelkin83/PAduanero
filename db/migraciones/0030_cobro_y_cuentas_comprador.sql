-- =====================================================================
-- 0030 — Cobro de cursos con Wompi y cuenta de comprador (sub-proyectos 2a+2b)
--
-- Migración aditiva (ADR-013). Implementa
-- docs/superpowers/specs/2026-08-26-cobro-y-cuenta-comprador-design.md.
-- Orden de creación importa por las llaves foráneas: compradores antes de
-- compras_curso (que la referencia), compras_curso antes de
-- compradores_enlaces (que referencia ambas).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS compradores (
  id                        CHAR(36)     NOT NULL DEFAULT (UUID()),
  nombres                   VARCHAR(150) NOT NULL,
  apellidos                 VARCHAR(150) NOT NULL,
  tipo_documento            ENUM('CC','CE','PASAPORTE','NIT') NOT NULL,
  numero_documento_cifrado  VARBINARY(255) NOT NULL,
  celular                   VARCHAR(20)  NOT NULL,
  correo                    VARCHAR(180) NOT NULL,
  password_hash             VARCHAR(255) NOT NULL,
  creado_en                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_compradores_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS compras_curso (
  id                CHAR(36)     NOT NULL DEFAULT (UUID()),
  curso_id          CHAR(36)     NOT NULL,
  comprador_id      CHAR(36)     NULL,
  nombre            VARCHAR(150) NOT NULL,
  correo            VARCHAR(180) NOT NULL,
  precio_cop        BIGINT       NOT NULL,
  referencia_wompi  VARCHAR(120) NULL,
  externo_id        VARCHAR(120) NULL,
  estado            ENUM('pendiente','pagada','fallida') NOT NULL DEFAULT 'pendiente',
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagado_en         DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_compras_curso (curso_id, estado),
  KEY ix_compras_referencia (referencia_wompi),
  CONSTRAINT fk_compras_curso FOREIGN KEY (curso_id) REFERENCES cursos(id),
  CONSTRAINT fk_compras_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS compradores_sesiones (
  id            CHAR(36)     NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)     NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  ip            VARCHAR(45)  NULL,
  user_agent    VARCHAR(500) NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME     NOT NULL,
  revocada_en   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_sesionescomp_token (token_hash),
  CONSTRAINT fk_sesionescomp_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS compradores_enlaces (
  id            CHAR(36)     NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)     NULL,
  compra_id     CHAR(36)     NULL,
  tipo          ENUM('completar_registro','reset_password') NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME     NOT NULL,
  usado         TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY ux_enlaces_token (token_hash),
  CONSTRAINT fk_enlaces_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id) ON DELETE CASCADE,
  CONSTRAINT fk_enlaces_compra FOREIGN KEY (compra_id) REFERENCES compras_curso(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
