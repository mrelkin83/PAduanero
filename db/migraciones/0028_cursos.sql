-- =====================================================================
-- 0028 — CURSOS — catálogo público (sub-proyecto 1 de 3)
--
-- Migración aditiva (ADR-013). Implementa
-- docs/superpowers/specs/2026-08-26-catalogo-cursos-design.md: tablas del
-- catálogo y el temario (títulos y duración, sin contenido protegido
-- todavía — eso es el sub-proyecto 3), permisos nuevos y el interruptor de
-- menú. No siembra categorías ni cursos de ejemplo (ver memoria del
-- proyecto sobre datos de prueba en `confianza`): Pedro los crea desde el
-- panel cuando tenga cursos reales.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categorias_curso (
  id         CHAR(36)     NOT NULL DEFAULT (UUID()),
  nombre     VARCHAR(80)  NOT NULL,
  slug       VARCHAR(80)  NOT NULL,
  orden      SMALLINT     NOT NULL DEFAULT 0,
  activa     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY ux_categorias_curso_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cursos (
  id                CHAR(36)     NOT NULL DEFAULT (UUID()),
  categoria_id      CHAR(36)     NOT NULL,
  titulo            VARCHAR(150) NOT NULL,
  slug              VARCHAR(150) NOT NULL,
  resumen           VARCHAR(300) NOT NULL,
  descripcion       TEXT         NOT NULL,
  lo_que_aprendera  JSON         NOT NULL,
  nivel             ENUM('basico','intermedio','avanzado') NOT NULL DEFAULT 'basico',
  precio_cop        BIGINT       NOT NULL,
  imagen_portada    VARCHAR(255) NULL,
  estado            ENUM('borrador','publicado') NOT NULL DEFAULT 'borrador',
  orden             SMALLINT     NOT NULL DEFAULT 0,
  creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  publicado_en      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_cursos_slug (slug),
  KEY ix_cursos_estado (estado, orden),
  CONSTRAINT fk_cursos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_curso(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS curso_modulos (
  id        CHAR(36)     NOT NULL DEFAULT (UUID()),
  curso_id  CHAR(36)     NOT NULL,
  titulo    VARCHAR(150) NOT NULL,
  orden     SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_modulos_curso (curso_id, orden),
  CONSTRAINT fk_modulos_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS curso_lecciones (
  id                  CHAR(36)     NOT NULL DEFAULT (UUID()),
  modulo_id           CHAR(36)     NOT NULL,
  titulo              VARCHAR(150) NOT NULL,
  duracion_min        SMALLINT     NULL,
  orden               SMALLINT     NOT NULL DEFAULT 0,
  vista_previa_gratis TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_lecciones_modulo (modulo_id, orden),
  CONSTRAINT fk_lecciones_modulo FOREIGN KEY (modulo_id) REFERENCES curso_modulos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- PERMISOS
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, modulo) VALUES
 ('cursos.ver','cursos'),
 ('cursos.editar','cursos');

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
WHERE (r.clave = 'super_admin' AND p.clave IN ('cursos.ver','cursos.editar'))
   OR (r.clave = 'abogado'     AND p.clave IN ('cursos.ver','cursos.editar'));

-- ---------------------------------------------------------------------
-- INTERRUPTOR DE MENÚ
--
-- Apagado por defecto: se enciende desde /panel/configuracion cuando haya
-- cursos publicados que mostrar. No apaga las rutas públicas (spec §5),
-- solo el enlace del menú.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO configuraciones
  (clave, valor, tipo, grupo, etiqueta, ayuda, rol_minimo, minimo, maximo, opciones) VALUES
 ('cursos_activo','false','booleano','cursos','Mostrar "Cursos" en el menú',
  'Activa el enlace en la cabecera de la landing. Las páginas /cursos siguen respondiendo aunque esté apagado.',
  'abogado', NULL, NULL, NULL);
