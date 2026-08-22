-- =====================================================================
-- 0016 — El motor de WhatsApp vuelve, esta vez para agendar citas
--
-- El PO decidió (2026-08-22) conectar el motor conversacional extraído de
-- ControlBarMax (`packages/whatsapp-engine`, vendorizado en este repo) para
-- que el bot atienda el WhatsApp del negocio, oriente con el vocabulario
-- técnico del despacho y AGENDE la asesoría contra el Google Calendar del
-- abogado, cobrando antes de confirmar cuando así esté configurado.
--
-- IMPORTANTE — estas tablas son NUEVAS (prefijo wa_). Las tablas huérfanas
-- del motor de 2026-08 (`casos`, `consultas`, `contactos`…) siguen sin
-- usarse, como manda el CLAUDE.md §0.1: si hace falta guardar algo, tabla
-- nueva. Aditiva, como todas (ADR-013).
--
-- Con el motor vuelve el TRATAMIENTO DE DATOS PERSONALES (teléfono, nombre,
-- correo, descripción del caso). La política de tratamiento de datos deja de
-- ser un pendiente opcional: es requisito para encender `wa_config.activo`.
-- Por eso la semilla deja el motor APAGADO.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Configuración del motor: una sola fila (id = 1)
--    El esquema es el del paquete; columnas que aquí no aplican (domicilio,
--    pedido mínimo, stock) se quedan en su valor por defecto y el adaptador
--    jamás las consulta.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wa_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `activo` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Interruptor maestro del motor',
  `canal_tipo` varchar(20) NOT NULL DEFAULT 'evolution',
  `evolution_url` varchar(255) DEFAULT NULL,
  `evolution_instancia` varchar(100) DEFAULT NULL,
  `evolution_apikey` text DEFAULT NULL COMMENT 'Cifrada (ADR-011)',
  `webhook_token_hash` char(64) DEFAULT NULL,
  `numero_whatsapp` varchar(25) DEFAULT NULL,
  `estado_conexion` enum('desconectado','qr','conectado','error') NOT NULL DEFAULT 'desconectado',
  `ultima_conexion` datetime DEFAULT NULL,
  `llm_proveedor` varchar(40) DEFAULT NULL,
  `llm_modelo` varchar(120) DEFAULT NULL,
  `llm_api_key` text DEFAULT NULL COMMENT 'Cifrada',
  `llm_fallback_proveedor` varchar(40) DEFAULT NULL,
  `llm_fallback_modelo` varchar(120) DEFAULT NULL,
  `llm_fallback_api_key` text DEFAULT NULL COMMENT 'Cifrada',
  `llm_max_tokens` int NOT NULL DEFAULT 2048,
  `llm_temperatura` decimal(3,2) DEFAULT NULL,
  `stt_proveedor` varchar(40) DEFAULT NULL,
  `stt_api_key` text DEFAULT NULL COMMENT 'Cifrada',
  `stt_modelo` varchar(120) DEFAULT NULL,
  `stt_url` varchar(255) DEFAULT NULL,
  `stock_sin_registro` varchar(10) NOT NULL DEFAULT 'no_vender',
  `tts_proveedor` varchar(40) DEFAULT NULL,
  `tts_api_key` text DEFAULT NULL COMMENT 'Cifrada',
  `tts_voice_id` varchar(120) DEFAULT NULL,
  `tts_modelo` varchar(120) DEFAULT NULL,
  `tts_modo` enum('nunca','siempre','espejo','texto_y_audio') NOT NULL DEFAULT 'espejo',
  `tts_url` varchar(255) DEFAULT NULL,
  `vision_proveedor` varchar(40) DEFAULT NULL,
  `vision_api_key` text DEFAULT NULL COMMENT 'Cifrada',
  `vision_modelo` varchar(120) DEFAULT NULL,
  `vision_url` varchar(255) DEFAULT NULL,
  `pago_modo` enum('contra_entrega','wompi','manual','mixto','todos') NOT NULL DEFAULT 'mixto'
    COMMENT 'mixto = la cita se confirma con pago verificado (enlace o transferencia). contra_entrega = agenda sin cobrar. Es el interruptor «exigir pago» del panel',
  `wompi_ambiente` enum('sandbox','produccion') NOT NULL DEFAULT 'sandbox',
  `wompi_public_key` varchar(120) DEFAULT NULL,
  `wompi_private_key` text DEFAULT NULL COMMENT 'Cifrada',
  `wompi_events_secret` text DEFAULT NULL COMMENT 'Cifrada',
  `wompi_integrity_secret` text DEFAULT NULL COMMENT 'Cifrada',
  `pago_datos_transferencia` varchar(400) DEFAULT NULL,
  `pago_expira_minutos` int NOT NULL DEFAULT 30,
  `entrega_modos` varchar(60) NOT NULL DEFAULT 'domicilio,recoger',
  `costo_domicilio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pedido_minimo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `horario_atencion` text DEFAULT NULL COMMENT 'JSON por día; el motor lo consulta, no lo razona',
  `handoff_numero` varchar(25) DEFAULT NULL,
  `retencion_media_dias` int NOT NULL DEFAULT 7,
  `limite_mensajes` int NOT NULL DEFAULT 30,
  `limite_ventana_minutos` int NOT NULL DEFAULT 5,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Semilla: motor APAGADO, cobro exigido (mixto), horario de oficina y el
-- número de guardia del despacho para el traspaso a humano.
INSERT IGNORE INTO `wa_config`
  (`id`, `activo`, `pago_modo`, `handoff_numero`, `horario_atencion`)
VALUES
  (1, 0, 'mixto', '573159923676',
   '{"1":{"desde":"08:00","hasta":"18:00"},"2":{"desde":"08:00","hasta":"18:00"},"3":{"desde":"08:00","hasta":"18:00"},"4":{"desde":"08:00","hasta":"18:00"},"5":{"desde":"08:00","hasta":"18:00"}}');

-- ---------------------------------------------------------------------
-- 2. Agente: rol, objetivo y la RUTA de conversación
--    `instrucciones` es la parte AJUSTABLE desde el panel. Las reglas
--    jurídicas inquebrantables NO están aquí: viven en el código del
--    adaptador (SoportaReglasDeDominio) y ningún panel las toca.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wa_agentes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL DEFAULT 'Asistente',
  `rol` varchar(200) DEFAULT NULL,
  `objetivo` text DEFAULT NULL,
  `personalidad` varchar(200) DEFAULT NULL,
  `idioma` varchar(10) NOT NULL DEFAULT 'es',
  `instrucciones` text DEFAULT NULL COMMENT 'Lo que escribe el despacho; NO puede pisar las reglas del sistema',
  `herramientas` text DEFAULT NULL COMMENT 'JSON: lista blanca; NULL = todas',
  `saludo_inicial` text DEFAULT NULL,
  `mensaje_fuera_horario` text DEFAULT NULL,
  `mensaje_error` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `wa_agentes`
  (`id`, `nombre`, `rol`, `objetivo`, `personalidad`, `instrucciones`, `saludo_inicial`, `mensaje_fuera_horario`, `mensaje_error`)
VALUES
  (1,
   'Asistente del despacho',
   'Eres el asistente del despacho de Pedro, abogado especialista en derecho aduanero y comercio exterior, y en derecho tributario, con más de 15 años de experiencia.',
   'Que cada persona con un procedimiento en curso ante la DIAN salga de la conversación con una cita agendada con el abogado. Toda la conversación conduce ahí.',
   'Serio, cercano y preciso. Vocabulario jurídico correcto, sin tecnicismos innecesarios. Transmites que el despacho domina la materia.',
   'RUTA DE ATENCIÓN (en orden; puedes adelantarte si el cliente ya llegó decidido):\n\n1. ENTIENDE el problema. Deja que la persona lo cuente; haz máximo una pregunta por mensaje para ubicar el caso: ¿es aduanero (aprehensión, decomiso, clasificación arancelaria, valoración, levante, origen/TLC, zona franca, tránsito, sanción a agencia) o tributario (requerimiento especial, liquidación oficial, fiscalización de renta o IVA, sanción, devolución)? ¿Ya hay un acto o requerimiento de la DIAN? ¿Hay términos corriendo?\n\n2. DEMUESTRA dominio nombrando el problema con su nombre técnico y diciendo qué está en juego EN GENERAL (mayores tributos, sanción, decomiso). Una o dos frases de contexto, no más: el análisis del caso es de la cita, no del chat.\n\n3. Si cuenta que hay un OPERATIVO en curso o mercancía recién aprehendida, trátalo como urgente: ofrece la cita más próxima disponible.\n\n4. ENCAMINA a la cita en cada mensaje, sin ser robótico: la asesoría es con el abogado, por videollamada, y del catálogo salen duración y precio (consúltalos, no los recuerdes). Si duda por el precio, explica qué incluye: revisión de documentos enviados con antelación, análisis con el especialista y hoja de ruta. No ofrezcas rebajas.\n\n5. AGENDA: consulta disponibilidad, ofrece 2 o 3 horarios, registra los datos (nombre, correo para la invitación, motivo) y crea la cita. Si el pago está configurado como requisito, explica con naturalidad que la cita queda confirmada al verificarse el pago.\n\n6. Si la persona NO tiene aún ningún procedimiento abierto, dilo con claridad: el despacho atiende procesos en curso. Invítala a escribir cuando reciba cualquier acto de la DIAN.\n\n7. Si piden hablar con una persona, hay un reclamo, o el caso no encaja (penal puro, laboral, civil), transfiere a un humano.\n\nAVISO DE DATOS (obligatorio): en tu PRIMER mensaje a un contacto nuevo incluye una línea: los datos que comparta se usan solo para agendar y preparar la asesoría.',
   'Hola 👋 Soy el asistente del despacho de Pedro, abogado aduanero y tributario. Cuéntame qué te llegó o qué está pasando con la DIAN y te ayudo a agendar una asesoría con el abogado. Los datos que compartas se usan únicamente para agendar y preparar tu asesoría.',
   'Gracias por escribir. En este momento estamos fuera del horario de atención, pero cuéntame tu caso y te respondo apenas retomemos. Si es un operativo en curso, dímelo para darle prioridad.',
   'Tuve un inconveniente técnico procesando tu mensaje 🙏 Voy a pasar tu solicitud a una persona del equipo para que te contacte.');

-- ---------------------------------------------------------------------
-- 3. Conversaciones, mensajes, eventos y catálogo de modelos: el esquema
--    del paquete, tal cual.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wa_conversaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `telefono` varchar(25) NOT NULL,
  `cliente_id` int DEFAULT NULL,
  `cliente_mesa_id` int DEFAULT NULL,
  `nombre_contacto` varchar(100) DEFAULT NULL,
  `estado` enum('IA_ACTIVA','IA_PAUSADA','HUMANO_ATENDIENDO','CERRADA') NOT NULL DEFAULT 'IA_ACTIVA',
  `agente_id` int DEFAULT NULL,
  `atendida_por` int DEFAULT NULL,
  `contexto` text DEFAULT NULL,
  `ultimo_mensaje_at` datetime DEFAULT NULL,
  `limite_avisado_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wa_conv_telefono` (`telefono`),
  KEY `idx_wa_conv_estado` (`estado`, `ultimo_mensaje_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wa_mensajes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversacion_id` int NOT NULL,
  `message_id_externo` varchar(128) DEFAULT NULL,
  `direccion` enum('entrante','saliente') NOT NULL,
  `tipo` enum('texto','audio','imagen','documento','sistema') NOT NULL DEFAULT 'texto',
  `contenido` text DEFAULT NULL,
  `media_ruta` varchar(255) DEFAULT NULL,
  `media_mime` varchar(80) DEFAULT NULL,
  `transcripcion` text DEFAULT NULL,
  `tokens_entrada` int NOT NULL DEFAULT 0,
  `tokens_salida` int NOT NULL DEFAULT 0,
  `proveedor` varchar(40) DEFAULT NULL,
  `modelo` varchar(120) DEFAULT NULL,
  `latencia_ms` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_msg_externo` (`message_id_externo`),
  KEY `idx_wa_msg_conv` (`conversacion_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wa_eventos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversacion_id` int DEFAULT NULL,
  `tipo` enum('mensaje','llm','tool','pago','handoff','error','webhook','config') NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wa_ev_conv` (`conversacion_id`, `id`),
  KEY `idx_wa_ev_tipo` (`tipo`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wa_modelos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(40) NOT NULL,
  `modelo_id` varchar(120) NOT NULL,
  `nombre` varchar(160) DEFAULT NULL,
  `contexto_max` int DEFAULT NULL,
  `soporta_vision` tinyint(1) NOT NULL DEFAULT 0,
  `soporta_tools` tinyint(1) NOT NULL DEFAULT 1,
  `estado` enum('disponible','nuevo','retirado') NOT NULL DEFAULT 'disponible',
  `descubierto_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `visto_ultima_vez` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_modelo` (`proveedor`, `modelo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. La transacción del motor y su pago (esquema del paquete).
--    `pedido_id` aquí apunta a `wa_citas.id`: en este despacho la
--    transacción ES la cita.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wa_pedidos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversacion_id` int NOT NULL,
  `pedido_id` int NOT NULL,
  `modo_entrega` enum('domicilio','recoger') NOT NULL DEFAULT 'recoger',
  `nombre` varchar(100) DEFAULT NULL,
  `telefono` varchar(25) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `barrio` varchar(100) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `referencias` varchar(255) DEFAULT NULL,
  `costo_domicilio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `estado_pago` enum('PAYMENT_PENDING','PAYMENT_INITIATED','PAYMENT_SCREENSHOT_RECEIVED','PAYMENT_VALIDATING','PAYMENT_VERIFIED','PAYMENT_REJECTED','PAYMENT_REVIEW_REQUIRED','PAYMENT_EXPIRED','PAYMENT_REFUNDED') NOT NULL DEFAULT 'PAYMENT_PENDING',
  `enviado_cocina` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Marca del motor: la transacción ya se confirmó (aquí: la cita quedó en el calendario)',
  `idempotency_key` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_pedido` (`pedido_id`),
  UNIQUE KEY `uq_wa_pedido_idem` (`idempotency_key`),
  KEY `idx_wa_pedido_conv` (`conversacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wa_pagos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `wa_pedido_id` int NOT NULL,
  `proveedor` varchar(20) NOT NULL DEFAULT 'wompi',
  `estado` enum('PAYMENT_PENDING','PAYMENT_INITIATED','PAYMENT_SCREENSHOT_RECEIVED','PAYMENT_VALIDATING','PAYMENT_VERIFIED','PAYMENT_REJECTED','PAYMENT_REVIEW_REQUIRED','PAYMENT_EXPIRED','PAYMENT_REFUNDED') NOT NULL DEFAULT 'PAYMENT_PENDING',
  `monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `moneda` varchar(3) NOT NULL DEFAULT 'COP',
  `referencia` varchar(80) DEFAULT NULL,
  `transaccion_externa_id` varchar(120) DEFAULT NULL,
  `evento_externo_id` varchar(120) DEFAULT NULL,
  `enlace_pago` varchar(500) DEFAULT NULL,
  `metodo_detectado` varchar(40) DEFAULT NULL,
  `comprobante_media_ruta` varchar(255) DEFAULT NULL,
  `comprobante_extraido` text DEFAULT NULL,
  `verificado_por` enum('webhook','consulta_api','humano') DEFAULT NULL,
  `verificado_por_usuario_id` int DEFAULT NULL,
  `intentos` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_pago_ref` (`referencia`),
  KEY `idx_wa_pago_pedido` (`wa_pedido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. LA CITA — propia de este proyecto, no del paquete.
--
-- `slot_activo` es el truco que hace único el cupo sin impedir reutilizar
-- una hora cancelada: vale 1 mientras la cita vive (reservada/confirmada)
-- y pasa a NULL al cancelarse — y NULL nunca choca en un índice único.
-- La hora se guarda en hora local de Bogotá, que es la única zona que
-- existe en este negocio (CLAUDE.md: America/Bogota).
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wa_citas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversacion_id` int NOT NULL,
  `modalidad_id` char(36) NOT NULL COMMENT 'FK lógica a modalidades_asesoria',
  `nombre` varchar(100) NOT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `telefono` varchar(25) NOT NULL,
  `motivo` varchar(400) DEFAULT NULL,
  `inicio` datetime NOT NULL COMMENT 'Hora local America/Bogota',
  `duracion_min` smallint NOT NULL DEFAULT 60,
  `precio_cop` bigint NOT NULL DEFAULT 0 COMMENT 'PESOS enteros (ADR-010)',
  `estado` enum('reservada','confirmada','cancelada') NOT NULL DEFAULT 'reservada',
  `slot_activo` tinyint DEFAULT 1,
  `gcal_event_id` varchar(200) DEFAULT NULL,
  `gcal_meet_url` varchar(300) DEFAULT NULL,
  `observaciones` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_cita_slot` (`inicio`, `slot_activo`),
  KEY `idx_wa_cita_conv` (`conversacion_id`, `id`),
  KEY `idx_wa_cita_estado` (`estado`, `inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. Google Calendar — credenciales OAuth de la cuenta del abogado.
--    Secretos con el cifrado único del sistema (ADR-011). Una fila.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wa_google` (
  `id` tinyint NOT NULL,
  `client_id` varchar(200) DEFAULT NULL,
  `client_secret_cifrado` blob,
  `refresh_token_cifrado` blob,
  `calendar_id` varchar(200) NOT NULL DEFAULT 'primary',
  `correo_cuenta` varchar(150) DEFAULT NULL,
  `conectado_en` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `wa_google` (`id`) VALUES (1);
