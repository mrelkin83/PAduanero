-- =====================================================================
-- PANEL ADMINISTRATIVO — MySQL 8.0.13+
-- Ejecutar DESPUÉS de db/schema.sql (misma base).
--
-- El panel NO reimplementa la bandeja de conversaciones: eso es Chatwoot.
-- Aquí vive lo que Chatwoot no sabe.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. USUARIOS Y ROLES
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave       VARCHAR(30) NOT NULL,
  nombre      VARCHAR(60) NOT NULL,
  descripcion TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_roles_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE permisos (
  id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave  VARCHAR(60) NOT NULL,     -- 'config.credenciales.escribir'
  modulo VARCHAR(30) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_permisos_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE roles_permisos (
  rol_id     SMALLINT UNSIGNED NOT NULL,
  permiso_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  CONSTRAINT fk_rp_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE usuarios (
  id                  CHAR(36)     NOT NULL DEFAULT (UUID()),
  email               VARCHAR(180) NOT NULL,
  nombre              VARCHAR(150) NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,   -- password_hash(..., PASSWORD_ARGON2ID)
  rol_id              SMALLINT UNSIGNED NOT NULL,
  chatwoot_agent_id   BIGINT       NULL,
  telefono_alertas    VARCHAR(20)  NULL,
  totp_secret_cifrado VARBINARY(255) NULL,
  totp_activo         TINYINT(1)   NOT NULL DEFAULT 0,
  activo              TINYINT(1)   NOT NULL DEFAULT 1,
  ultimo_acceso_en    DATETIME     NULL,
  intentos_fallidos   SMALLINT     NOT NULL DEFAULT 0,
  bloqueado_hasta     DATETIME     NULL,
  creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_usuarios_email (email),
  CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sesiones (
  id          CHAR(36)   NOT NULL DEFAULT (UUID()),
  usuario_id  CHAR(36)   NOT NULL,
  token_hash  CHAR(64)   NOT NULL,      -- SHA-256 del token; nunca el token
  ip          VARCHAR(45) NULL,
  user_agent  TEXT       NULL,
  expira_en   DATETIME   NOT NULL,
  revocada_en DATETIME   NULL,
  creado_en   DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_sesiones_token (token_hash),
  KEY ix_sesiones_usuario (usuario_id, expira_en),
  CONSTRAINT fk_sesiones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 2. CONFIGURACIÓN TIPADA
-- ---------------------------------------------------------------------
CREATE TABLE configuraciones (
  clave             VARCHAR(80)  NOT NULL,
  valor             JSON         NOT NULL,
  tipo              ENUM('texto','entero','decimal','booleano','json','fecha','lista') NOT NULL,
  grupo             VARCHAR(40)  NOT NULL,
  etiqueta          VARCHAR(150) NOT NULL,
  ayuda             TEXT         NULL,
  minimo            DECIMAL(18,4) NULL,
  maximo            DECIMAL(18,4) NULL,
  opciones          JSON         NULL,
  rol_minimo        VARCHAR(30)  NOT NULL DEFAULT 'abogado',
  requiere_reinicio TINYINT(1)   NOT NULL DEFAULT 0,
  editable_ui       TINYINT(1)   NOT NULL DEFAULT 1,
  actualizado_por   CHAR(36)     NULL,
  actualizado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave),
  KEY ix_config_grupo (grupo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE configuraciones_historial (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave          VARCHAR(80) NOT NULL,
  valor_anterior JSON        NULL,
  valor_nuevo    JSON        NOT NULL,
  usuario_id     CHAR(36)    NULL,
  motivo         TEXT        NULL,
  creado_en      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_config_hist (clave, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 3. CREDENCIALES
--    AES-256-GCM con clave maestra en variable de entorno. NUNCA en base.
--    La UI es de solo escritura: al leer devuelve mascara, jamás el valor.
-- ---------------------------------------------------------------------
CREATE TABLE credenciales (
  id               CHAR(36)    NOT NULL DEFAULT (UUID()),
  servicio         VARCHAR(40) NOT NULL,
  entorno          ENUM('produccion','pruebas') NOT NULL DEFAULT 'produccion',
  clave            VARCHAR(60) NOT NULL,
  valor_cifrado    VARBINARY(2048) NOT NULL,
  nonce            VARBINARY(16)   NOT NULL,
  tag              VARBINARY(16)   NOT NULL,
  mascara          VARCHAR(40) NOT NULL,
  key_version      SMALLINT    NOT NULL DEFAULT 1,
  activo           TINYINT(1)  NOT NULL DEFAULT 1,
  expira_en        DATE        NULL,
  ultima_prueba_en DATETIME    NULL,
  ultima_prueba_ok TINYINT(1)  NULL,
  actualizado_por  CHAR(36)    NULL,
  actualizado_en   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_cred (servicio, entorno, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 4. PROVEEDORES Y MODELOS DE IA
-- ---------------------------------------------------------------------
CREATE TABLE proveedores_ia (
  id            CHAR(36)    NOT NULL DEFAULT (UUID()),
  clave         VARCHAR(30) NOT NULL,
  nombre        VARCHAR(80) NOT NULL,
  base_url      TEXT        NOT NULL,
  formato_api   ENUM('openai_compatible','anthropic','ollama') NOT NULL,
  pais_servidor VARCHAR(60) NULL,     -- dato de cumplimiento, no adorno
  activo        TINYINT(1)  NOT NULL DEFAULT 1,
  creado_en     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_prov_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE modelos_ia (
  id                   CHAR(36)     NOT NULL DEFAULT (UUID()),
  proveedor_id         CHAR(36)     NOT NULL,
  identificador        VARCHAR(120) NOT NULL,
  nombre_visible       VARCHAR(120) NOT NULL,
  proposito            ENUM('conversacion','embeddings','clasificacion','resumen') NOT NULL,
  ventana_contexto     INT          NULL,
  costo_entrada_usd_1m DECIMAL(10,4) NULL,
  costo_salida_usd_1m  DECIMAL(10,4) NULL,
  temperatura_default  DECIMAL(3,2) NULL DEFAULT 0.40,
  max_tokens_default   INT          NULL DEFAULT 600,
  es_primario          TINYINT(1)   NOT NULL DEFAULT 0,
  orden_fallback       SMALLINT     NOT NULL DEFAULT 99,
  activo               TINYINT(1)   NOT NULL DEFAULT 1,

  -- Un solo primario por propósito (índice único parcial emulado).
  primario_key VARCHAR(20) GENERATED ALWAYS AS (
    IF(es_primario = 1, proposito, NULL)
  ) STORED,

  PRIMARY KEY (id),
  UNIQUE KEY ux_modelo (proveedor_id, identificador, proposito),
  UNIQUE KEY ux_modelo_primario (primario_key),
  CONSTRAINT fk_modelo_prov FOREIGN KEY (proveedor_id) REFERENCES proveedores_ia(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE consumo_ia (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  modelo_id      CHAR(36)     NULL,
  caso_id        CHAR(36)     NULL,
  tokens_entrada INT          NOT NULL DEFAULT 0,
  tokens_salida  INT          NOT NULL DEFAULT 0,
  costo_usd      DECIMAL(12,6) NOT NULL DEFAULT 0,
  latencia_ms    INT          NULL,
  exito          TINYINT(1)   NOT NULL DEFAULT 1,
  error          TEXT         NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_consumo_fecha (creado_en),
  KEY ix_consumo_modelo (modelo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 5. PROMPTS VERSIONADOS
-- ---------------------------------------------------------------------
CREATE TABLE prompts (
  id           CHAR(36)    NOT NULL DEFAULT (UUID()),
  clave        VARCHAR(50) NOT NULL,
  version      INT         NOT NULL,
  contenido    MEDIUMTEXT  NOT NULL,
  notas_cambio TEXT        NULL,
  activo       TINYINT(1)  NOT NULL DEFAULT 0,
  aprobado_por CHAR(36)    NULL,      -- el abogado aprueba, no el desarrollador
  aprobado_en  DATETIME    NULL,
  creado_por   CHAR(36)    NULL,
  creado_en    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  activo_key VARCHAR(50) GENERATED ALWAYS AS (IF(activo = 1, clave, NULL)) STORED,

  PRIMARY KEY (id),
  UNIQUE KEY ux_prompt_version (clave, version),
  UNIQUE KEY ux_prompt_activo (activo_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 6. CONTENIDO DE LA LANDING
-- ---------------------------------------------------------------------
CREATE TABLE landing_bloques (
  id              CHAR(36)     NOT NULL DEFAULT (UUID()),
  clave           VARCHAR(50)  NOT NULL,
  titulo          VARCHAR(250) NULL,
  subtitulo       TEXT         NULL,
  contenido       JSON         NOT NULL,
  orden           SMALLINT     NOT NULL DEFAULT 0,
  visible         TINYINT(1)   NOT NULL DEFAULT 1,
  actualizado_por CHAR(36)     NULL,
  actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_bloques_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE articulos (
  id                   CHAR(36)     NOT NULL DEFAULT (UUID()),
  slug                 VARCHAR(180) NOT NULL,
  titulo               VARCHAR(250) NOT NULL,
  meta_titulo          VARCHAR(70)  NULL,
  meta_descripcion     VARCHAR(160) NULL,
  resumen              TEXT         NULL,
  contenido_md         MEDIUMTEXT   NOT NULL,
  area                 ENUM('aduanero','tributario','ambos') NOT NULL DEFAULT 'aduanero',
  tipo_caso            VARCHAR(40)  NULL,
  cta_texto            VARCHAR(120) NULL,
  publicado            TINYINT(1)   NOT NULL DEFAULT 0,
  revisado_por_abogado TINYINT(1)   NOT NULL DEFAULT 0,   -- gate obligatorio
  publicado_en         DATETIME     NULL,
  vistas               INT          NOT NULL DEFAULT 0,
  conversiones         INT          NOT NULL DEFAULT 0,
  actualizado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_articulos_slug (slug),
  KEY ix_articulos_pub (publicado, publicado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 7. MÉTRICAS DE ADQUISICIÓN
-- ---------------------------------------------------------------------
CREATE TABLE eventos_landing (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sesion_hash  CHAR(64)     NOT NULL,   -- sin cookies identificables
  tipo         VARCHAR(30)  NOT NULL,   -- vista|scroll_50|click_whatsapp|envio_form
  ruta         VARCHAR(250) NULL,
  utm_source   VARCHAR(100) NULL,
  utm_medium   VARCHAR(100) NULL,
  utm_campaign VARCHAR(100) NULL,
  utm_content  VARCHAR(100) NULL,
  dispositivo  VARCHAR(20)  NULL,
  creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_eventos_landing (creado_en, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
