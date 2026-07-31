-- =====================================================================
-- 0004 — Configuración de la landing (Etapa 1)
--
-- Solo INSERT sobre `configuraciones`: añadir un parámetro es un INSERT, no
-- un cambio de código (CLAUDE.md §9). Sin cambios de esquema.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO configuraciones
  (clave, valor, tipo, grupo, etiqueta, ayuda, rol_minimo, minimo, maximo, opciones)
VALUES

-- SEO ------------------------------------------------------------------
('landing_meta_titulo',
 '"Abogado aduanero y tributario · Defensa ante la DIAN"',
 'texto','landing','Meta título',
 'Máximo 60 caracteres o Google lo recorta. Sale en la pestaña y en el resultado de búsqueda.',
 'abogado',NULL,NULL,NULL),

('landing_meta_descripcion',
 '"Defensa jurídica en aprehensión de mercancía, requerimientos y fiscalización de la DIAN. Más de 15 años en derecho aduanero y tributario."',
 'texto','landing','Meta descripción',
 'Máximo 160 caracteres. No es factor de posicionamiento, pero sí de que hagan clic.',
 'abogado',NULL,NULL,NULL),

('landing_indexable',
 'true','booleano','landing','Permitir indexación',
 'En falso, robots.txt bloquea todo y la página emite noindex. Apagarlo mientras el copy no esté revisado bajo la Ley 1123 de 2007.',
 'abogado',NULL,NULL,NULL),

-- Rendimiento ----------------------------------------------------------
('landing_cache_segundos',
 '300','entero','landing','Caché de la landing (segundos)',
 'La landing se sirve desde HTML ya renderizado. Con caché caliente no toca MySQL. Cero desactiva la caché, solo para depurar.',
 'abogado',0,86400,NULL),

-- Widget de Chatwoot ---------------------------------------------------
-- Se despliega en la Etapa 2. Vacío = no se emite el script, para no cargar
-- JavaScript de un servidor que todavía no existe.
('chatwoot_widget_token',
 '""','texto','landing','Token del widget web de Chatwoot',
 'PENDIENTE hasta la Etapa 2. Vacío: la landing no carga el widget. Es el website_token del inbox de tipo Website.',
 'super_admin',NULL,NULL,NULL),

('chatwoot_widget_url',
 '""','texto','landing','URL base de Chatwoot para el widget',
 'PENDIENTE hasta la Etapa 2. Ej: https://chat.pedroabogadoaduanero.com',
 'super_admin',NULL,NULL,NULL),

-- Instrumentación ------------------------------------------------------
('landing_eventos_por_sesion',
 '60','entero','landing','Tope de eventos por sesión',
 'Freno a bucles accidentales del JavaScript. El abuso deliberado se ataja con limit_req en Nginx, no aquí.',
 'super_admin',10,1000,NULL);
