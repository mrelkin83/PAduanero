# CLAUDE.md — Sitio público de Pedro, abogado aduanero

> Documento maestro. Toda sesión sobre este repositorio debe leerlo completo
> antes de escribir código. Los cambios de esquema, dependencias, API o
> seguridad requieren aprobación explícita del Product Owner.

**Versión:** 2.0 · **Fecha:** 2026-08-17 · **Dominio:** pedroabogadoaduanero.com
(verificado contra el registro el 2026-08-22: es el único registrado, con DNS en
Cloudflare; una «corrección» de ese día hacia pedroaduanero.com resultó errónea)
**Infraestructura:** VPS propio (169.58.220.204) · **Zona horaria:** America/Bogota

---

## 0. Qué es esto ahora — y qué era

Esto es un **sitio público de dos páginas más un panel para editarlas**. Nada
más. Conviene decirlo primero porque el repositorio conserva la forma de algo
mucho más grande, y quien llegue nuevo va a encontrar tablas, migraciones y
comentarios que hablan de piezas que ya no existen.

Hasta el 2026-08-17 esto fue una plataforma de captación completa: un motor
conversacional sobre WhatsApp con máquina de estados, triage jurídico,
puntaje de lead y escalamiento; una capa de IA con RAG sobre MySQL; Chatwoot
como bandeja omnicanal; Evolution API como pasarela de WhatsApp; y Wompi para
cobrar la asesoría. **El PO decidió retirarlo todo.** El commit `3fcea6e`
tiene el detalle; son 17.100 líneas de las 28.500 que había.

Lo que queda:

| Pieza | Qué hace |
|---|---|
| `/` | La landing. Contenido editable desde el panel |
| `/perfil` | El diagnóstico: seis preguntas, cero persistencia (§4) |
| `/panel` | Entrar con 2FA, usuarios, configuración, tarifas, bitácora, métricas |
| `/api/wa/webhook/{token}`, `/api/wa/pago/{token}` | El motor de WhatsApp v2 (§0.2): mensajes de Evolution y eventos de Wompi |
| `/salud`, `/robots.txt`, `/sitemap.xml` | Operación y SEO |

**El embudo termina en un enlace de WhatsApp.** La landing y el diagnóstico
componen un mensaje prellenado y abren `wa.me`. A partir de ahí la
conversación ocurre en el teléfono de Pedro, fuera de este sistema. Eso
reordena todo lo demás: no hay datos personales que tratar, no hay cobros que
conciliar, no hay bot que pueda decir una barbaridad jurídica.

### 0.1 Lo que quedó en pie del sistema anterior, y por qué

No todo lo del motor se borró, y las excepciones no son descuido:

- **`src/Motor/Cuestionario.php` y `src/Motor/Catalogo.php`.** De ellos cuelga
  `/perfil`. El diagnóstico no es motor: es el triage adelantado a la landing
  y se resuelve entero en el navegador.
- **Las tablas del motor.** Ni un `DROP`. `casos`, `consultas`, `pagos`,
  `contactos`, `prompts`, `kb_*` y `eventos_outbox` quedan huérfanas pero
  intactas (ADR-013: migraciones siempre aditivas). Deja la puerta abierta a
  recuperar el motor sin perder lo que hubiera. El precio es un esquema con
  tablas que nadie usa — **no las uses para nada nuevo**: si hace falta
  guardar algo, tabla nueva.
- **`modalidades_asesoria`.** El precio sigue alimentando el copy de la
  landing y del diagnóstico, así que la pantalla de tarifas sobrevivió.

### 0.2 El motor de WhatsApp, versión 2 — 2026-08-22

El PO decidió volver a tener bot, pero **no el de antes**: se conectó el motor
conversacional extraído de ControlBarMax, vendorizado en
`packages/whatsapp-engine` (PHP puro, cero dependencias, arquitectura de
puertos; sus 49 pruebas propias corren con `php packages/whatsapp-engine/tests/prueba.php`).
Su trabajo aquí es distinto al del motor de 2026-08: **vender la asesoría y
agendarla**, no hacer triage jurídico por chat.

- **La integración vive en `src/Wa/`**: `MotorWa` (arranque),
  `DbMotor`/`SecretoMotor`/`ArchivosMotor` (puertos), `AdaptadorDespacho`
  (el dominio: catálogo = `modalidades_asesoria`, transacción = cita),
  `GoogleCalendar` (OAuth de la cuenta de Pedro, free/busy, evento con Meet)
  y `WebhookControlador` (el borde).
- **La cita es la transacción del motor**: `crearTransaccion` reserva la
  franja en `wa_citas` (atómico por índice único `inicio+slot_activo`);
  `confirmarTransaccion` crea el evento en el Google Calendar de Pedro con
  invitación al correo del cliente — y el motor solo la llama con el pago
  verificado, salvo que `wa_config.pago_modo = 'contra_entrega'` (el
  interruptor «agendar sin cobrar»).
- **Tablas nuevas `wa_*`** (migración 0016). Las huérfanas del motor viejo
  siguen huérfanas.
- **Las reglas jurídicas del bot NO están en el prompt editable**: van en
  `AdaptadorDespacho::reglasDeDominio()`, capa no editable del prompt
  (interfaz `SoportaReglasDeDominio` del paquete), y las defiende
  `ReglasDelBotTest`. La ruta de conversación SÍ es editable
  (`wa_agentes.instrucciones`).
- **Configuración**: `php bin/wa-configurar.php --estado` dice qué falta.
  El motor está **apagado** hasta que se resuelva el pendiente de datos
  personales (abajo) y Pedro apruebe el prompt.
- **OJO**: el bot **sí persiste datos personales** (teléfono, nombre, correo,
  motivo de consulta) en `wa_conversaciones`/`wa_citas`. El «cero
  persistencia» del §4 sigue siendo cierto **para `/perfil`**, pero el sitio
  como un todo ya no está fuera de la ley de datos: la política de
  tratamiento es requisito para encender (`wa_config.activo = 1`).

---

## 1. Stack

PHP 8.2+ · MySQL 8 · TailwindCSS 4 · JavaScript sin dependencias ·
`index.php` en la raíz. Es el stack del PO, que es quien va a mantener esto.

**Node existe solo para compilar el CSS y para las herramientas de
verificación** (`bin/auditar-landing.mjs`, `bin/capturar.mjs`). No hay
framework de front ni JavaScript de build.

Composer trae PHPUnit en `require-dev` y **dos** dependencias de
producción: `dompdf/dompdf`, para generar el PDF del certificado de
finalización (decisión del PO, 2026-08-29 — ver
`docs/superpowers/specs/2026-08-29-certificado-cursos-design.md` §7), y
`chillerlan/php-qrcode`, para el QR de verificación que lleva el
certificado (decisión del PO, 2026-09-05). El QR se genera como PNG si
hay extensión `gd`, y cae a SVG si no (`App\Soporte\GeneradorQr`).

---

## 2. Arquitectura

```
   Meta Ads / Google Ads / SEO
              │
              ▼
   ┌──────────────────────────┐        ┌────────────────────┐
   │  Aplicación (PHP 8.3)    │◄──────►│  MySQL 8           │
   │  · landing  /            │        │  configuraciones   │
   │  · diagnóstico /perfil   │        │  landing_bloques   │
   │  · panel /panel          │        │  usuarios, sesiones│
   │  · /api/evento           │        │  eventos_landing   │
   └────────────┬─────────────┘        │  auditoria         │
                │                      └────────────────────┘
                ▼
        wa.me (enlace, no API)
                │
                ▼
      El WhatsApp de Pedro — fuera de este sistema
```

**ADR-005 — PHP 8.2 + MySQL 8.** Se conserva. Es el stack del PO.

**ADR-006 — El panel no reimplementa una bandeja.** Lo era frente a Chatwoot;
sigue valiendo por otra razón: el panel administra **contenido y acceso**, no
conversaciones. Si algún día vuelve una bandeja, vuelve como sistema aparte.

**ADR-011 — Formato de los campos cifrados.** Un solo formato:
`v1 ‖ nonce(12) ‖ tag(16) ‖ ciphertext`. Hoy solo lo usa
`usuarios.totp_secret_cifrado`; cifraba también credenciales de proveedores y
el NIT de los contactos, que se fueron con el motor.

**ADR-013 — Migraciones numeradas, idempotentes y siempre aditivas.** Tabla
`migraciones (version, aplicada_en, hash)`. `bin/migrar.php` compara el hash
del archivo con el registrado y **aborta** si una migración ya aplicada cambió
de contenido, en vez de reaplicarla.

**ADR-012 — RETIRADO.** El HMAC del teléfono con `PEPPER_TELEFONO` se fue con
`contactos`, su único cliente. Se retiró también la variable de entorno: una
llave obligatoria que nada usa hay que generarla y custodiarla en cada
despliegue a cambio de nada. Si vuelve el motor, vuelve el ADR entero y con su
razón intacta — un SHA-256 pelado sobre doce dígitos se rompe por fuerza bruta
en segundos.

**ADR-010 — El dinero va en PESOS enteros.** `modalidades_asesoria.precio_cop`
y `consultas.precio_cop`. La multiplicación por 100 ocurría en
`Pagos::crearLink()` y ya no ocurre en ninguna parte: no hay pasarela. La
guarda contra teclear centavos donde van pesos **se queda** en
`TarifasControlador` — quien escribe el precio sigue siendo una persona y un
cero de más sigue acabando en la landing.

**ADR-007 / ADR-008 — Separación de llaves y prompts versionados.**
Retirados con la IA. La asimetría que sobrevive es más simple: el
`super_admin` administra acceso y sistema; el `abogado` aprueba el contenido
público, que es lo que lleva su firma profesional.

---

## 3. Reglas inviolables

Eran catorce y gobernaban un bot que hablaba con clientes. Sin bot, quedan
tres, y las tres son sobre lo que la **página** puede decir:

1. **La página nunca entrega términos, plazos ni fechas límite**, en ninguna
   forma. Un plazo mal dicho puede costar un caso y comprometer a Pedro.
2. **La página nunca cita normas con número**, ni redacta memoriales, ni da
   estrategia.
3. **La página nunca promete resultados** ni estima probabilidades de éxito.
   La Ley 1123 de 2007 regula la publicidad y la conducta del abogado en
   Colombia; el copy de la landing y del diagnóstico debe ser revisado por
   Pedro bajo ese marco.

No se defienden con un comentario sino con una prueba:
`CuestionarioTest::elCopyNoNombraPlazosNiNormas()` rechaza plazos en días o
meses y normas numeradas en cualquier texto del cuestionario.

**Principio general que sobrevive al recorte, y que conviene no perder:**
donde una regla se pueda hacer imposible de violar por la firma de un método
en vez de por convención, se hace así. El caso que queda vivo es
`Cuestionario::definicion()`, que deduce la salida crítica de
`Catalogo::esCritico()` en vez de repetir la lista (§4.2).

---

## 4. El diagnóstico público — ADR-017

`/perfil` es un cuestionario de seis pasos, con la rama aduanera, que
termina componiendo el mensaje prellenado de WhatsApp. (Hasta el
2026-08-25 hubo también una rama tributaria: se retiró junto con esa
área de práctica — §5, §7.)

Era el triage del motor adelantado a la landing. Sin motor detrás, es
**todo el triage que hay**: quien lo termina llega al WhatsApp de Pedro con
el caso ya descrito en el vocabulario correcto.

### 4.1 Cero persistencia, y por qué eso no es pereza

**Nada de lo que se responde se guarda.** Ni una fila, ni un log, ni una cola.
Se resuelve entero en el navegador y su única salida es el texto del mensaje,
que la persona lee antes de enviarlo.

Eso es lo que mantiene la página **fuera del alcance de la ley de datos
personales**: no hay tratamiento, así que no hay habeas data que pedir, y la
landing no tiene que exhibir un aviso que además sigue pendiente de redacción.

Guardar las respuestas «para que Pedro las vea aunque no escriban» es la
tentación evidente y es exactamente lo que convertiría una página anónima en
un fichero de datos personales de gente que solo estaba mirando.

### 4.2 La salida crítica sale del catálogo, no de una lista nueva

Marcar «operativo en curso» corta el cuestionario en seco y lleva a escribir
ya. Esa condición **no está escrita a mano** en la opción:
`Cuestionario::definicion()` la deduce de `Catalogo::esCritico()`.

Si alguien añade un tipo a `Catalogo::CRITICOS`, el diagnóstico deja de
preguntarle la cuantía a quien tiene la POLFA en la puerta **sin que haya que
acordarse** de tocar el cuestionario. Con dos listas, la segunda se queda
atrás y nadie lo nota.

### 4.3 Solo procesos correctivos

El despacho atiende procedimientos **ya abiertos**. La primera pregunta ofrece
la salida «todavía no hay nada abierto», y esa opción **termina el
cuestionario ahí**. Está en el paso 1 y no en el 6 a propósito: negar después
de que alguien contestó cinco preguntas es peor que no preguntar.

### 4.4 Tampoco aparece el puntaje

El puntaje de lead medía en parte capacidad de pago y no puede llegar a quien
acaba de perder su mercancía. El diagnóstico ni siquiera lo calcula.

### 4.5 La mención del expediente técnico — 2026-09-11

El resultado incluye un bloque que nombra a la consultora del despacho
(§7.1), y **solo aparece en las situaciones donde el expediente decide el
caso**: subpartida, valor en aduana, régimen aplicado, documentos soporte.

La condición no está escrita a mano en el JavaScript ni duplicada en una
lista: las opciones que la encienden llevan `'expediente' => true` en
`Cuestionario::pasos()`, y **`definicion()` la apaga en cualquier opción con
`salida`**. Eso cubre de una vez los dos casos en los que la mención estaría
mal, sin enumerarlos:

- «todavía nada: quiero prevenir» sale por `fuera_alcance` — ahí la revisión
  técnica sería el servicio principal y no un apoyo, y esa línea todavía no
  se vende (§7, pendientes);
- «operativo de la POLFA en curso» sale por `urgente` — ahí lo único sensato
  es escribir ya.

Y seguirá valiendo para la próxima salida que alguien añada.
`CuestionarioTest::ningunaSalidaDelCuestionarioMencionaElExpediente()` lo
defiende, y `algunaOpcionMencionaElExpediente()` impide lo contrario: que el
bloque quede en el HTML sin nadie que lo encienda.

El bloque va **oculto de salida**. Sin JavaScript el resultado se ve entero
y no hay forma de saber qué contestó nadie, así que la mención no se emite:
es un refuerzo, no parte del cierre (§4.6).

### 4.6 La página funciona sin JavaScript

Los seis pasos y las dos ramas se emiten **enteros** desde el servidor, como
un formulario de radios corriente. El script solo esconde lo que no toca.
Dos consecuencias buscadas: la conversión nunca depende de un script, y las
preguntas son indexables — que es lo único que hace que una página valga para
las búsquedas de las que salen estos clientes.

**Esta regla vale para todo el sitio.** El CSS trae guardas
`html:not([data-js])` para `.paso` y `.revelar`, y las dos plantillas arman en
su `<head>` una red con temporizador que revela el contenido si el JavaScript
no llega. Hay que verificarlo con el navegador a JS apagado, no razonarlo:
una vez el bloque del diagnóstico entero quedó invisible y la página no dio
ningún error.

---

## 5. Catálogo de tipos de caso

Pedro es **especialista en derecho aduanero y comercio exterior**. Lo usa
`Catalogo`, y de ahí sale el cuestionario.

**Aduanero:** `aprehension_mercancia` · `decomiso` · `cancelacion_levante` ·
`firmeza_declaracion` · `clasificacion_arancelaria` · `valoracion_aduanera` ·
`origen_tlc` · `operativo_polfa` · `contrabando_tecnico` · `deposito_habilitado` ·
`transporte_transito` · `devolucion_mercancia` · `agencia_aduanas_sancion` ·
`cuestionamiento_solvencia_economica` · `demoras_contenedor`

**Comunes:** `requerimiento_ordinario` · `proceso_sancionatorio` ·
`recurso_reconsideracion` · `nulidad_restablecimiento` · `fiscalizacion` · `otro`

Catálogo cerrado. **Esta lista es la normativa.**

**2026-08-25 — se retiró `TRIBUTARIO` del catálogo** (eran ocho tipos:
`requerimiento_especial`, `liquidacion_oficial_revision`,
`fiscalizacion_renta`, `fiscalizacion_iva`, `sancion_tributaria`,
`devolucion_compensacion`, `retencion_fuente`, `precios_transferencia`).
Decisión del PO: el despacho pasa a ser 100% aduanero. Ver §7.

---

## 6. Sistema visual — Lex Aeterna

Especificación normativa en `stitch_customs_law_digital_experience/`
(`DESIGN.md` y `screen.png`). El CSS documenta cada punto donde se separa de
ella y por qué.

Negro profundo como lienzo, superficies de grafito que se separan por
luminosidad y no por sombras, y **un único acento de oro metálico** reservado
a lo que manda: la cifra, la acción, el borde que se enciende. El vacío es el
material principal — 160 px entre secciones.

- **Tipografía:** Geist y Geist Mono, servidas locales desde `/fonts`. Las dos
  son variables (eje 100–900). Se precarga solo Geist, que es la del elemento
  LCP.
- **Formas:** 4 px estructural, 8 px en la acción principal. **Nada de
  píldoras** — lo pide la especificación y tiene razón: el radio completo es
  lenguaje de producto de consumo, y esto es un despacho.
- **El rojo** existe en un solo sitio y con nombre propio, `.marca-urgente`:
  la salida crítica del diagnóstico. En cualquier otro sitio deja de
  significar urgencia.

**El panel NO usa esta paleta.** `resources/css/panel.css` es autónomo y sigue
en claro, y eso es deliberado: son dos productos con dos trabajos distintos, y
las tablas densas y los avisos de error se leen mejor sobre claro por parte de
alguien que va a estar horas ahí.

### 6.1 Presupuesto de la landing

300 KB y LCP < 2 s, medidos por `bin/auditar-landing.mjs` en cada cierre. Hoy:
91.6 KB, Performance 99, Accesibilidad 100, LCP 1.5 s, CLS 0.

**La accesibilidad está en 100 y conviene que se quede ahí.** Estuvo en 96
mientras el botón principal fue verde de WhatsApp con texto blanco: 3.77:1
contra los 4.5:1 que exige la WCAG AA. El botón de oro con texto casi negro lo
resolvió de paso.

---

## 7. Decisiones del Product Owner

Cerradas. Ya están sembradas en la base; no hay que preguntarlas de nuevo.

| Punto | Valor |
|---|---|
| Modalidad de asesoría | Virtual, 60 minutos |
| Precio | **$400.000 COP** |
| WhatsApp del negocio | **`573112335405`** — único número; decisión del PO del 2026-09-11 |
| Imágenes | Disco `public/img/` · URL `/img` |
| Perfil | Especialista en Derecho Aduanero y Comercio Exterior · más de 15 años de experiencia |
| Equipo | Pedro (abogado titular) y Erika Duarte Ruiz (consultora, §7.1) |
| Áreas de práctica | Aduanero (2026-08-25: se retiró tributario — decisión del PO, despacho 100% aduanero) |
| Marca | **«Pedro.»** — no «ADUANA ELITE», que es lo que rotula la maqueta |

Nombres de archivo de las fotos:

| Archivo | Foto | Bloque |
|---|---|---|
| `pedro-hero.jpg` | Puerto, contenedores al atardecer | `hero` |
| `pedro-perfil.jpg` | Retrato de estudio, fondo neutro | `credenciales` |
| `pedro-documentos.jpg` | Revisión documental en escritorio | `proceso` |
| `pedro-comercio-exterior.jpg` | Oficina, globo terráqueo | `cta_final` |

### 7.1 Erika Duarte Ruiz — consultora, no abogada (2026-09-11)

El despacho pasa a ser dos personas. La distinción es la única parte de esto
que no admite matices:

**Erika Duarte Ruiz NO es abogada.** Es Profesional en Negocios
Internacionales con especialización en Derecho Aduanero y Comercio Exterior
—un posgrado abierto a no abogados, que no habilita para ejercer el derecho—
y ocho años en operaciones de comercio exterior. Su título es **«Consultora
Aduanera y de Comercio Exterior»**. Llamarla abogada en la página, en una
migración o en el prompt del bot no es una imprecisión de redacción: es una
afirmación sobre la habilitación profesional de una persona, publicada por un
despacho de abogados.

Qué hace: revisa el expediente técnico —clasificación arancelaria, valor en
aduana, régimen aplicado, documentos soporte, requisitos ante DIAN, ICA e
INVIMA—. **Su trabajo alimenta la defensa; no la firma ni la dirige.** La
asesoría paga se agenda con Pedro, siempre, y desde el diagnóstico el botón
sigue siendo uno solo.

Dónde vive esto, y por qué en cada sitio:

| Pieza | Qué hace |
|---|---|
| `landing_bloques.equipo` (migración 0041) | La sección pública. Contenido editable: el rol se pinta del campo, nunca de una constante de la plantilla |
| `plantillas/landing/bloques/equipo.php` | Va detrás de `credenciales`, y hay que añadirlo **también** a la lista de `pagina.php` — un bloque que no esté en esa lista no se pinta y no da error (§8) |
| `AdaptadorDespacho::reglasDeDominio()` | Las prohibiciones del bot. Capa **no editable** del prompt, por la misma razón que las tres reglas del §3 |
| `TitulosDelEquipoTest` | Recorre `plantillas/`, `db/migraciones/` y `src/` buscando que alguien la haya llamado abogada. Ignora los comentarios: explicar la regla exige poder escribir la palabra |
| `tests/golden/conversaciones.json` | Casos `equipo-01` a `equipo-05`. Quedan fuera del escaneo de arriba: su trabajo es contener esos patrones |

Lo que el despacho **no** vende, aunque ella sepa hacerlo: prevención,
auditoría de procesos y optimización de costos. Este canal atiende a quien ya
tiene un problema con la DIAN. La rama preventiva del diagnóstico sigue
saliendo por `fuera_alcance` (§4.5) hasta que el PO decida lo de abajo, y el
bot no la menciona nunca como servicio agendable (§7.2).

### 7.2 La revisión técnica y el interruptor `ofrece_bot` (2026-09-11)

Decisión del PO: la revisión técnica vale **$400.000 por ahora**, y «cada
valor se configura en el panel». La fila vive en `modalidades_asesoria`
(migración 0043) y se edita entera desde `/panel/tarifas`, como la otra.

Lo que no era obvio: **el catálogo del bot ES esa tabla.**
`AdaptadorDespacho::buscarItems()` lee `modalidades_asesoria WHERE activo = 1`,
así que sembrar la revisión técnica activa habría hecho que el bot empezara a
ofrecerla y a agendarla — con el bot encendido en producción, y contra sus
propias reglas de dominio, que dicen que la cita es con el abogado.

Y habría roto algo más callado: la red de `detalleItem()` que resuelve un id
irreconocible al único servicio activo. Existe por el fallo del 2026-08-22 —el
modelo mutiló el UUID al confirmar, la venta murió en «no está en el catálogo»
y la conversación acabó transferida— y **deja de aplicar en cuanto hay dos
filas activas**.

De ahí la columna `ofrece_bot`, que separa dos cosas que la tabla confundía:

| | `activo` | `ofrece_bot` |
|---|---|---|
| Asesoría jurídica virtual | 1 | 1 |
| Revisión técnica de operación | 1 | **0** |

El catálogo del bot pasa a ser `activo = 1 AND ofrece_bot = 1`: vuelve a tener
un solo servicio, la red sigue en pie, y la revisión técnica existe, se cobra
y se edita sin que el bot la nombre nunca. Lo defiende
`AdaptadorDespachoTest::elBotNoOfreceLasModalidadesQueNoLeCorresponden()`, que
comprueba los tres caminos de búsqueda —por id, por nombre y por la red— y no
solo el listado.

La casilla está en `/panel/tarifas`, en su propia fila y con su explicación:
puesta al lado de «Activa» parece otra casilla de visibilidad de la página, y
es la única de esa pantalla que cambia lo que el bot le dice a un cliente.

### Sobre el precio

$400.000 por una hora es un ticket alto para un lead frío, y eso ordena el
copy: el contacto no paga por curiosidad, paga porque quedó convencido de que
Pedro sabe algo que él no. Por eso la página demuestra dominio del vocabulario
técnico antes de nombrar el precio, nunca al revés.

### Lo que sigue pendiente y no es configuración

- [ ] Revisión del copy de landing y diagnóstico bajo el marco de publicidad
      del abogado (Ley 1123 de 2007). **Incluye el bloque `equipo` y la
      mención del expediente técnico en el resultado** (§4.5, §7.1): son copy
      nuevo del 2026-09-11 y ninguno lo ha visto Pedro todavía.
- [ ] **La rama preventiva del diagnóstico.** Hoy sale por `fuera_alcance` —
      se le dice a la persona que no hay nada que venderle— y con Erika deja
      de ser cierto: revisión documental y de procesos no es ejercicio del
      derecho, y es exactamente lo que esa opción pedía. La modalidad ya
      existe y ya tiene precio (§7.2); lo que falta son dos decisiones que no
      son de configuración:
      1. **Si Erika factura al despacho o directamente.** Cambia quién emite
         la factura y a quién llega el pago en Wompi.
      2. **Si esa revisión puede derivar en caso jurídico.** Si ella detecta
         algo que ya es un problema, el camino natural es Pedro — y eso es
         una segunda venta, no un favor.
      Y una advertencia sobre el precio: a $400.000 la revisión técnica vale
      lo mismo que una hora del abogado defendiendo un caso abierto. Mientras
      no haya camino público hacia ella da igual; el día que la rama
      preventiva se encienda, conviene revisarlo — nadie paga por prevenir lo
      mismo que por defenderse.
- [ ] **Política de tratamiento de datos — ya no es hipotética.** El motor de
      WhatsApp (§0.2) persiste teléfono, nombre, correo y motivo de consulta.
      Es requisito para poner `wa_config.activo = 1`.
- [ ] Revisión de Pedro del prompt del bot (`wa_agentes` y
      `AdaptadorDespacho::reglasDeDominio()`) bajo la Ley 1123.
- [x] Pantallas del panel para el motor (2026-08-22): `/panel/whatsapp`
      (conexión, QR, token, IA, cobro, horario, agente, Google Calendar),
      `/panel/whatsapp/citas` y `/panel/whatsapp/conversaciones`.
      `bin/wa-configurar.php` queda como vía alternativa por consola.
      Pruebas en `PanelWhatsappTest`.
- [ ] Credenciales reales: Evolution API, proveedor de IA, Wompi y el
      cliente OAuth de Google (pasos en `bin/wa-configurar.php`).

---

## 8. Cómo se trabaja aquí

- **Migraciones:** `php bin/migrar.php`. Aditivas siempre (ADR-013).
- **CSS:** `npm run build:landing`. El CSS va **incrustado** en el HTML, así
  que un build tiene que invalidar la caché de páginas igual que editar un
  texto — de eso se encarga `CachePagina` mirando el mtime del archivo.
- **Pruebas:** `vendor/bin/phpunit`. Más de 500, y se espera que estén todas
  en verde. Las de `tests/Unidad` no tocan MySQL y corren en segundos
  (`--testsuite unidad`); las de integración recrean `pedro_pruebas` desde
  `db/migraciones/` y tardan. **Nunca dos corridas a la vez contra la misma
  base:** `CasoBaseBd::recrear()` la borra y la vuelve a crear, así que dos
  procesos simultáneos se destruyen mutuamente y dejan un rastro de «table
  doesn't exist» que no tiene nada que ver con el código.
- **El conjunto dorado no tiene runner.** `tests/golden/conversaciones.json`
  se mantiene al día —hoy 33 casos— pero `composer dorado` apunta a
  `bin/correr-dorado.php`, que se fue con el gate dorado (migración 0010).
  Se corre contra el LLM real, así que hace falta antes de encender el bot;
  mientras tanto, lo que el archivo describe **no está comprobado por nada**.
- **Auditoría de la landing:** `node bin/auditar-landing.mjs <url>` contra el
  servidor de desarrollo (`php -S 127.0.0.1:8000 bin/servidor-dev.php`).
- **Capturas:** `node bin/capturar.mjs <url> <destino>`.

### Trampas conocidas, todas vividas

- **La caché de páginas no mira las plantillas.** Invalida por centinela y por
  mtime del CSS, no por mtime de los `.php`. Al tocar una plantilla hay que
  borrar `storage/cache/*.html` a mano o no se ve el cambio.
- **Un bloque de la landing que no esté en `landing_bloques` no se pinta, y no
  da error:** el bucle de `pagina.php` hace `continue` en silencio. Ya se
  perdió así una sección entera durante días.
- **Las clases `.titular*` traen tamaño por defecto** justamente porque
  olvidar la utilidad de tamaño no se ve «un poco más pequeño»: derrumba el
  titular a 1 rem y la sección pierde el encabezado sin que nada falle.
