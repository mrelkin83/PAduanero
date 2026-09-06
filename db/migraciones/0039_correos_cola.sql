-- =====================================================================
-- 0039 — Cola de correos con reintentos
--
-- Hasta ahora los correos (acceso tras compra, alertas) se enviaban en línea
-- con la petición: si el SMTP tardaba o fallaba en ese instante, el correo se
-- perdía o bloqueaba la respuesta. Esta cola los desacopla: el sitio ENCOLA y
-- un cron (bin/enviar-correos.php) los ENVÍA aparte, reintentando hasta 3
-- veces. Patrón tomado de MisRifas (email_queue), adaptado y reusando el
-- App\Soporte\Smtp de este proyecto.
--
-- El cuerpo se guarda ya en HTML (plantilla de marca) más una versión de
-- texto. `tipo` etiqueta el origen para la bitácora del panel (compra_acceso,
-- compra_aviso, certificado, cita_cliente, cita_pedro, recordatorio…).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `correos_cola` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `destinatario` varchar(190) NOT NULL,
  `asunto` varchar(255) NOT NULL,
  `cuerpo_html` mediumtext NOT NULL,
  `cuerpo_texto` mediumtext DEFAULT NULL,
  `tipo` varchar(40) NOT NULL DEFAULT 'general',
  `estado` enum('pendiente','enviado','fallido') NOT NULL DEFAULT 'pendiente',
  `intentos` tinyint unsigned NOT NULL DEFAULT 0,
  `ultimo_error` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `enviado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_correos_pendientes` (`estado`, `intentos`, `id`),
  KEY `idx_correos_bitacora` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
