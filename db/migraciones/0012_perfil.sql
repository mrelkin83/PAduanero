-- =====================================================================
-- 0012 — El diagnóstico público (`/perfil`)
--
-- Aditiva y sin cambios de esquema salvo un comentario de columna
-- (ADR-013). Todo lo demás son INSERT IGNORE sobre tablas que ya existen:
-- el contenido de una página es contenido, no código.
--
-- Lo que NO hay aquí, y es lo importante: ninguna tabla para guardar
-- respuestas. El diagnóstico se resuelve entero en el navegador y sale por
-- el mensaje prellenado de WhatsApp. No hay dato personal que tratar, así
-- que no hay habeas data que pedir (regla 1) — y la primera vez que este
-- sistema guarde algo de esa persona seguirá siendo el motor, con el
-- consentimiento que siempre exige.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Tipos de evento del embudo del diagnóstico
--
-- `tipo` es VARCHAR y no ENUM, así que la lista cerrada vive en dos
-- sitios: `MetricasLanding::TIPOS`, que la hace cumplir, y este comentario,
-- que es donde se lee al abrir el esquema. Se actualiza para que no queden
-- discrepando.
-- ---------------------------------------------------------------------
ALTER TABLE eventos_landing
  MODIFY COLUMN tipo VARCHAR(30) NOT NULL
  COMMENT 'vista|scroll_50|click_whatsapp|envio_form|perfil_inicio|perfil_paso|perfil_resultado';

-- ---------------------------------------------------------------------
-- 2. Configuración de la página
-- ---------------------------------------------------------------------
INSERT IGNORE INTO configuraciones
  (clave, valor, tipo, grupo, etiqueta, ayuda, rol_minimo, minimo, maximo, opciones)
VALUES

('perfil_meta_titulo',
 '"Diagnostique su caso ante la DIAN en dos minutos"',
 'texto','landing','Meta título del diagnóstico',
 'Máximo 60 caracteres o Google lo recorta.',
 'abogado',NULL,NULL,NULL),

('perfil_meta_descripcion',
 '"Seis preguntas para saber qué le está pasando con la DIAN y cómo se llama. Sin dejar datos y sin costo."',
 'texto','landing','Meta descripción del diagnóstico',
 'Máximo 160 caracteres. No posiciona, pero decide si hacen clic.',
 'abogado',NULL,NULL,NULL);

-- ---------------------------------------------------------------------
-- 3. Bloques de contenido
--
-- `orden` solo ordena la lista de edición del panel. El orden con el que se
-- pintan los bloques de la landing lo fija `plantillas/landing/pagina.php`,
-- que es donde se puede ver de un vistazo.
--
-- Como todo el copy de esta landing: no promete un resultado, no cita una
-- norma con número y no nombra un plazo. Pedro lo revisa bajo la Ley 1123
-- de 2007 antes de que `landing_indexable` se ponga en verdadero.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO landing_bloques (clave, titulo, subtitulo, contenido, orden) VALUES

-- Invitación, en la landing, justo detrás del índice de situaciones.
('perfil',
 '¿No sabe cómo se llama lo que le está pasando?',
 'Seis preguntas y le decimos el nombre técnico de su situación. Ese nombre es lo que cambia una conversación con la DIAN.',
 JSON_OBJECT(
   'cta_texto','Diagnosticar mi caso',
   'cta_detalle','Menos de dos minutos. Sin dejar datos.',
   'promesas', JSON_ARRAY(
     'Sin nombre, sin correo, sin teléfono',
     'Nada se guarda: el resultado queda en su pantalla',
     'Gratis y sin compromiso',
     'Al final usted decide si escribe'
   )
 ), 6),

-- Portada de /perfil.
('perfil_intro',
 'Veamos qué tiene entre manos',
 'Conteste seis preguntas sobre lo que recibió. Al final verá cómo se llama su situación en el lenguaje con el que la DIAN la trata, y podrá enviarla por WhatsApp ya redactada.',
 JSON_OBJECT(), 7),

-- Resultado.
('perfil_resultado',
 'Lo que usted tiene tiene nombre',
 NULL,
 JSON_OBJECT(
   'incluye', JSON_ARRAY(
     'Revisión de los documentos que envíe antes de la sesión',
     'Una hora por videollamada con el abogado',
     'Hoja de ruta: qué se puede hacer y en qué orden'
   )
 ), 8),

-- Fuera de alcance. El despacho atiende procesos correctivos: cosas ya
-- abiertas. Decirlo en el paso 1 es mejor que arrastrar a alguien por seis
-- pantallas para negarle al final, y mucho mejor que aceptar un encargo que
-- no se va a atender bien.
('perfil_fuera_alcance',
 'Este despacho entra cuando el problema ya existe',
 'Lo que se atiende aquí son procedimientos ya abiertos: una mercancía aprehendida, un requerimiento notificado, una sanción en curso. Si todavía no hay nada de eso, lo que necesita es acompañamiento en la operación, y ese no es el trabajo de este despacho.',
 JSON_OBJECT(), 9);
