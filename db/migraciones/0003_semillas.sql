-- =====================================================================
-- 0003 — SEMILLAS — Datos reales definidos por el Product Owner (2026-07-31)
-- Se aplica después de 0001_motor.sql y 0002_panel.sql.
--
-- Lo que está aquí ES la configuración de arranque del negocio.
-- Todo lo editable después vive en el panel, no en este archivo.
--
-- Todos los INSERT son repetibles: `INSERT IGNORE` donde hay clave única, y
-- guarda `WHERE NOT EXISTS` donde no la hay (modalidades y horarios). El
-- runner ya evita reaplicar la migración; esto es la segunda red, para el día
-- que alguien cargue el archivo a mano.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- ROLES
-- ---------------------------------------------------------------------
INSERT IGNORE INTO roles (clave, nombre, descripcion) VALUES
 ('super_admin','Super administrador','Acceso técnico total. Único que ve y edita credenciales y proveedores de IA. NO aprueba prompts ni publica contenido.'),
 ('abogado','Abogado titular','Dueño del negocio. Tarifas, agenda, casos, contenido, métricas. Aprueba prompts y verifica normas. NO ve credenciales.'),
 ('asistente','Asistente jurídico','Casos, agenda, conversaciones. Sin configuración ni finanzas.'),
 ('contador','Contador','Solo lectura de pagos y reportes financieros.');

-- ---------------------------------------------------------------------
-- PERMISOS  (matriz de docs/PANEL_ADMIN.md §3)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, modulo) VALUES
 ('tablero.ver','tablero'),
 ('casos.ver','casos'),
 ('casos.editar','casos'),
 ('agenda.ver','agenda'),
 ('agenda.editar','agenda'),
 ('pagos.transacciones.ver','pagos'),
 ('pagos.credenciales.ver','pagos'),
 ('pagos.credenciales.escribir','pagos'),
 ('ia.proveedores.ver','ia'),
 ('ia.proveedores.escribir','ia'),
 ('ia.prompts.editar','ia'),
 ('ia.prompts.aprobar','ia'),
 ('ia.consumo.ver','ia'),
 ('kb.cargar','conocimiento'),
 ('kb.verificar','conocimiento'),
 ('contenido.editar','contenido'),
 ('contenido.publicar','contenido'),
 ('config.ver','config'),
 ('config.editar','config'),
 ('usuarios.ver','usuarios'),
 ('usuarios.editar','usuarios'),
 ('auditoria.ver','usuarios'),
 ('motor.killswitch','motor');

-- ---------------------------------------------------------------------
-- MATRIZ ROLES × PERMISOS
--
-- Las dos asimetrías del ADR-007 están aquí, y son deliberadas:
--   · super_admin NO tiene ia.prompts.aprobar, kb.verificar ni
--     contenido.publicar. Tiene las llaves técnicas; la responsabilidad
--     profesional es del abogado. Si el bot dice una barbaridad jurídica, la
--     firma que la autorizó debe ser la suya.
--   · abogado NO tiene pagos.credenciales.*. No las necesita y no debería
--     poder filtrarlas. Ve máscaras y el botón de probar conexión.
--
-- El "✔ (parcial)" del abogado en Configuración general no se modela aquí:
-- lo resuelve `configuraciones.rol_minimo` fila por fila.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
WHERE (r.clave = 'super_admin' AND p.clave IN (
        'tablero.ver','casos.ver','casos.editar','agenda.ver','agenda.editar',
        'pagos.transacciones.ver','pagos.credenciales.ver','pagos.credenciales.escribir',
        'ia.proveedores.ver','ia.proveedores.escribir','ia.prompts.editar','ia.consumo.ver',
        'kb.cargar','contenido.editar','config.ver','config.editar',
        'usuarios.ver','usuarios.editar','auditoria.ver','motor.killswitch'))
   OR (r.clave = 'abogado' AND p.clave IN (
        'tablero.ver','casos.ver','casos.editar','agenda.ver','agenda.editar',
        'pagos.transacciones.ver',
        'ia.proveedores.ver','ia.prompts.editar','ia.prompts.aprobar','ia.consumo.ver',
        'kb.cargar','kb.verificar','contenido.editar','contenido.publicar',
        'config.ver','config.editar','usuarios.ver','auditoria.ver','motor.killswitch'))
   OR (r.clave = 'asistente' AND p.clave IN (
        'tablero.ver','casos.ver','casos.editar','agenda.ver','kb.cargar','contenido.editar'))
   OR (r.clave = 'contador' AND p.clave IN (
        'tablero.ver','pagos.transacciones.ver'));

-- ---------------------------------------------------------------------
-- MODALIDAD DE ASESORÍA — definida por el PO
--   Asesoría virtual · 60 minutos · $400.000 COP
--
--   precio_cop va en PESOS (ADR-010). La conversión a centavos ocurre solo
--   en Pagos::crearLink(); aquí un 40000000 le cobraría $40 millones al
--   cliente. Hay una prueba de nivel 1 que vigila exactamente esto.
-- ---------------------------------------------------------------------
INSERT INTO modalidades_asesoria
  (nombre, descripcion, duracion_min, precio_cop, modalidad, requiere_pago, activo, orden)
SELECT 'Asesoría jurídica virtual (1 hora)',
       'Revisión del caso con el abogado por videollamada. Incluye análisis de los documentos enviados con antelación y hoja de ruta de la actuación.',
       60, 400000, 'virtual', 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM modalidades_asesoria);

-- ---------------------------------------------------------------------
-- HORARIOS BASE  (lunes a viernes 8–12 y 14–18; sábado 9–12)
-- Ajustables desde el panel. Pedro debe confirmarlos.
-- ---------------------------------------------------------------------
INSERT INTO horarios (dia_semana, hora_inicio, hora_fin)
SELECT * FROM (
  SELECT 1 d,'08:00:00' i,'12:00:00' f UNION ALL SELECT 1,'14:00:00','18:00:00'
  UNION ALL SELECT 2,'08:00:00','12:00:00' UNION ALL SELECT 2,'14:00:00','18:00:00'
  UNION ALL SELECT 3,'08:00:00','12:00:00' UNION ALL SELECT 3,'14:00:00','18:00:00'
  UNION ALL SELECT 4,'08:00:00','12:00:00' UNION ALL SELECT 4,'14:00:00','18:00:00'
  UNION ALL SELECT 5,'08:00:00','12:00:00' UNION ALL SELECT 5,'14:00:00','18:00:00'
  UNION ALL SELECT 6,'09:00:00','12:00:00'
) base
WHERE NOT EXISTS (SELECT 1 FROM horarios);

-- ---------------------------------------------------------------------
-- CONFIGURACIÓN
-- ---------------------------------------------------------------------
INSERT IGNORE INTO configuraciones (clave, valor, tipo, grupo, etiqueta, ayuda, rol_minimo, minimo, maximo, opciones) VALUES
-- Motor
('motor_ia_pausado','false','booleano','motor','Pausar toda la IA','Kill switch. Chatwoot y WhatsApp siguen funcionando; solo calla el bot.','abogado',NULL,NULL,NULL),
('motor_modo_sombra','true','booleano','motor','Modo sombra','La IA redacta como nota privada en Chatwoot en vez de enviar. Arranca encendido.','abogado',NULL,NULL,NULL),
('max_turnos_ia','40','entero','motor','Máximo de turnos por conversación','Tope de costo. Al superarlo se escala a humano.','abogado',5,200,NULL),
('ventana_rafaga_ms','6000','entero','motor','Ventana de ráfaga (ms)','Espera antes de responder, para agrupar mensajes seguidos.','abogado',1000,30000,NULL),
('horario_atencion_bot','{"inicio":"07:00","fin":"20:00","dias":[1,2,3,4,5,6]}','json','motor','Horario del bot','Fuera de este horario responde con mensaje de espera.','abogado',NULL,NULL,NULL),
('areas_practica','["aduanero","tributario"]','json','motor','Áreas de práctica','Determina qué consultas son "fuera de alcance". Confirmado por el PO: aduanero + tributario.','abogado',NULL,NULL,NULL),

-- Agenda
('minutos_reserva_pago','45','entero','agenda','Minutos de reserva sin pago','Tiempo que se aparta el cupo mientras el cliente paga.','abogado',10,240,NULL),
('dias_max_anticipacion','30','entero','agenda','Días máximos de anticipación','','abogado',1,180,NULL),
('anticipacion_minima_horas','4','entero','agenda','Anticipación mínima (horas)','Evita que agenden para dentro de diez minutos.','abogado',0,72,NULL),

-- Pagos
('pasarela_activa','"wompi"','lista','pagos','Pasarela de pago','','super_admin',NULL,NULL,'["wompi","bold","mercadopago"]'),
('metodos_pago_habilitados','["PSE","CARD","BANCOLOMBIA_TRANSFER","NEQUI"]','json','pagos','Métodos de pago','A $400.000 el medio importa: PSE y transferencia reducen la fricción frente a tarjeta.','abogado',NULL,NULL,NULL),
('politica_reembolso','""','texto','pagos','Política de reembolso','PENDIENTE: debe redactarla el abogado antes de cobrar el primer peso.','abogado',NULL,NULL,NULL),
('horas_cancelacion_sin_costo','24','entero','pagos','Horas para cancelar sin costo','','abogado',0,168,NULL),

-- IA
('llm_pais_permitido','["CO","US"]','json','ia','Países permitidos para el LLM','Si el proveedor está fuera de Colombia, el consentimiento debe declarar transferencia internacional.','super_admin',NULL,NULL,NULL),
('rag_fragmentos_max','4','entero','ia','Fragmentos de conocimiento por respuesta','','super_admin',1,10,NULL),
('presupuesto_ia_mensual_usd','100','decimal','ia','Presupuesto mensual de IA (USD)','Al superarlo se alerta y opcionalmente se pausa.','abogado',0,5000,NULL),

-- Legal
('retencion_conversaciones_dias','730','entero','legal','Retención de conversaciones (días)','','abogado',30,3650,NULL),
('retencion_casos_descartados_dias','365','entero','legal','Retención de casos descartados (días)','','abogado',30,3650,NULL),
('version_politica_datos','"v1.0-2026-08"','texto','legal','Versión de la política de datos','Cambiarla obliga a re-solicitar consentimiento a todos los contactos.','abogado',NULL,NULL,NULL),
('texto_aviso_habeas_data','""','texto','legal','Aviso de habeas data','PENDIENTE: texto exacto que envía el bot. Lo aprueba el abogado.','abogado',NULL,NULL,NULL),

-- Notificaciones y marca
('whatsapp_numero_negocio','"573159923676"','texto','notificaciones','WhatsApp del negocio','Número dedicado en formato E.164 sin el signo +. Confirmado por el PO.','abogado',NULL,NULL,NULL),
('whatsapp_alertas_abogado','""','texto','notificaciones','WhatsApp para alertas internas','PENDIENTE: debe ser DISTINTO del número del negocio. Recibe escalamientos urgentes.','abogado',NULL,NULL,NULL),
('alertar_lead_puntaje_min','70','entero','notificaciones','Alertar si el puntaje supera','','abogado',0,100,NULL),

-- Landing
('landing_mensaje_whatsapp','"Hola, necesito asesoría jurídica sobre un tema con la DIAN."','texto','landing','Mensaje prellenado de WhatsApp','Se le anexan los UTMs para atribuir la campaña.','abogado',NULL,NULL,NULL),
('landing_ruta_imagenes','"/img"','texto','landing','Carpeta de imágenes','Confirmado por el PO.','abogado',NULL,NULL,NULL);

-- ---------------------------------------------------------------------
-- CONTENIDO DE LA LANDING
-- Textos base a partir del perfil entregado. Pedro debe revisarlos bajo el
-- marco de publicidad del abogado (Ley 1123 de 2007) antes de publicar:
-- ninguno promete resultados, y así debe permanecer.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO landing_bloques (clave, titulo, subtitulo, contenido, orden) VALUES

('hero',
 '¿La DIAN le aprehendió la mercancía o le llegó un requerimiento?',
 'Defensa jurídica especializada en derecho aduanero y tributario. Más de 15 años litigando ante la DIAN.',
 JSON_OBJECT(
   'imagen','/img/pedro-hero.jpg',
   'alt','Pedro, abogado especialista en derecho aduanero y tributario',
   'cta_texto','Hablar con el abogado por WhatsApp',
   'cta_secundario','Ver cómo funciona la asesoría'
 ), 1),

('credenciales',
 'Especialización, no generalidad',
 NULL,
 JSON_OBJECT(
   'imagen','/img/pedro-perfil.jpg',
   'items', JSON_ARRAY(
     JSON_OBJECT('titulo','Especialista en Derecho Aduanero y Comercio Exterior','detalle',''),
     JSON_OBJECT('titulo','Especialista en Derecho Tributario','detalle',''),
     JSON_OBJECT('titulo','Más de 15 años de experiencia','detalle','Litigio y procedimiento administrativo ante la DIAN')
   )
 ), 2),

('casos',
 'Situaciones que atiende',
 'Si su empresa está en alguna de estas, el tiempo corre.',
 JSON_OBJECT(
   'aduanero', JSON_ARRAY(
     'Aprehensión de mercancía','Decomiso','Cancelación de levante',
     'Clasificación arancelaria','Valoración aduanera','Origen y TLC',
     'Operativos de la POLFA','Sanciones a agencias de aduanas',
     'Depósitos y tránsito aduanero'
   ),
   'tributario', JSON_ARRAY(
     'Requerimiento especial','Liquidación oficial de revisión',
     'Fiscalización de renta e IVA','Sanciones tributarias',
     'Devoluciones y compensaciones','Recurso de reconsideración'
   )
 ), 3),

('proceso',
 'Cómo funciona',
 NULL,
 JSON_OBJECT(
   'imagen','/img/pedro-documentos.jpg',
   'alt','El abogado revisando una declaración de importación',
   'pasos', JSON_ARRAY(
     JSON_OBJECT('n',1,'titulo','Escriba por WhatsApp','detalle','Cuente qué pasó. Le responden el mismo día.'),
     JSON_OBJECT('n',2,'titulo','Agende la asesoría','detalle','Una hora por videollamada. $400.000.'),
     JSON_OBJECT('n',3,'titulo','Envíe los documentos','detalle','El acta, la declaración y las facturas. El abogado llega con el caso leído.'),
     JSON_OBJECT('n',4,'titulo','Reciba la hoja de ruta','detalle','Qué se puede hacer, en qué orden y con qué plazos reales.')
   )
 ), 4),

('cta_final',
 'Entre más temprano, más opciones',
 'La mayoría de los casos que se pierden se pierden por tiempo, no por argumentos.',
 JSON_OBJECT(
   'imagen','/img/pedro-comercio-exterior.jpg',
   'alt','Pedro, especialista en derecho aduanero y comercio exterior',
   'cta_texto','Escribir ahora por WhatsApp'
 ), 5);

-- ---------------------------------------------------------------------
-- PROVEEDOR DE IA — placeholder. Completar desde el panel.
-- Las credenciales NO se siembran aquí: van cifradas vía el panel.
-- ---------------------------------------------------------------------
-- (sin filas: se crean desde Configuración → Inteligencia artificial)
