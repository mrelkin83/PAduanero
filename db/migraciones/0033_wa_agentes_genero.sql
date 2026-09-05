-- =====================================================================
-- 0033 — Género gramatical del agente, explícito y no ambiguo
--
-- `rol`, `personalidad` e `instrucciones` son prosa libre que el despacho
-- edita a mano, y ya se vio en vivo (2026-08-26) que basta con que UNO de
-- los tres quede desactualizado («eres un asistente virtual») para que el
-- modelo conteste en el género contrario al que dicen los otros dos. Pedirle
-- al operador que mantenga la concordancia a mano en tres campos de texto
-- libre es pedir que no vuelva a pasar exactamente lo que ya pasó.
--
-- Este campo es la fuente única: PromptComposer añade la frase de
-- concordancia SIEMPRE, en código, a partir de este valor — no de lo que
-- diga la prosa de rol/personalidad/instrucciones.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `wa_agentes`
  ADD COLUMN `genero` enum('femenino','masculino') NOT NULL DEFAULT 'femenino'
  COMMENT 'Con qué género gramatical se refiere el agente a sí mismo. Lo aplica PromptComposer, no la prosa de rol/instrucciones.'
  AFTER `personalidad`;
