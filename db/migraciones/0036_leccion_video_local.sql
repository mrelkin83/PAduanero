-- =====================================================================
-- 0036 — Video de la lección servido desde el propio servidor (no Bunny)
--
-- Decisión del PO (2026-09-05): con disco de sobra en el VPS y cursos que
-- no pasan de 20 GB, los videos se alojan y sirven localmente en vez de
-- Bunny Stream — control total y sin costo por streaming.
--
-- `video_archivo` es el NOMBRE del archivo (no una ruta) dentro de
-- storage/cursos/videos/<leccion_id>/. Se sube por SFTP al VPS y se
-- registra aquí desde el panel. La entrega es protegida: AccesoLeccion
-- verifica la compra y nginx sirve el archivo por X-Accel-Redirect.
--
-- Aditiva (ADR-013): `video_bunny_id` se conserva. Si una lección tiene
-- las dos, gana el video local. Deja la puerta abierta a volver a Bunny
-- sin perder nada.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `curso_lecciones`
  ADD COLUMN `video_archivo` varchar(255) DEFAULT NULL
  COMMENT 'Nombre del archivo de video en storage/cursos/videos/<leccion_id>/. Servido con control de acceso; tiene prioridad sobre video_bunny_id.'
  AFTER `video_bunny_id`;
