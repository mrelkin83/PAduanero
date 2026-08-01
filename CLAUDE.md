# CLAUDE.md — Plataforma Digital Pedro Abogado Aduanero

> Documento maestro. Toda sesión de Claude Code sobre este repositorio debe leerlo
> completo antes de escribir código. Los cambios de esquema, dependencias, API o
> seguridad requieren aprobación explícita del Product Owner.

**Versión:** 1.2 · **Fecha:** 2026-07-31 · **Dominio:** pedroabogadoaduanero.com
**Infraestructura:** VPS propio · **Zona horaria:** America/Bogota

**Documentos que componen la especificación** (leer los cuatro antes de codificar):

| Archivo | Contenido |
|---|---|
| `CLAUDE.md` | Este documento. Decisiones, arquitectura, reglas inviolables |
| `docs/CONTRATOS.md` | Firmas exactas de cada módulo. **Normativo** |
| `docs/PANEL_ADMIN.md` | Panel administrativo, roles, frontera con Chatwoot |
| `docs/PLAN_BUILD.md` | Orden de construcción y criterios de cierre por etapa |
| `db/schema.sql` | Esquema del motor |
| `db/schema_admin.sql` | Esquema del panel y configuración |
| `db/seeds.sql` | Semillas con los datos reales del negocio |
| `docs/PRUEBAS.md` | Qué se prueba y con qué severidad |
| `docs/RUNBOOK.md` | Operación, incidentes y despliegue |
| `docs/RESPALDOS.md` | Respaldos, cifrado y recuperación |
| `README.md` | Índice y arranque |

---

## 1. Decisión de stack — qué se construye y qué NO

La pregunta de partida fue si conviene usar Chatwoot, OpenClaw, o fusionarlos.
Respuesta corta: **Chatwoot sí, OpenClaw no, motor propio sí pero delgado.**

### 1.1 Chatwoot — ADOPTAR como bandeja omnicanal

Chatwoot resuelve exactamente el requisito de "centralizar conversaciones": bandeja
compartida, WhatsApp, Instagram, Facebook Messenger, widget web para la landing y
correo en un solo hilo por contacto, con historial, etiquetas, asignación y notas
internas. Ya existe integración nativa entre Evolution API y Chatwoot, así que no
hay que construir el puente.

- Licencia: MIT para el núcleo. El directorio `enterprise/` va bajo licencia
  comercial separada y requiere suscripción en producción — **no usarlo**.
  En la práctica esto se traduce en una sola cosa: la imagen de Docker debe ser
  la **community edition**, con etiqueta terminada en `-ce`. La etiqueta por
  defecto (`chatwoot/chatwoot:latest`) incluye el código `enterprise/`.
- Requisitos: Linux, PostgreSQL, Redis, ≥2 vCPU y ≥4 GB RAM. Presupuestar 4 GB solo
  para Chatwoot; el motor y Evolution van aparte.
- Captain AI (la capa de IA propia de Chatwoot) se mide en créditos de pago:
  **no se usa**. La IA la pone el motor propio.

Reconstruir una bandeja omnicanal desde cero sería tirar meses de trabajo a un
problema ya resuelto. El diferencial de este proyecto está en el criterio jurídico
del embudo, no en pintar una lista de conversaciones.

### 1.2 OpenClaw — DESCARTAR para este proyecto

OpenClaw (antes Clawdbot/Moltbot) es un asistente personal autohospedado que conecta
un agente LLM a WhatsApp, Telegram, Slack y otros, con capacidad de **ejecutar
comandos de shell y manipular archivos en el host**. Es una herramienta excelente
para lo que fue diseñada: automatización personal del propio dueño de la máquina.

No sirve aquí, por tres razones:

1. **Modelo de amenaza incompatible.** En julio de 2026 se publicaron tres
   vulnerabilidades de severidad alta que permitían encadenar un mensaje de WhatsApp
   hasta ejecución de código en el host, saltándose el saneamiento de variables de
   entorno y el aislamiento Docker. Están parcheadas (2026.6.6+), pero el patrón es
   estructural: un agente con shell expuesto a mensajes de desconocidos es una
   superficie que un despacho de abogados no debe tener. Aquí los mensajes vienen
   de público frío captado en Facebook Ads.
2. **No es un CRM.** No tiene bandeja multiagente, asignación, etiquetas ni SLA.
3. **Secreto profesional.** Un agente con acceso al sistema de archivos y a los
   expedientes es un riesgo de fuga que no compensa la comodidad.

Si en el futuro Pedro quiere un asistente para su uso interno (consultar su propia
agenda desde el celular), OpenClaw es candidato razonable **en una máquina separada,
sin acceso a la base de clientes y con `allowFrom` limitado a su número**.

### 1.3 Evolution API — ADOPTAR

Evolution API es la pasarela de WhatsApp. Código bajo Apache 2.0.

**Decisión (2026-07-31, cierre de la acción previa a la Etapa 2): se adopta,
pineada en `evoapicloud/evolution-api:v2.3.7`.**

Al ir a fijar la versión apareció el dato que reordena todo el análisis: **la
2.4.0 todavía no existe como estable.** Solo hay `2.4.0-rc1` y `2.4.0-rc2`,
marcadas por el propio proyecto como «validation only; do not deploy to
production». La última estable es **v2.3.7** (diciembre de 2025), que es
**anterior a la 2.4.0 y por tanto no pide activación de licencia en absoluto**.

Consecuencia práctica: en la Etapa 2 el problema de la activación **no
existe**. La maquinaria para tratarlo se deja montada igualmente —el
`EVOLUTION_OPERATOR_EMAIL` en la plantilla, la distinción del 503 en
`bin/salud.sh` y en `bin/verificar-canales.php`, el procedimiento en
`docs/DESPLIEGUE_CANALES.md` §2.1— porque el día que la 2.4.0 salga estable y
haya que subir, todo eso hace falta y nadie se acordará de escribirlo.

**Lo que sí hay que vigilar es lo contrario: la antigüedad.** Baileys sigue el
protocolo de WhatsApp, que cambia sin avisar, y una versión que envejece
termina perdiendo la conexión sin arreglo posible salvo actualizar. v2.3.7
lleva ya unos meses. **Revisar cada mes si la 2.4.0 salió estable** y planear
la subida en ventana acordada — momento en el que la activación pasa a ser
relevante y la mitigación de abajo, obligatoria.

Se descartó `evolution-go`: exige activación **y no tiene integración nativa
con Chatwoot**, que es justamente la razón por la que se eligió Evolution
(§1.1). Sin ese puente habría que escribirlo, y eso es meses de trabajo para
volver al punto de partida.

Sobre la licencia en sí, verificado contra la documentación de Evolution
Foundation por si se llega a la 2.4.0:

| Punto | Estado |
|---|---|
| Coste | Gratis. Sin límite de instancias, mensajes ni funciones |
| Licencia del código | Apache 2.0, y lo ya publicado bajo ella lo sigue estando |
| Kill switch remoto | **No existe** |
| Servidor de licencias caído | La instancia **sigue operando**; el cliente reintenta con backoff |
| Operación sin red | Tras la activación inicial, los heartbeats fallan en silencio |
| Datos enviados | Correo, teléfono, UUID de instancia, versión, conteos agregados, IP. **Nunca** mensajes, contactos ni credenciales |

Eso corrige una suposición de la v1.0 de este documento: los heartbeats **no**
son una dependencia de red en cada arranque, y una caída del servidor de
licenciamiento no tumba el WhatsApp.

**El riesgo real, cuando se llegue a la 2.4.0:** la activación se pide una sola
vez por instancia, pero al **recrear el contenedor** (actualización de imagen,
reinicio del orquestador, volumen perdido) la API queda devolviendo 503 hasta
que alguien entre al Manager a activarla a mano. Mitigación obligatoria a
partir de esa versión, en este orden:

1. Volumen persistente para el estado de la instancia. Si sobrevive, no se
   vuelve a pedir activación.
2. Etiqueta de imagen **fija**, nunca `latest`: una actualización desatendida
   es la forma más probable de recrear el contenedor sin supervisión.
3. `EVOLUTION_OPERATOR_EMAIL` para la autoactivación headless. Requiere que
   ese correo se haya registrado **una vez** manualmente; si falla, cae de
   vuelta al flujo manual sin abortar.
4. `bin/salud.sh` distingue el estado «pendiente de activación» del resto de
   fallos, porque el remedio es distinto.

### Obligación de atribución — verificada en el LICENSE de la v2.3.7

El `LICENSE` del tag `2.3.7` (Apache 2.0 **más condiciones adicionales**) trae
esta cláusula, que **nos obliga**:

> **b. Usage Notification Requirement:** If Evolution API is used as part of
> any project, including closed-source systems (e.g., proprietary software),
> the user is required to display a clear notification within the system that
> Evolution API is being utilized. This notification should be visible to
> system administrators and accessible from the system's documentation or
> settings page.

Se cumple en el **pie del panel administrativo y en su página de Configuración**
(Etapa 3), que es literalmente lo que pide la letra: visible para los
administradores del sistema, accesible desde la página de ajustes. **No va en
la landing**: ahí no hay administradores, y sería publicidad de un proveedor
en la página de un abogado.

La cláusula **1.a** (no quitar logos ni avisos de copyright) **no aplica**:
dice expresamente que es *«inapplicable to uses of Evolution API that do not
involve its frontend components»*, y aquí se consume la API sin embeber el
Manager. Si alguna vez se embebe o se rebrandea el Manager, vuelve a aplicar.

En el tag `2.3.7` no existen `NOTICE` ni `TRADEMARKS.md` — se añadieron
después, con el cambio a Evolution Foundation. La imagen de Docker tampoco
incluye archivos de licencia propios.

Alternativas 100 % libres si algún día la licencia deja de convencer:
`evolution-api-lite` (solo conectividad, sin integración nativa con Chatwoot —
habría que escribir el puente), o construir directo sobre `whatsmeow` (Go) o
Baileys (Node). No se toman hoy: todas cuestan el puente con Chatwoot, y en
v2.3.7 ni siquiera hay licencia que esquivar.

**Riesgo transversal de WhatsApp Web:** Baileys/whatsmeow no son API oficiales de
Meta. Un número puede ser bloqueado. Para un negocio cuyo centro es WhatsApp, esto
es riesgo existencial. Mitigación obligatoria: número dedicado (nunca el personal de
Pedro), volumen de envío conservador, cero mensajería masiva no solicitada, y
**plan de migración documentado a WhatsApp Business Cloud API oficial** para cuando
el volumen lo justifique. Evolution soporta ambos backends, así que la migración es
de configuración, no de reescritura.

### 1.4 Motor propio — CONSTRUIR

Es la única pieza que no existe hecha: la lógica de negocio jurídico. Debe ser
**delgado**: no reimplementa bandeja, no reimplementa pasarela. Solo decide.

---

## 2. Arquitectura

```
  Landing (pedroabogadoaduanero.com)
  Meta Ads / Google Ads / SEO
              │
              ▼  (click-to-WhatsApp con parámetro UTM)
   ┌──────────────────────────┐
   │  Evolution API           │  ← WhatsApp (número dedicado)
   │  (VPS, Docker)           │
   └────────────┬─────────────┘
                │ integración nativa
                ▼
   ┌──────────────────────────┐
   │  CHATWOOT                │  ← Instagram DM, Messenger,
   │  bandeja omnicanal       │     widget web, correo
   │  + historial + etiquetas │
   └────────────┬─────────────┘
     webhook    │    ▲  API REST (respuestas del bot)
  message_created│    │
                ▼    │
   ┌──────────────────────────┐        ┌────────────────────┐
   │  MOTOR (Node 20)         │◄──────►│ PostgreSQL + pgvector│
   │  · máquina de estados    │        │ casos · consultas   │
   │  · triage y scoring      │        │ KB jurídica (RAG)   │
   │  · RAG jurídico          │        └────────────────────┘
   │  · agenda y cobro        │        ┌────────────────────┐
   │  · escalamiento          │◄──────►│ Redis / BullMQ     │
   └────┬──────────────┬──────┘        │ outbox + colas     │
        │              │               └────────────────────┘
        ▼              ▼
   Pasarela pago   Evolution (directo, solo alertas internas a Pedro)
   (Wompi/Bold)
```

**ADR-001 — El bot responde a través de Chatwoot, no de Evolution.**
Todo mensaje saliente del motor va por la API de Chatwoot. Así queda en el hilo,
Pedro ve exactamente lo que el bot dijo, y el handoff es instantáneo. Única
excepción: alertas internas a Pedro, que salen directo por Evolution a su número
personal para no contaminar la bandeja de clientes.

**ADR-002 — Bases de datos separadas.** Chatwoot tiene su Postgres; el motor el
suyo. Nunca escribir en las tablas de Chatwoot por SQL. El vínculo es
`chatwoot_conv_id` y `chatwoot_contact_id`.

**ADR-003 — Un solo profesional.** El esquema no es multi-tenant. Se eliminó
`negocio_id` del motor de referencia. Si más adelante entran abogados asociados, se
introduce `profesional_id` en `consultas` y `horarios`, no un tenant.

**ADR-005 — Stack PHP 8.2 + MySQL 8 (RESUELTO, 2026-07-31).** Motor y panel
comparten runtime, base de datos y capa de repositorios. Es el stack del PO, lo
que importa más que cualquier preferencia técnica abstracta: el sistema lo va a
mantener él. Consecuencias que hay que asumir de frente:

- **No hay pgvector.** El RAG se resuelve con embeddings en columna JSON, prefiltro
  FULLTEXT y coseno calculado en PHP. A escala de 130 escenarios (unos 2.000
  fragmentos) esto corre en milisegundos. Si algún día creciera un orden de
  magnitud, la salida es Qdrant en Docker, no reescribir el motor.
- **No hay índices únicos parciales.** Se emulan con columnas generadas STORED que
  valen NULL cuando la condición no aplica. Es lo que impide la doble reserva de
  cupo: no se puede eliminar. Ver `db/schema.sql`.
- **El código de error cambia:** MySQL 1062, no Postgres 23505.
- Chatwoot y Evolution siguen en Docker con sus propios runtimes y su propio
  Postgres. Son cajas negras; no comparten base con nosotros.

**ADR-004 — Patrón outbox.** Ningún I/O externo (mensajes, correos, webhooks) dentro
de una transacción de base de datos. Se encola en `eventos_outbox` y un worker lo
despacha con reintentos.

---

## 3. El cambio de modelo: de agendamiento a embudo jurídico

El `index.js` de referencia optimiza para *reservar un cupo gratis lo más rápido
posible*. Aquí el objetivo es distinto y eso reordena todo el flujo:

| Motor de referencia | Motor jurídico |
|---|---|
| Meta: agendar | Meta: calificar, cobrar, agendar |
| Cita gratuita, confirmación inmediata | Asesoría paga: `reservada` → link → `pagada` |
| Sin gate de datos personales | Habeas data obligatorio antes de persistir |
| Un solo estado (`IA_ACTIVA`) | Máquina de estados de 10 nodos |
| Bot habla de servicios y precios | Bot con prohibiciones jurídicas duras |
| Escalamiento inexistente | Escalamiento de primera clase con kill switch |
| Sin scoring | Puntaje 0–100 para priorizar la bandeja |

### 3.1 Máquina de estados

```
nuevo → consentimiento → triage → calificacion → propuesta_enviada
          │                 │           │              │
          │                 │           │              ▼
          │                 │           │        pendiente_pago ──► agendado
          │                 │           │              │  (webhook de pago)
          ▼                 ▼           ▼              ▼
    (no acepta)        fuera_alcance  humano ◄─────────┘
        cerrado                        (IA apagada)
```

Transición a `humano` desde **cualquier** estado, por: señal crítica detectada,
petición expresa, caso sensible, inconformidad, tope de turnos o error técnico.

### 3.2 Puntaje de lead (0–100, interno, nunca visible al contacto)

| Factor | Puntos |
|---|---|
| Existe acto administrativo (acta/requerimiento/resolución) | +25 |
| Urgencia crítica / alta / media | +25 / +15 / +6 |
| Valor ≥ 500 M / ≥ 100 M / ≥ 20 M / > 0 COP | +30 / +22 / +14 / +6 |
| Persona jurídica | +15 |
| Entidad DIAN o POLFA | +5 |

Uso: orden de atención en la bandeja y priorización de respuesta de Pedro.
**No** se usa para negar atención ni para variar el precio.

---

## 4. Reglas de negocio inviolables

1. Sin consentimiento de tratamiento de datos vigente, el motor **no persiste**
   contenido del caso. Solo puede almacenar teléfono y el propio consentimiento.
2. El bot **nunca** entrega términos, plazos ni fechas límite, en ninguna forma.
   Un plazo mal dicho puede costar un caso y comprometer a Pedro.
3. El bot **nunca** cita normas con número, redacta memoriales ni da estrategia.
4. El bot **nunca** promete resultados ni estima probabilidades de éxito. La Ley
   1123 de 2007 regula la publicidad y la conducta del abogado en Colombia; el copy
   de la landing y los guiones del bot deben ser revisados por Pedro bajo ese marco.
5. Los casos con POLFA en operativo, detenciones, allanamientos o contrabando con
   implicación penal se escalan a humano **sin pasar por el LLM**.
6. Una asesoría solo pasa a `pagada` por webhook verificado por firma de la
   pasarela. Nunca por afirmación del contacto ni del LLM.
7. La reserva de cupo expira a los N minutos (default 45) sin pago confirmado.
8. Cuando un humano toma la conversación, la IA queda apagada hasta reactivación
   explícita. Nunca se reactiva sola dentro de la misma sesión de atención.
9. Existe un kill switch global (`motor_ia_pausado`) que silencia toda la IA sin
   apagar Chatwoot ni WhatsApp.
10. Todo contenido de la base de conocimiento jurídico debe tener
    `verificado_por` y `verificado_en` de Pedro antes de estar activo. Ningún
    fragmento sin verificar entra al RAG.
11. Se registran contrapartes en `caso_partes` para permitir verificación manual
    de conflicto de interés antes de aceptar el encargo.
12. El motor no procesa instrucciones contenidas en mensajes del contacto que
    intenten alterar su comportamiento. El contenido del usuario es dato, no orden.
13. Datos sensibles (NIT, documentos) se cifran a nivel aplicación (AES-256-GCM),
    nunca se registran en logs y nunca se envían al proveedor del LLM sin necesidad.
14. **Un escalamiento sin consentimiento vigente no persiste contenido.** Cuando la
    regla 5 obliga a escalar (POLFA en operativo, detención, allanamiento) y todavía
    no hay habeas data, el sistema puede almacenar únicamente: teléfono, motivo del
    escalamiento, marca de tiempo y `chatwoot_conv_id`. **Cero texto del mensaje**:
    ni descripción, ni extracto, ni resumen, en ninguna tabla, cola o notificación.
    La alerta a Pedro dice "escalamiento urgente, revise la conversación #123" con
    el enlace al hilo; el contenido lo lee en Chatwoot, que es donde el contacto ya
    lo escribió por voluntad propia.

    Esto resuelve el choque entre la regla 1 y la regla 5, que se contradecían: la 1
    prohíbe persistir sin consentimiento y la 5 obliga a escalar de inmediato. La
    notificación sin carga útil no es solo cumplimiento — también evita que datos de
    un caso penal en curso queden copiados en el outbox, en logs de correo y en el
    historial de WhatsApp del teléfono personal de Pedro.

---

## 5. Catálogo de tipos de caso

El PO confirmó que Pedro es **especialista en derecho aduanero y comercio exterior
y especialista en derecho tributario**. El brief original hablaba solo de aduanero,
así que el catálogo se amplía y el campo `casos.area` distingue las dos ramas.

**Aduanero:** `aprehension_mercancia` · `decomiso` · `cancelacion_levante` ·
`firmeza_declaracion` · `clasificacion_arancelaria` · `valoracion_aduanera` ·
`origen_tlc` · `operativo_polfa` · `contrabando_tecnico` · `deposito_habilitado` ·
`transporte_transito` · `devolucion_mercancia` · `agencia_aduanas_sancion`

**Tributario:** `requerimiento_especial` · `liquidacion_oficial_revision` ·
`fiscalizacion_renta` · `fiscalizacion_iva` · `sancion_tributaria` ·
`devolucion_compensacion` · `retencion_fuente` · `precios_transferencia`

**Comunes a ambas:** `requerimiento_ordinario` · `proceso_sancionatorio` ·
`recurso_reconsideracion` · `nulidad_restablecimiento` · `fiscalizacion` · `otro`

Catálogo cerrado: el saneador de acciones fuerza `otro` ante cualquier valor no
listado. **Esta lista es la normativa.** El array `TIPOS_CASO` de `motor/index.js`
tiene 21 valores y ninguno tributario — está desactualizado y se corrige contra
esta sección cuando se traduzca a PHP en la Etapa 4, no antes.

**Pendiente de confirmación de Pedro:** que la lista tributaria refleje
lo que efectivamente quiere atender. Un especialista en tributario puede no querer
precios de transferencia, por ejemplo.

Consecuencia en el motor: el handler `FUERA_DE_ALCANCE` decía "el despacho se
dedica exclusivamente a derecho aduanero". Hay que corregirlo — con esa redacción
estaría rechazando clientes tributarios, que son negocio.

---

## 6. Base de conocimiento (RAG)

Los 130+ escenarios jurídicos **no van en el system prompt**. Razones: costo por
token en cada turno, dilución de atención del modelo y, sobre todo, imposibilidad
de auditar qué fragmento se usó en cada respuesta.

Diseño:
- `kb_documentos` + `kb_chunks` en MySQL, embeddings de 1536 dimensiones guardados
  en columna `JSON` con la norma del vector precalculada en `embedding_norma`.
  **No hay `pgvector`**: lo cerró el ADR-005. La similitud coseno se calcula en PHP.
- Búsqueda en tres pasos: prefiltro por `area` y `tipo_caso`, prefiltro léxico con
  `MATCH … AGAINST` sobre el índice FULLTEXT, y coseno en PHP sobre los candidatos.
  Máximo 4 fragmentos. Con ~2.000 chunks son milisegundos.
- Si esto creciera un orden de magnitud, la salida es Qdrant en Docker, no
  reescribir el motor.
- Marco normativo base: Decreto 1165 de 2019 y sus modificaciones, resoluciones
  reglamentarias de la DIAN, conceptos DIAN, jurisprudencia del Consejo de Estado.
  **La vigencia de cada norma la valida Pedro, no el desarrollador.**
- Cada fragmento entra al RAG solo con revisión humana registrada.

---

## 7. Defectos detectados en el `index.js` de referencia

Corregidos en la adaptación. Se documentan porque el código original quizá siga en
producción en el otro proyecto:

1. **Objeto malformado en `procesarCrearCita`.** El bloque `hayConflicto` construye
   `mensaje: mensajeMotivo({...}) ? '...' : '...'`, un ternario sobre el resultado de
   la función, con un comentario `// legacy` en medio. Como `mensajeMotivo` siempre
   devuelve string no vacío, la rama falsa es inalcanzable y se pierde el mensaje
   correcto. **Revisar en producción.**
2. **Regex frágil para extraer JSON:** `/\{[\s\S]*?"accion"[\s\S]*?\}/` corta en la
   primera llave de cierre y falla con objetos anidados o llaves dentro de strings.
   Sustituido por parser de llaves balanceadas.
3. **`getContextoCliente` trae todas las citas del negocio** y filtra en memoria.
   Escala mal y es una fuga potencial. Sustituido por query filtrada.
4. **Sin validación de esquema de la acción del LLM:** los campos del JSON llegan
   directo a la capa de datos. Añadida whitelist por acción.
5. **Sin manejo de ráfagas.** En WhatsApp la gente manda 4 mensajes seguidos y se
   disparan 4 llamadas al LLM que se pisan. Añadido buffer con ventana.
6. **Sin control de concurrencia en la reserva.** Dos mensajes simultáneos pueden
   crear cita doble. Añadida validación de solapamiento bajo `SELECT … FOR UPDATE`
   dentro de la transacción, con el índice único sobre `slot_unico` como segunda
   línea de defensa y captura del error **1062** de MySQL (no el `23505` de
   Postgres: ver ADR-005).
7. **Sin tope de costo por conversación.** Un contacto puede quemar presupuesto de
   LLM indefinidamente. Añadido `max_turnos_ia`.
8. **Historial truncado a 10 turnos sin resumen.** En un caso jurídico se pierde
   contexto. Añadido campo `resumen_largo` para compactación.
9. **Prompt injection sin mitigar.** Añadidas instrucciones explícitas y whitelist.
10. **`require` dentro de funciones** (`require('../../config/database')` en cuatro
    sitios). Mover a la cabecera.

---

## 8. Roadmap

El detalle operativo, con criterios de cierre verificables por etapa, vive en
`docs/PLAN_BUILD.md`. Resumen:

| Etapa | Alcance |
|---|---|
| 0 | Cimientos: migraciones, config, cifrado de credenciales |
| 1 | Landing pública con instrumentación de UTMs |
| 2 | Centralización: Evolution + Chatwoot, cuatro canales, sin IA |
| 3 | Panel: autenticación, roles, configuración, tarifas, pasarela |
| 4 | Motor en **modo sombra** (nota privada, sin envío) |
| 5 | Cobro y agenda con webhook verificado |
| 6 | Envío automático |
| 7 | Base de conocimiento jurídico verificada |
| 8 | Contenido, métricas y campañas |

**Regla de despliegue:** la IA arranca en modo sombra. Genera la respuesta, la deja
como **nota privada** en Chatwoot, y Pedro decide si la envía. Solo después de dos
semanas limpias se habilita el envío automático.

---

## 9. Parámetros de decisión

Lo que en la v1.0 era un checklist de pendientes ahora vive en la tabla
`configuraciones` y se edita desde el panel. Añadir un parámetro nuevo es un
`INSERT`, no un cambio de código. Ver `db/schema_admin.sql` §8.

Siguen bloqueando el paso a producción, pero como **valores por definir**, no como
decisiones de arquitectura:

- ~~Precio de cada modalidad~~ — **resuelto**: `modalidades_asesoria.precio_cop`
  está sembrado en `400000` (pesos). Ver §12 sobre las unidades.
- Pasarela activa y sus credenciales (`pasarela_activa` + tabla `credenciales`).
- Política de reembolso y ventana de cancelación sin costo.
- Proveedor y modelo de LLM, y país del servidor (`proveedores_ia.pais_servidor`).
- Períodos de retención de conversaciones y de casos descartados.
- Texto del aviso de habeas data (`texto_aviso_habeas_data`), aprobado por Pedro.
- Número de WhatsApp dedicado, distinto del personal.

Dos que **no** son configuración y siguen siendo trabajo humano:

- Redacción y verificación normativa de los 130+ escenarios.
- Revisión del copy de landing y de los prompts bajo el marco de publicidad del
  abogado (Ley 1123 de 2007).

---

## 10. Panel administrativo

Especificación completa en `docs/PANEL_ADMIN.md`. Lo esencial que no puede
perderse de vista:

**ADR-006 — El panel no reimplementa Chatwoot.** Conversaciones, agentes,
etiquetas y notas viven en Chatwoot. El panel administra configuración, tarifas,
credenciales, IA, conocimiento, contenido y métricas. El puente es un enlace
directo al hilo desde la ficha del caso.

**ADR-007 — Separación de llaves y responsabilidad.** El `super_admin` (perfil
técnico) tiene las credenciales pero **no** aprueba prompts, ni verifica normas,
ni publica contenido. El `abogado` aprueba, verifica y publica, pero **no** ve
credenciales. Si el bot dice una barbaridad jurídica, la firma que la autorizó
debe ser la del abogado.

**ADR-008 — Prompts versionados con aprobación.** Todo prompt nace inactivo y
requiere aprobación registrada del abogado. El motor guarda en cada conversación
qué versión usó, para poder reconstruir después qué instrucciones tenía el bot en
una fecha dada.

---

## 11. Decisiones del Product Owner (2026-07-31)

Cerradas. Ya están sembradas en `db/seeds.sql`, no hay que preguntarlas de nuevo.

| Punto | Valor |
|---|---|
| Stack | PHP 8.2+ · MySQL 8 · TailwindCSS · JS vanilla · `index.php` en la raíz |
| Modalidad de asesoría | Virtual, 60 minutos |
| Precio | **$400.000 COP** (`40000000` centavos para la pasarela) |
| WhatsApp del negocio | `573159923676` |
| Imágenes | Ruta de disco `public/img/` · ruta URL `/img` (ver §12) |
| Perfil | Especialista en Derecho Tributario · Especialista en Derecho Aduanero y Comercio Exterior · más de 15 años de experiencia |
| Áreas de práctica | Aduanero **y** tributario |
| `motor/index.js` | Referencia de la Etapa 4. **No se entrega antes** |

### Notas sobre el precio

$400.000 por una hora es un ticket alto para un lead frío de WhatsApp, y eso
reordena dos cosas:

1. **El embudo tiene que ganárselo.** A ese precio el contacto no paga por
   curiosidad: paga porque quedó convencido de que Pedro sabe algo que él no. Por
   eso el bot debe demostrar dominio del vocabulario técnico antes de proponer la
   asesoría, nunca al revés. Proponer el pago en el segundo mensaje mata la venta.
2. **El medio de pago importa.** PSE y transferencia bancaria tienen mucha menos
   fricción que tarjeta para montos así en Colombia. Ver
   `metodos_pago_habilitados` en las semillas.

La ventana de reserva de 45 minutos puede quedar corta a este precio: mucha gente
necesita consultar con el socio o el contador antes de pagar $400.000. Si en la
Etapa 5 se ve caída alta entre reserva y pago, subirla a 24 horas desde el panel.

### Lo que sigue pendiente y no es configuración

- [ ] Inventario de los 130+ escenarios jurídicos (nunca llegó el archivo).
- [ ] Texto del aviso de habeas data y política de tratamiento de datos.
- [ ] Política de reembolso.
- [ ] Segundo número de WhatsApp, distinto del `573159923676`, para alertas internas.
- [ ] Confirmación del catálogo tributario (§5).
- [ ] Revisión del copy de landing bajo el marco de publicidad del abogado.
- [x] ~~Nombres reales de los archivos en `/img`~~ — resuelto en la Etapa 0 (§12).

---

## 12. Decisiones del Product Owner (Etapa 0)

Cerradas al arrancar la construcción. Son invariantes de implementación: cambiarlas
después obliga a migrar datos, no solo a editar código.

### 12.1 Unidades de dinero — ADR-010

**`modalidades_asesoria.precio_cop` y `consultas.precio_cop` van en PESOS enteros.**
La multiplicación por 100 ocurre **exclusivamente** en `Pagos::crearLink()`, y
`pagos.monto_centavos` es la única columna en centavos de todo el sistema.

La razón de fijarlo por escrito: un error de factor 100 en cualquiera de los dos
sentidos le cobra $40.000.000 a un cliente o le cobra $4.000 a Pedro. Hay una
prueba de nivel 1 que crea el link de la modalidad sembrada y exige
`monto_centavos = 40000000`.

### 12.2 Formato de los campos cifrados — ADR-011

Un solo formato para todo lo que se cifre, un solo camino de código:

```
v1 ‖ nonce(12) ‖ tag(16) ‖ ciphertext
```

Aplica a `credenciales.valor_cifrado`, `contactos.nit_cifrado` y
`usuarios.totp_secret_cifrado`. En consecuencia se **eliminan** las columnas
`nonce` y `tag` de `credenciales`: eran un segundo camino para lo mismo.

`key_version` se conserva y **no** es lo mismo que el byte de versión del blob:
`key_version` dice *qué clave maestra* cifró el dato y cambia con
`rotarClaveMaestra()`; el byte dice *qué layout* tiene el blob y cambia solo si
se altera el formato. Rotan por razones distintas y en momentos distintos.

### 12.3 Hash de teléfono — ADR-012

`contactos.telefono_hash = HMAC-SHA256(telefono_e164, PEPPER_TELEFONO)`.

`PEPPER_TELEFONO` es una variable de entorno propia, 32 bytes en base64, que
**nunca rota** y se respalda junto a la `MASTER_KEY` con las mismas tres copias
(`docs/RESPALDOS.md` §4).

No se deriva de `MASTER_KEY` a propósito: `rotarClaveMaestra()` puede re-cifrar
credenciales porque el cifrado es reversible, pero un hash no lo es. El día que se
rotara la clave maestra, todos los `telefono_hash` quedarían huérfanos y la
búsqueda por hash dejaría de funcionar **en silencio**, que es la peor forma de
fallar. Un SHA-256 pelado tampoco sirve: un número de 12 dígitos se rompe por
fuerza bruta en segundos.

### 12.4 Migraciones — ADR-013

Numeradas, idempotentes y siempre aditivas. Tabla `migraciones (version,
aplicada_en, hash)`. `bin/migrar.php` compara el hash del archivo con el
registrado y **aborta** si una migración ya aplicada cambió de contenido, en vez
de reaplicarla. Ningún `DROP COLUMN` en el mismo despliegue que deja de usar la
columna.

### 12.5 Radicado interno — ADR-014

Formato `PA-YYYY-NNNNNN` (p. ej. `PA-2026-000123`). Secuencial por año,
reiniciando cada enero. Se asigna **al crear el caso, dentro de la misma
transacción**, contra una tabla `secuencias (anio, ultimo)` con
`SELECT … FOR UPDATE`.

Nunca con `MAX(id)+1`: con dos mensajes concurrentes eso entrega el mismo
radicado dos veces y el `UNIQUE` de `casos.radicado_interno` hace fallar la
creación del caso en plena conversación.

### 12.6 Rutas de imágenes

No había contradicción entre los documentos, sino dos cosas distintas mal
explicadas:

| | Valor | Quién lo usa |
|---|---|---|
| Ruta de disco | `public/img/` | `respaldo.sh`, el despliegue |
| Ruta URL | `/img` | El HTML, `landing_ruta_imagenes` |

Nginx sirve la primera bajo la segunda. `landing_ruta_imagenes` sigue en `/img`
porque es lo que termina en el atributo `src`.

Nombres de archivo definidos por el PO:

| Archivo | Foto | Bloque |
|---|---|---|
| `pedro-hero.jpg` | Puerto, contenedores al atardecer | `hero` |
| `pedro-perfil.jpg` | Retrato de estudio, fondo neutro | `credenciales` |
| `pedro-documentos.jpg` | Revisión documental en escritorio | `proceso` |
| `pedro-comercio-exterior.jpg` | Oficina, globo terráqueo | `cta_final` |

### 12.7 Concurrencia en la reserva — ADR-015

`ConsultaRepo::reservar()` valida el solapamiento **real** dentro de una
transacción:

1. `SELECT … FOR UPDATE` sobre las consultas vivas de esa fecha.
2. Validar `(inicio_a < fin_b) AND (inicio_b < fin_a)`.
3. `INSERT`.

El índice único sobre `slot_unico` queda como **segunda** línea de defensa. Por sí
solo únicamente bloquea la coincidencia exacta de `hora_inicio`: bastaría con
crear desde el panel una modalidad de 30 minutos para que 14:00–15:00 y
14:30–15:30 convivan sin violarlo. La validación de rango es la primera línea.

### 12.8 Catálogo de modelos — ADR-016

**El catálogo de modelos se descubre solo; la adopción es manual.**

El PO pidió que la lista de modelos se mantenga sola: que salga Opus 6 y el
sistema se entere sin que nadie edite código. Eso lo hace
`bin/cron-sincronizar-modelos.php`, consultando el endpoint de modelos de cada
proveedor (`GET /v1/models` en Anthropic y en los compatibles con OpenAI,
`GET /api/tags` en Ollama).

Lo que **no** se hace solo es empezar a usarlos. Un modelo descubierto entra
`activo = 0`, `es_primario = 0`, `costos_verificados = 0`, y ahí espera.
Tres razones, en orden de peso:

1. **Coherencia con ADR-008.** Un prompt no puede cambiar sin aprobación
   registrada del abogado porque cambia lo que el bot dice. El modelo cambia lo
   que el bot dice igual o más. Un modelo que se asciende solo sería la única
   pieza del sistema capaz de alterar el comportamiento del bot sin dejar
   firma en `auditoria`.
2. **El precio no viene en el endpoint.** Ningún proveedor lo publica ahí.
   Anthropic devuelve identificador, nombre, ventanas y capacidades; no costo.
   Y `costo_entrada_usd_1m` es lo que alimenta el corte por
   `presupuesto_ia_mensual_usd`: un modelo a costo NULL hace que el
   presupuesto no se agote nunca. Un guardia que deja de guardar en silencio
   es peor que no tenerlo. Lo impone el CHECK `ck_modelo_primario_apto`.
3. **El conjunto dorado.** La Etapa 4 cierra con 30 conversaciones limpias.
   Cambiar el modelo por debajo las deja sin valor, y nadie se entera hasta
   que un cliente lee algo que no debía.

**Quién asciende — resuelto por el PO (2026-07-31).** Se crea
`ia.modelos.promover` y es **del abogado**, no del `super_admin`. Es la tercera
asimetría del ADR-007, junto a `ia.prompts.aprobar`, `kb.verificar` y
`contenido.publicar`:

| Rol | Puede |
|---|---|
| `super_admin` | Descubrir, configurar, verificar costos, probar conexión, activar y desactivar. Todo el trabajo técnico. **No puede promover.** |
| `abogado` | `ia.modelos.promover`. Firma que el bot va a hablar con otro modelo. |

La objeción previsible —Pedro no sabe evaluar un modelo— es correcta y no
importa: tampoco redacta el prompt que aprueba. Lo que firma no es la calidad
técnica, es que asume la responsabilidad profesional de lo que el bot diga a
partir de ese momento.

**El gate que hace la firma significativa.** Un modelo no puede ser primario de
`conversacion` sin que el conjunto dorado haya corrido **en verde contra ese
modelo**, y sin que la corrida siga vigente: se guarda en `modelos_ia` el
resultado, la fecha y el `id` del prompt que estaba activo, y si el prompt
cambia después, el verde caduca.

Así Pedro no aprueba «claude-opus-6»: aprueba un modelo que ya demostró no
soltar un plazo, no citar una norma numerada y no prometer un resultado. Y la
razón 3 de arriba se da la vuelta — el conjunto dorado deja de perder valor al
cambiar de modelo, porque cambiar de modelo obliga a recorrerlo.

La regla vive en `App\Servicios\GateDorado`, en un solo sitio, porque la usan
el panel al promover y el corredor del dorado al terminar. Las dos mitades que
caben en un CHECK están en `ck_modelo_primario_dorado`; la vigencia frente al
prompt cruza dos tablas y por eso no cabe.

Un modelo de `embeddings` no pasa por el gate: no le dice nada a nadie.
