-- =====================================================================
-- 0015 — Relleno provisional de confianza y testimonios
--
-- La migración 0014 dejó las dos secciones vacías, y con eso invisibles.
-- Era correcto de cara al visitante y molesto de cara al trabajo: no se podía
-- ver la composición ni enseñarla para decidir sobre ella.
--
-- El PO no tiene todavía la tarjeta profesional, el NIT ni las direcciones, y
-- se cargarán después. Esto llena las dos secciones para poder verlas.
--
-- ---------------------------------------------------------------------
-- LA DIFERENCIA QUE HACE QUE ESTO SEA SEGURO
-- ---------------------------------------------------------------------
-- Cada dato provisional lleva `pendiente: true`, y eso NO es un comentario:
-- lo leen la plantilla y el tablero.
--
--   · La plantilla pinta el valor en gris y con una marca «pendiente» al
--     lado, nunca como un dato normal, y suprime el enlace de verificación —
--     un enlace a un registro donde no hay nada que consultar es peor que
--     ninguno.
--   · El tablero sigue contándolo como FALTANTE. Si el aviso desapareciera
--     al poner relleno, el relleno se quedaría ahí para siempre: nada
--     volvería a recordarlo.
--
-- Es la distinción que importa. Un texto de relleno que se ve como relleno es
-- una maqueta; un número de tarjeta profesional inventado que se ve como real
-- es una constancia falsa en la página de un abogado, y sería exactamente el
-- fraude que esta sección existe para desmentir. Por eso ningún valor de aquí
-- tiene la forma de un dato verdadero: no hay dígitos que parezcan un NIT ni
-- una calle con número.
--
-- Al cargar los datos reales hay que **quitar `pendiente`**, no solo cambiar
-- el valor. Mientras esté, la página seguirá diciendo que ese dato no está
-- confirmado — que es la verdad.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Confianza
--
-- Se sobreescribe el contenido entero porque 0014 lo dejó vacío y aquí no
-- hay nada del usuario que preservar. La condición del WHERE evita pisar
-- datos reales si esta migración llegara a correrse sobre una base donde ya
-- se cargaron: si algún verificable perdió su marca de pendiente, es que
-- alguien puso el dato bueno y no se toca.
-- ---------------------------------------------------------------------
UPDATE landing_bloques
   SET contenido = JSON_OBJECT(
     'verificables', JSON_ARRAY(
       JSON_OBJECT(
         'etiqueta','Tarjeta profesional',
         'valor','Pendiente de cargar',
         'pendiente', TRUE,
         'nota','Consulte el número en el Registro Nacional de Abogados del Consejo Superior de la Judicatura',
         'enlace_texto','Registro Nacional de Abogados',
         'url',''
       ),
       JSON_OBJECT(
         'etiqueta','NIT y razón social',
         'valor','Pendiente de cargar',
         'pendiente', TRUE,
         'nota','Razón social y matrícula mercantil, en el RUES de las cámaras de comercio',
         'enlace_texto','Consultar en el RUES',
         'url','https://www.rues.org.co/'
       )
     ),
     'sedes', JSON_ARRAY(
       JSON_OBJECT(
         'nombre','Zona Franca de Bogotá',
         'direccion','Dirección pendiente de cargar',
         'pendiente', TRUE,
         'detalle','A pie de operación: donde se aprehende la mercancía es donde conviene resolverlo.',
         'horario','Horario pendiente de confirmar'
       ),
       JSON_OBJECT(
         'nombre','Teusaquillo, Bogotá',
         'direccion','Dirección pendiente de cargar',
         'pendiente', TRUE,
         'detalle','Atención de casos tributarios y reuniones con documentos sobre la mesa.',
         'horario','Horario pendiente de confirmar'
       )
     ),
     'invitacion','Puede venir a cualquiera de las dos oficinas a comprobar que existimos, antes de pagar nada.'
   )
 WHERE clave = 'confianza'
   AND JSON_EXTRACT(contenido, '$.verificables[0].pendiente') IS NULL
   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(contenido, '$.verificables[0].valor')), '') = '';

-- ---------------------------------------------------------------------
-- 2. Testimonios
--
-- Tres ejemplos, los tres con `pendiente`. La plantilla los pinta con una
-- marca «ejemplo» encima y con el nombre en gris, así que no pueden
-- confundirse con reseñas reales de nadie.
--
-- El texto está escrito a propósito con la forma que tendrán los buenos:
-- describen el TRATO recibido —que contestó el mismo día, que explicó en
-- español, que dijo desde el principio qué no se podía hacer— y ninguno
-- menciona un desenlace. Un «me devolvieron todo» entra en el terreno que
-- regula la Ley 1123 de 2007, y además es el que menos confianza da porque
-- suena a promesa. Que el relleno ya tenga la forma correcta ahorra la
-- discusión el día que lleguen los de verdad.
-- ---------------------------------------------------------------------
UPDATE landing_bloques
   SET contenido = JSON_OBJECT('items', JSON_ARRAY(
     JSON_OBJECT(
       'texto','Llamé un viernes con el contenedor retenido y me contestó el mismo día. Me explicó en español qué estaba pasando y qué seguía, sin prometerme nada.',
       'autor','Nombre pendiente',
       'cargo','Gerente de operaciones',
       'empresa','Empresa pendiente',
       'pendiente', TRUE
     ),
     JSON_OBJECT(
       'texto','Llevaba tres semanas sin entender el requerimiento. Salí de la primera reunión sabiendo exactamente qué documentos reunir y en qué orden.',
       'autor','Nombre pendiente',
       'cargo','Directora financiera',
       'empresa','Empresa pendiente',
       'pendiente', TRUE
     ),
     JSON_OBJECT(
       'texto','Lo que más me sirvió fue que desde el principio me dijo qué no se podía hacer. Nadie me había hablado así antes.',
       'autor','Nombre pendiente',
       'cargo','Representante legal',
       'empresa','Agencia de aduanas pendiente',
       'pendiente', TRUE
     )
   ))
 WHERE clave = 'testimonios'
   AND JSON_LENGTH(COALESCE(JSON_EXTRACT(contenido, '$.items'), JSON_ARRAY())) = 0;
