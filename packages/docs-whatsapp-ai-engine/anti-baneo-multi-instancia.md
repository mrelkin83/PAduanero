# Anti-baneo y multi-instancia — diseño portable (adaptado a PAduanero)

> Cómo una plataforma comparte **un solo servidor Evolution API** entre uno o
> varios negocios, dándole a cada uno **su propia instancia de WhatsApp** con
> ciclo de vida independiente (vincular, estado, desvincular), y qué prácticas
> mantienen bajo el riesgo de baneo de los números.
>
> Guía portable del paquete `whatsapp-engine`. La referencia multi-tenant
> completa vive en el mismo paquete dentro de Control BarMax
> (`MULTI_INSTANCIA_ANTI_BANEO.md`); **este documento la adapta al motor tal
> como corre en PAduanero** —un despacho, un solo número— y le suma las
> lecciones de operación reales de este proyecto (device_removed, versión de
> WhatsApp Web, vinculación por código). Verificado contra el código el
> 2026-09-05.

---

## 0. Qué aplica a PAduanero y qué no

PAduanero es **single-tenant**: un solo negocio (Pedro), una sola instancia de
WhatsApp (`pedro`), y el bot **solo responde** mensajes entrantes — no hace
tráfico proactivo (campañas, recordatorios masivos). Eso reordena el documento:

| Bloque | ¿Aplica hoy a PAduanero? |
|---|---|
| Aislamiento por instancia (§1) | Sí — es la base del canal, aunque haya una sola instancia |
| Ciclo de vida individual: vincular / estado / desvincular (§3) | Sí — es exactamente lo que hace el panel |
| Higiene entrante: `fromMe`, grupos, broadcast, LID (§5) | Sí — ya implementada |
| Operación de la sesión: QR, código, versión, vigilancia (§6) | Sí — el corazón de este proyecto |
| Ritmo anti-baneo del tráfico **proactivo** (§4) | **No hoy** — el motor solo responde. Documentado para cuando se necesite |
| Multi-tenant real (N negocios, offboarding) | **No** — un solo negocio. El código ya lo soporta si algún día se necesita |

La regla de oro sobrevive a la escala: **el tráfico reactivo (responder a quien
te escribió) es de bajo riesgo; lo que hace que baneen un número es el tráfico
proactivo y los reportes de usuarios.** Un bot que solo responde, como el de
PAduanero, parte de la posición más segura.

---

## 1. Aislamiento por instancia

- **Una instancia por negocio, con nombre propio.** El radio de explosión de un
  baneo es UN negocio. En PAduanero la instancia es `pedro`; en un SaaS serían
  `barlavallenata`, `latroja`, etc. Nunca compartir un número entre negocios.
- **El cliente HTTP solo conoce SU instancia.** `EvolutionClient` se construye
  desde la configuración del negocio y lleva el nombre de instancia en cada ruta
  (`/instance/logout/{instancia}`, `/message/sendText/{instancia}`). El
  aislamiento no es disciplina: el objeto ni siquiera puede nombrar otra
  instancia que la suya.
- **Dos modos con la misma pantalla**, ya soportados por `desdeConfig()`:
  - *Gestionado (SaaS):* la plataforma define `WA_EVOLUTION_URL`/`APIKEY` en el
    entorno; el negocio solo escribe su instancia. En PAduanero, como Evolution
    corre en el mismo VPS, la URL por defecto es `http://127.0.0.1:8080` y el
    negocio solo pega la API Key.
  - *Manual:* si esas variables están vacías, la pantalla pide URL + instancia +
    API Key.

---

## 2. Modelo de datos (una fila por negocio, tabla `wa_config`)

```
evolution_url        VARCHAR NULL   -- vacío = usar el default de plataforma (entorno)
evolution_instancia  VARCHAR        -- nombre único de la instancia ('pedro')
evolution_apikey     TEXT NULL      -- CIFRADA (ADR-011); vacía = default de plataforma
activo               TINYINT(1)     -- recibir ≠ atender: apagado, se descarta el mensaje
```

- La API key se guarda **cifrada** con la clave del proyecto; en modo gestionado
  puede no guardarse (se usa la de plataforma desde el entorno).
- El estado de conexión NO se confía a una columna: se pregunta a Evolution en
  vivo (`/instance/connectionState/`). En PAduanero la columna heredada
  `estado_conexion` está muerta a propósito — quien la mire, miente.
- El nombre de instancia es único; crear uno tomado responde 403/409 (no es
  error: es «ya estaba»).

---

## 3. Ciclo de vida de la instancia (todo por instancia)

| Operación | Llamada a Evolution | Detalles duros (aprendidos) |
|---|---|---|
| **Crear + vincular (QR)** | `POST /instance/create {instanceName,qrcode:true,integration:'WHATSAPP-BAILEYS'}` y `GET /instance/connect/{instancia}` | 403/409 = «ya existe», se pide el QR y ya. **El QR NO está listo al instante**: `create` y el primer `connect` responden `{"count":0}` sin imagen mientras Baileys levanta el socket → hay que **sondear** (≈8 × 1,5 s). Si `create` ni contacta a Evolution (status 0), NO sondear: cortar en 2 s con error claro |
| **Vincular por CÓDIGO** | `POST /instance/create` con `number` y `GET /instance/connect/{instancia}?number=…` | Devuelve `pairingCode` de 8 caracteres para teclear en el teléfono. **Más confiable que el QR** cuando este no llega o caduca (§6) |
| **Estado** | `GET /instance/connectionState/{instancia}` | Mapear `open→conectado`, `connecting→qr`, `close→desconectado`. El número vinculado viene en `instance.owner` |
| **Desvincular** | `DELETE /instance/logout/{instancia}` | Cierra la sesión de ESA instancia. Queda en `close`, lista para re-vincular con otro QR/código |
| **Webhook** | `POST /webhook/set/{instancia}` con `{webhook:{url,enabled,base64:true,events:['MESSAGES_UPSERT','CONNECTION_UPDATE']}}` | Evolution cambió el cuerpo entre v1 y v2: si la forma anidada falla, reintentar con los campos en la raíz. `base64:true` trae la media en el evento y ahorra una llamada por audio/foto |

---

## 4. Ritmo anti-baneo del tráfico proactivo (patrón, NO implementado en PAduanero)

PAduanero no envía campañas: el motor solo responde. Si algún día se agrega
tráfico proactivo (recordatorios de cita, seguimientos), el patrón portable es
**encolar y enviar separados**, nunca enviar en línea con la petición web:

```
Evaluador (cron horario)     → SOLO encola (dedupe por periodo, UNIQUE)
Enviador  (cron cada 5 min)  → por tick:
                                1. jitter: ~40% de ticks se saltan al azar
                                2. UNA fila pendiente, la más vieja (intentos < 3)
                                3. revalidar consentimiento AHORA (opt-out → 'omitido')
                                4. sin instancia conectada → 'omitido' con motivo visible
                                5. enviar, marcar, siguiente
```

Resultado: envíos cada **5–25 minutos** sin patrón de reloj. **Un reloj perfecto
también es un patrón detectable.** Advertencia honesta: el jitter y el opt-out
son mitigaciones, no garantía — WhatsApp banea sobre todo por reportes de
usuarios, así que el mejor anti-baneo es que el mensaje sea esperado.

Nota de nota de voz: el **botón de pendientes** de PAduanero (§ doc
`RESPONDER_PENDIENTES.md`) es un envío *disparado por una persona*, uno por
chat, tras una caída — no es tráfico automatizado y no necesita el enviador con
jitter. Aun así respeta el espíritu: nada sale sin revisión humana.

---

## 5. Higiene entrante (ya implementada en PAduanero)

En `EvolutionClient::normalizarWebhook()`:

- **`fromMe` se descarta** siempre: el eco propio crearía el bucle del bot
  respondiéndose a sí mismo (volumen infinito = baneo seguro).
- **Grupos (`@g.us`) y broadcast (`@broadcast`) se descartan.**
- **LID**: con `addressingMode:"lid"` WhatsApp no revela el número; el remitente
  llega como `45939054088265@lid`. Se responde **a ese JID entero** (mandar solo
  los dígitos responde 400 `"exists": false`). Regla: si el destino trae `@`, se
  respeta tal cual. Esta misma regla la aplica el botón de pendientes al derivar
  el teléfono de cada chat.

---

## 6. Operación de la sesión — las lecciones caras de PAduanero

Esto es lo que este proyecto aprendió a los golpes y no está en el doc original:

- **La versión de WhatsApp Web del contenedor caduca y rompe la vinculación.**
  El 2026-09-01 WhatsApp revocó la sesión (`device_removed`) y ningún QR nuevo
  volvía a vincular: el teléfono decía «no se puede vincular el dispositivo».
  Causa: Baileys anunciaba una versión de WhatsApp Web meses atrasada. **Cura:**
  fijar `CONFIG_SESSION_PHONE_VERSION` en el compose de Evolution a una versión
  vigente (la del repo `wppconnect-team/wa-version`, carpeta `html/`). En el VPS
  quedó `2.3000.1046856082`. Esa versión también envejecerá: si la vinculación
  vuelve a fallar, actualizarla es el primer sospechoso.

- **Vincular por CÓDIGO cuando el QR no coopera.** El QR del panel no rota y
  caduca en ~40 s; para cuando se escanea suele estar muerto, sin dejar rastro
  en los logs. El **código de emparejamiento** (§3) dura minutos y vinculó al
  primer intento. Está en el panel: botón «Obtener código» junto al de QR.

- **No pidas QR de una sesión ya vinculada.** WhatsApp no entrega QR de una
  instancia en `open`: Baileys no lo genera y el sondeo cae en el mensaje
  genérico de «versión», un diagnóstico FALSO cuando la sesión está sana.
  `conectarQr()`/`conectarCodigo()` consultan el estado primero y, si ya está
  conectada, lo dicen (con el número) en vez de culpar a la versión.

- **Una caída puede pasar días inadvertida.** Entre el 01 y el 04 de septiembre
  el bot estuvo caído sin que nadie lo notara — `estado_conexion` no se
  actualiza solo. El vigilante `bin/wa-vigilar.php` (cron cada 5 min) pregunta a
  Evolution el estado REAL, y **solo cuando cambia** avisa por correo y/o por
  una instancia de WhatsApp DISTINTA (la que se cayó no puede avisar de su
  propia caída). Los mensajes que llegan durante la caída no pasan por el
  webhook: se recuperan después con el botón de pendientes.

- **QR de pruebas primero.** Vincular siempre un número de pruebas antes que el
  del negocio: los experimentos queman números.

---

## 7. Mapa a la implementación de referencia (PAduanero)

| Pieza | Archivo |
|---|---|
| Cliente por instancia (create/QR/código/estado/logout/webhook/envíos/lecturas) | `packages/whatsapp-engine/src/Channel/EvolutionClient.php` |
| Contrato del canal (cambiar de proveedor sin tocar el resto) | `packages/whatsapp-engine/src/Channel/ChannelInterface.php` |
| Construcción desde la config + defaults de plataforma (dos modos) | `EvolutionClient::desdeConfig()` |
| Vinculación por código (pairingCode) | `EvolutionClient::conectarPorCodigo()` |
| Pantalla de conexión (QR, código, estado, desvincular) | `src/Panel/WhatsappControlador.php` → `conectarQr()`, `conectarCodigo()`; plantilla `plantillas/panel/whatsapp.php` |
| Higiene entrante (fromMe/grupos/broadcast/LID) | `EvolutionClient::normalizarWebhook()` |
| Vigilante de sesión (detección de caída + alerta) | `bin/wa-vigilar.php` (cron cada 5 min) |
| Versión de WhatsApp Web fijada | `CONFIG_SESSION_PHONE_VERSION` en `/opt/evolution/docker-compose.yml` (VPS) |
| Recuperar los mensajes perdidos durante una caída | `RESPONDER_PENDIENTES.md` (mismo paquete) |

**Para un proyecto multi-tenant** (offboarding con `DELETE /instance/delete`,
enviador proactivo con jitter, canal propio de la plataforma), la referencia
completa es `MULTI_INSTANCIA_ANTI_BANEO.md` en el paquete de Control BarMax.
