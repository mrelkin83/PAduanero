-- =====================================================================
-- 0001 — Esquema del Motor Conversacional
-- MySQL 8.0.16+ · InnoDB · utf8mb4_0900_ai_ci
--
-- Se aplica con `php bin/migrar.php`, que lleva el control en la tabla
-- `migraciones` y aborta si el contenido de un archivo ya aplicado cambió
-- (ADR-013). Editar este archivo después de aplicarlo NO lo reaplica: hay que
-- crear una migración nueva.
--
-- La versión mínima es 8.0.16, no 8.0.13: `DEFAULT (UUID())` existe desde
-- 8.0.13, pero MySQL ignoraba silenciosamente las restricciones CHECK hasta
-- 8.0.16, y `ck_horarios_dia` es una de ellas. En 8.0.13 el esquema se crea
-- sin error y la restricción sencillamente no protege nada.
--
-- NOTAS DE CONVERSIÓN DESDE POSTGRES (leer antes de tocar nada):
--  · UUID → CHAR(36) con DEFAULT (UUID()).
--  · JSONB → JSON. TIMESTAMPTZ → DATETIME en UTC (la app convierte a Bogotá).
--  · MySQL NO tiene índices únicos parciales. Se emulan con columnas
--    generadas STORED que valen NULL cuando la condición no aplica, ya que
--    MySQL permite múltiples NULL en un índice UNIQUE. Es la pieza que
--    impide la doble reserva: no la elimines.
--  · Código de violación de unicidad: MySQL 1062 (SQLSTATE 23000).
--    El motor debe capturar ese, no el 23505 de Postgres.
--  · pgvector no existe. Ver kb_chunks: embeddings en JSON y coseno
--    calculado en PHP. A escala de 130 escenarios es perfectamente viable.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- 1. CONTACTOS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contactos (
  id                  CHAR(36)     NOT NULL DEFAULT (UUID()),
  chatwoot_contact_id BIGINT       NULL,
  telefono            VARCHAR(20)  NOT NULL,
  telefono_hash       CHAR(64)     NOT NULL,
  nombre              VARCHAR(150) NULL,
  tipo_persona        ENUM('natural','juridica') NULL,
  razon_social        VARCHAR(200) NULL,
  nit_cifrado         VARBINARY(255) NULL,
  ciudad              VARCHAR(100) NULL,
  canal_origen        VARCHAR(30)  NOT NULL,
  utm_source          VARCHAR(100) NULL,
  utm_campaign        VARCHAR(100) NULL,
  bloqueado           TINYINT(1)   NOT NULL DEFAULT 0,
  creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_contactos_telefono (telefono),
  UNIQUE KEY ux_contactos_chatwoot (chatwoot_contact_id),
  KEY ix_contactos_hash (telefono_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 2. CONSENTIMIENTOS (Ley 1581 de 2012)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consentimientos (
  id               CHAR(36)    NOT NULL DEFAULT (UUID()),
  contacto_id      CHAR(36)    NOT NULL,
  version_politica VARCHAR(20) NOT NULL,
  texto_mostrado   TEXT        NOT NULL,
  otorgado         TINYINT(1)  NOT NULL,
  evidencia        JSON        NOT NULL,
  otorgado_en      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revocado_en      DATETIME    NULL,
  PRIMARY KEY (id),
  KEY ix_consent_contacto (contacto_id, revocado_en),
  CONSTRAINT fk_consent_contacto FOREIGN KEY (contacto_id)
    REFERENCES contactos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 3. CASOS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS casos (
  id                  CHAR(36)     NOT NULL DEFAULT (UUID()),
  contacto_id         CHAR(36)     NOT NULL,
  radicado_interno    VARCHAR(20)  NULL,
  area                ENUM('aduanero','tributario','mixto') NOT NULL DEFAULT 'aduanero',
  tipo_caso           VARCHAR(40)  NOT NULL,
  subtipo             VARCHAR(60)  NULL,
  entidad             VARCHAR(30)  NULL,
  seccional           VARCHAR(80)  NULL,
  urgencia            ENUM('critica','alta','media','baja') NOT NULL DEFAULT 'media',
  tiene_acto_admin    TINYINT(1)   NULL,
  fecha_acto          DATE         NULL,
  numero_acto         VARCHAR(80)  NULL,
  valor_estimado_cop  BIGINT       NULL,
  descripcion_cliente TEXT         NULL,
  resumen_motor       TEXT         NULL,
  puntaje_lead        SMALLINT     NOT NULL DEFAULT 0,
  estado              ENUM('nuevo','en_triage','calificado','propuesta_enviada',
                           'pendiente_pago','agendado','atendido','ganado',
                           'descartado','fuera_alcance') NOT NULL DEFAULT 'nuevo',
  motivo_descarte     VARCHAR(120) NULL,
  requiere_revision   TINYINT(1)   NOT NULL DEFAULT 0,
  creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_casos_radicado (radicado_interno),
  KEY ix_casos_estado (estado, urgencia, creado_en),
  KEY ix_casos_contacto (contacto_id),
  KEY ix_casos_area (area, tipo_caso),
  CONSTRAINT fk_casos_contacto FOREIGN KEY (contacto_id)
    REFERENCES contactos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS caso_partes (
  id        CHAR(36)     NOT NULL DEFAULT (UUID()),
  caso_id   CHAR(36)     NOT NULL,
  rol       ENUM('cliente','contraparte','tercero') NOT NULL,
  nombre    VARCHAR(200) NOT NULL,
  documento VARCHAR(40)  NULL,
  creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_partes_nombre (nombre),
  CONSTRAINT fk_partes_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 4. AGENDA
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS modalidades_asesoria (
  id            CHAR(36)    NOT NULL DEFAULT (UUID()),
  nombre        VARCHAR(80) NOT NULL,
  descripcion   TEXT        NULL,
  duracion_min  SMALLINT    NOT NULL,
  precio_cop    BIGINT      NOT NULL,
  modalidad     ENUM('virtual','presencial','telefonica') NOT NULL,
  requiere_pago TINYINT(1)  NOT NULL DEFAULT 1,
  activo        TINYINT(1)  NOT NULL DEFAULT 1,
  orden         SMALLINT    NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS horarios (
  id          CHAR(36)   NOT NULL DEFAULT (UUID()),
  dia_semana  TINYINT    NOT NULL,      -- 0=domingo … 6=sábado
  hora_inicio TIME       NOT NULL,
  hora_fin    TIME       NOT NULL,
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_horarios_dia (dia_semana, activo),
  CONSTRAINT ck_horarios_dia CHECK (dia_semana BETWEEN 0 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS bloqueos (
  id          CHAR(36)     NOT NULL DEFAULT (UUID()),
  fecha       DATE         NOT NULL,
  hora_inicio TIME         NOT NULL,
  hora_fin    TIME         NOT NULL,
  motivo      VARCHAR(120) NULL,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_bloqueos_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS consultas (
  id             CHAR(36) NOT NULL DEFAULT (UUID()),
  caso_id        CHAR(36) NOT NULL,
  contacto_id    CHAR(36) NOT NULL,
  modalidad_id   CHAR(36) NOT NULL,
  fecha          DATE     NOT NULL,
  hora_inicio    TIME     NOT NULL,
  hora_fin       TIME     NOT NULL,
  estado         ENUM('reservada','pagada','realizada','cancelada','no_asistio','expirada')
                 NOT NULL DEFAULT 'reservada',
  precio_cop     BIGINT   NOT NULL,   -- PESOS (ADR-010), congelado al reservar:
                                      -- si sube la tarifa, las reservas vivas
                                      -- conservan su precio
  reserva_expira DATETIME NULL,
  enlace_reunion TEXT     NULL,
  notas_internas TEXT     NULL,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Emulación del índice único parcial de Postgres.
  -- Vale NULL salvo cuando la consulta está viva; MySQL admite NULLs repetidos
  -- en UNIQUE.
  --
  -- SEGUNDA línea de defensa, no la única (ADR-015). Solo bloquea horas de
  -- inicio idénticas: 14:00-15:00 y 14:30-15:30 no lo violan. La primera línea
  -- es la validación de solapamiento con FOR UPDATE en ConsultaRepo::reservar().
  -- No se elimina.
  slot_unico CHAR(20) GENERATED ALWAYS AS (
    IF(estado IN ('reservada','pagada','realizada'),
       CONCAT(fecha, 'T', hora_inicio), NULL)
  ) STORED,

  PRIMARY KEY (id),
  UNIQUE KEY ux_consultas_slot (slot_unico),
  KEY ix_consultas_caso (caso_id),
  KEY ix_consultas_agenda (fecha, estado),
  KEY ix_consultas_expira (estado, reserva_expira),
  CONSTRAINT fk_consultas_caso      FOREIGN KEY (caso_id)      REFERENCES casos(id)     ON DELETE RESTRICT,
  CONSTRAINT fk_consultas_contacto  FOREIGN KEY (contacto_id)  REFERENCES contactos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_consultas_modalidad FOREIGN KEY (modalidad_id) REFERENCES modalidades_asesoria(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 5. PAGOS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pagos (
  id               CHAR(36)    NOT NULL DEFAULT (UUID()),
  consulta_id      CHAR(36)    NOT NULL,
  pasarela         VARCHAR(20) NOT NULL,
  referencia       VARCHAR(80) NOT NULL,
  monto_centavos   BIGINT      NOT NULL,      -- 400.000 COP = 40000000
  moneda           CHAR(3)     NOT NULL DEFAULT 'COP',
  estado           ENUM('creado','pendiente','aprobado','rechazado','expirado','reversado')
                   NOT NULL DEFAULT 'creado',
  url_checkout     TEXT        NULL,
  payload_webhook  JSON        NULL,
  firma_verificada TINYINT(1)  NOT NULL DEFAULT 0,
  creado_en        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmado_en    DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_pagos_referencia (referencia),
  KEY ix_pagos_estado (estado, creado_en),
  CONSTRAINT fk_pagos_consulta FOREIGN KEY (consulta_id) REFERENCES consultas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 6. ESTADO CONVERSACIONAL
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conversacion_estado (
  id                CHAR(36)    NOT NULL DEFAULT (UUID()),
  chatwoot_conv_id  BIGINT      NOT NULL,
  contacto_id       CHAR(36)    NULL,
  caso_id           CHAR(36)    NULL,
  prompt_version_id CHAR(36)    NULL,
  estado            VARCHAR(25) NOT NULL DEFAULT 'nuevo',
  ia_activa         TINYINT(1)  NOT NULL DEFAULT 1,
  pausada_hasta     DATETIME    NULL,
  historial         JSON        NOT NULL,
  resumen_largo     TEXT        NULL,
  buffer_mensajes   JSON        NOT NULL,
  buffer_hasta      DATETIME    NULL,
  turnos            INT         NOT NULL DEFAULT 0,
  tokens_consumidos INT         NOT NULL DEFAULT 0,
  ultimo_mensaje_en DATETIME    NULL,
  actualizado_en    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_conv_chatwoot (chatwoot_conv_id),
  KEY ix_conv_estado (estado, ia_activa),
  KEY ix_conv_caso (caso_id),
  CONSTRAINT fk_conv_contacto FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE SET NULL,
  CONSTRAINT fk_conv_caso     FOREIGN KEY (caso_id)     REFERENCES casos(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 7. BASE DE CONOCIMIENTO
--    Sin pgvector: el embedding va en JSON y la similitud coseno se calcula
--    en PHP. Con ~130 escenarios (del orden de 1.000–2.000 chunks) una pasada
--    completa toma milisegundos. Se guarda la norma precalculada para no
--    recalcularla en cada comparación. El índice FULLTEXT permite prefiltrar
--    léxicamente y reducir el conjunto antes del coseno.
--    Si esto creciera a decenas de miles de chunks, la salida es Qdrant en
--    Docker, no reescribir el motor.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kb_documentos (
  id             CHAR(36)     NOT NULL DEFAULT (UUID()),
  titulo         VARCHAR(250) NOT NULL,
  area           ENUM('aduanero','tributario','ambos') NOT NULL DEFAULT 'aduanero',
  tipo_fuente    VARCHAR(30)  NOT NULL,
  referencia     VARCHAR(200) NULL,
  url_oficial    TEXT         NULL,
  vigente        TINYINT(1)   NOT NULL DEFAULT 1,
  verificado_por VARCHAR(120) NULL,
  verificado_en  DATE         NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_kb_doc_vigente (vigente, area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS kb_chunks (
  id              CHAR(36)    NOT NULL DEFAULT (UUID()),
  documento_id    CHAR(36)    NOT NULL,
  orden           INT         NOT NULL,
  contenido       TEXT        NOT NULL,
  tipo_caso       VARCHAR(40) NULL,
  embedding       JSON        NULL,     -- array de 1536 floats
  embedding_norma DOUBLE      NULL,     -- precalculada para el coseno
  creado_en       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_kb_chunks_tipo (tipo_caso),
  KEY ix_kb_chunks_doc (documento_id),
  FULLTEXT KEY ft_kb_contenido (contenido),
  CONSTRAINT fk_chunks_doc FOREIGN KEY (documento_id) REFERENCES kb_documentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 8. OUTBOX
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eventos_outbox (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo          VARCHAR(50) NOT NULL,
  payload       JSON        NOT NULL,
  estado        ENUM('pendiente','procesando','enviado','fallido') NOT NULL DEFAULT 'pendiente',
  intentos      SMALLINT    NOT NULL DEFAULT 0,
  ultimo_error  TEXT        NULL,
  disponible_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  creado_en     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  procesado_en  DATETIME    NULL,
  PRIMARY KEY (id),
  KEY ix_outbox_pendientes (estado, disponible_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 9. SECUENCIAS  (radicado interno, ADR-014)
--    Formato PA-YYYY-NNNNNN, consecutivo por año reiniciando cada enero.
--    Se toma con SELECT ... FOR UPDATE dentro de la misma transacción que
--    inserta el caso. Nunca MAX(id)+1: con dos mensajes concurrentes entrega
--    el mismo radicado dos veces y el UNIQUE de casos.radicado_interno hace
--    fallar la creación del caso en plena conversación.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS secuencias (
  anio   SMALLINT UNSIGNED NOT NULL,
  ultimo INT UNSIGNED      NOT NULL DEFAULT 0,
  PRIMARY KEY (anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 10. AUDITORÍA
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auditoria (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entidad    VARCHAR(40) NOT NULL,
  entidad_id CHAR(36)    NULL,
  accion     VARCHAR(40) NOT NULL,
  actor      VARCHAR(60) NOT NULL,
  detalle    JSON        NULL,
  creado_en  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_auditoria_entidad (entidad, entidad_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
