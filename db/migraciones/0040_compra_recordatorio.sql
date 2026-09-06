-- =====================================================================
-- 0040 — Marca de recordatorio de curso sin terminar
--
-- El cron bin/recordar-cursos.php avisa a quien compró y no ha terminado el
-- curso, pasados unos días. Esta columna evita repetir el recordatorio: una
-- vez enviado (o una vez que se comprueba que ya terminó), se sella la fecha
-- y esa compra no se vuelve a revisar.
--
-- Aditiva (ADR-013). NULL = aún no evaluada para recordatorio.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `compras_curso`
  ADD COLUMN `recordatorio_en` datetime DEFAULT NULL
  COMMENT 'Cuándo se envió (o descartó) el recordatorio de curso sin terminar. NULL = pendiente de evaluar.'
  AFTER `pagado_en`;
