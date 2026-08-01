-- =====================================================================
-- 0011 — Métricas por canal (Etapa 8)
--
-- El cierre de la etapa exige que el tablero muestre costo por lead y
-- conversión a asesoría pagada, POR CANAL. Los leads, las asesorías y los
-- ingresos ya viven en `contactos`, `consultas` y `pagos`; lo único que no
-- existe en ninguna tabla es cuánta plata se metió en cada canal.
--
-- `inversion_canales` guarda ese dato a mano: mes + canal + monto. A mano
-- a propósito — la integración con las APIs de Meta Ads y Google Ads exige
-- tokens del negocio que no existen todavía, y un tablero que espera por
-- una integración no muestra nada. El día que los tokens lleguen, un cron
-- podrá escribir estas mismas filas y el tablero no se entera del cambio.
--
-- El canal es el `utm_source` de la campaña (meta, google) o el
-- `canal_origen` del contacto para el tráfico directo. VARCHAR y no ENUM:
-- los canales de pauta cambian más rápido que los esquemas.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inversion_canales (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mes         DATE            NOT NULL COMMENT 'primer día del mes',
  canal       VARCHAR(100)    NOT NULL,
  monto_cop   BIGINT          NOT NULL COMMENT 'pesos enteros, ADR-010',
  anotado_por CHAR(36)                 DEFAULT NULL,
  creado_en   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_inversion_mes_canal (mes, canal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
