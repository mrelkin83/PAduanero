-- =====================================================================
-- 0014 — Confianza verificable y testimonios
--
-- Aditiva y sin cambios de esquema (ADR-013): dos filas nuevas en
-- `landing_bloques` y nada más.
--
-- Responde a un miedo concreto y muy razonable del visitante: acaba de
-- perder el control de su mercancía, encontró un abogado por internet y le
-- van a pedir $400.000 por adelantado. La pregunta que se hace no es «¿será
-- bueno?», es «¿existirá?».
--
-- Contra ese miedo los testimonios sirven poco, y conviene entender por qué
-- antes de tocar este archivo: **un estafador puede escribir veinte
-- testimonios en diez minutos.** Quien teme estar ante uno lo sabe. Lo que
-- no puede falsificar es un número de tarjeta profesional que el visitante
-- comprueba él mismo en el registro del Consejo Superior de la Judicatura, ni
-- una oficina con dirección a la que puede llegar caminando.
--
-- De ahí el orden de la sección: primero lo comprobable, después las
-- personas. Y de ahí que TODO nazca vacío.
--
-- ---------------------------------------------------------------------
-- POR QUÉ NACE VACÍO, Y POR QUÉ NO ES PEREZA
-- ---------------------------------------------------------------------
-- Ninguna de estas filas trae un número de tarjeta profesional, ni un NIT,
-- ni una dirección, ni un testimonio. No es que falten datos: es que
-- inventarlos sería exactamente el fraude que esta sección existe para
-- desmentir. Una dirección falsa en la página de un abogado no es un dato de
-- relleno pendiente de reemplazo — es la prueba de que el visitante tenía
-- razón en desconfiar.
--
-- Las plantillas están escritas para eso: **omiten en silencio todo campo
-- vacío, y si no queda ningún dato comprobable la sección entera no se
-- pinta.** Una página sin sección de confianza es neutra; una con la sección
-- a medias, llena de rayas y huecos, es peor que no tenerla.
--
-- El tablero del panel avisa de lo que falta (`TableroControlador::pendientes`).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Confianza verificable
--
-- `verificables` son afirmaciones que el visitante puede comprobar SIN
-- pedirnos permiso ni creernos nada. Esa es la prueba de fuego para decidir
-- si algo entra en esta lista: si para confirmarlo hay que preguntarnos a
-- nosotros, no va aquí.
--
--   · La tarjeta profesional se consulta en el Registro Nacional de Abogados
--     del Consejo Superior de la Judicatura.
--   · El NIT y la razón social, en el RUES de las cámaras de comercio.
--
-- `sedes` es el otro medio comprobable, y el más contundente de los dos
-- porque no exige que nadie abra un navegador. La de Zona Franca dice además
-- algo que ningún texto puede decir igual de bien: nadie pone oficina en una
-- zona franca por casualidad, y quien tiene mercancía retenida ahí lo sabe.
--
-- `invitacion` es el gesto que remata: decirle que puede presentarse. Un
-- despacho que no existe no invita a nadie a tocarle la puerta.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO landing_bloques (clave, titulo, subtitulo, contenido, orden) VALUES

('confianza',
 'Puede comprobar todo lo que dice esta página',
 'Un abogado real tiene número de tarjeta profesional, NIT y oficina con dirección. Los tres se verifican sin pedirnos permiso, y aquí están para que lo haga.',
 JSON_OBJECT(
   'verificables', JSON_ARRAY(
     JSON_OBJECT(
       'etiqueta','Tarjeta profesional',
       'valor','',
       'nota','Consulte el número en el Registro Nacional de Abogados',
       'enlace_texto','Registro Nacional de Abogados',
       'url',''
     ),
     JSON_OBJECT(
       'etiqueta','NIT',
       'valor','',
       'nota','Razón social y matrícula, en el RUES de las cámaras de comercio',
       'enlace_texto','Consultar en el RUES',
       'url','https://www.rues.org.co/'
     )
   ),
   'sedes', JSON_ARRAY(
     JSON_OBJECT(
       'nombre','Zona Franca de Bogotá',
       'direccion','',
       'detalle','A pie de operación: donde se aprehende la mercancía y donde se resuelve.',
       'horario',''
     ),
     JSON_OBJECT(
       'nombre','Teusaquillo',
       'direccion','',
       'detalle','Atención de casos tributarios y reuniones con documentos.',
       'horario',''
     )
   ),
   'invitacion','Puede venir a cualquiera de las dos oficinas a comprobar que existimos, antes de pagar nada.'
 ), 10),

-- ---------------------------------------------------------------------
-- 2. Testimonios
--
-- Nace con la lista vacía, y esto es lo que hay que leer antes de llenarla.
--
-- **Cada elemento necesita `autorizado` en verdadero, y la plantilla lo
-- exige.** Un testimonio sin esa marca no se pinta aunque tenga texto y
-- autor. No es un interruptor de borrador: es que en este oficio publicar un
-- testimonio tiene dos consecuencias que no tiene en otros negocios.
--
-- **Secreto profesional.** Un testimonio identificado revela que esa empresa
-- tuvo mercancía aprehendida o una sanción de la DIAN. Eso es información
-- comercial sensible del cliente, no nuestra: publicarla sin permiso escrito
-- lo perjudica a él, y el permiso tiene que ser sobre el texto exacto que va
-- a salir, no sobre la idea de aparecer.
--
-- **Ley 1123 de 2007.** Regula la publicidad del abogado. Un testimonio que
-- insinúe resultados garantizados —«me devolvieron todo», «ganamos»— cae en
-- el mismo terreno que el resto de la página tiene prohibido. Sirve lo que
-- describe el TRATO recibido: que contestó rápido, que explicó en español,
-- que dijo desde el principio qué no se podía hacer.
--
-- Y una razón práctica que se olvida: **un testimonio anónimo no convence a
-- quien teme una estafa**, porque es indistinguible de uno inventado. Si no
-- se puede publicar con nombre y con permiso, es mejor no publicarlo — el
-- trabajo de dar confianza ya lo está haciendo el bloque de arriba, que es
-- comprobable.
-- ---------------------------------------------------------------------
('testimonios',
 'Lo que dicen quienes ya pasaron por esto',
 NULL,
 JSON_OBJECT('items', JSON_ARRAY()), 11);
