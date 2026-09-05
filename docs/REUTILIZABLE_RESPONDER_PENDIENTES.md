# Responder los chats que quedaron sin atender — diseño portable

> Un botón que, tras una caída de la sesión de WhatsApp (o cualquier lapso sin
> atender), **recupera los chats donde la última palabra la tiene el cliente**,
> deja que la IA proponga una respuesta por chat, y envía —como **nota de voz**—
> solo lo que una persona revisó y marcó.
>
> Extraído de la implementación real en PAduanero (Pedro Abogado Aduanero,
> verificado contra el código el 2026-09-05). Está escrito para
> **reimplementarse en otro proyecto** sin conocer aquel código; al final hay
> un mapa a la implementación de referencia. Aplica a cualquier negocio con un
> bot de WhatsApp que responde clientes: despachos, clínicas, tiendas.

---

## 0. El problema que resuelve

Evolution/Baileys pierde la sesión cada tanto (WhatsApp la revoca —
«device_removed»— o cae la red). Mientras está caída, **los mensajes entrantes
no pasan por el webhook**: no quedan en la base del negocio, no los ve nadie, y
el cliente cree que lo ignoraron. Pasó en PAduanero del 2026-09-01 al 04: tres
días de mensajes al vacío, descubiertos por casualidad.

La reconexión no los recupera sola. Este botón sí: reconstruye la lista desde
**la base de datos de Evolution**, que es la única memoria que sobrevive a la
caída — el teléfono sincroniza su historial reciente al re-vincular, y eso
queda en Evolution aunque nunca haya pasado por el webhook.

---

## 1. Las cuatro reglas de diseño (aprendidas, no teóricas)

1. **La fuente es Evolution, no la base del negocio.** Los mensajes perdidos
   nunca llegaron a `wa_mensajes`/`wa_conversaciones`. Se leen de
   `POST /chat/findChats/{instancia}` (cada chat con su `lastMessage`) y, para
   el contexto, `POST /chat/findMessages/{instancia}`. Leer de la base propia
   dejaría fuera justo lo que se cayó.

2. **Dos tiempos: la IA propone, una persona dispone.** El LLM ANALIZA y
   redacta una propuesta por chat; **nada se envía** en ese paso. Una persona
   revisa, edita el texto y marca qué sale. Es la misma lógica de aprobar un
   comprobante de pago a mano: el ojo humano va antes del envío, no después.
   Con un despacho jurídico (o cualquier vertical regulado) esto no es opcional.

3. **La decisión de "qué es un pendiente" es una función pura y probada.**
   Recibe lo que devolvería `findChats` y un reloj; no toca red ni base. Así se
   prueban los bordes sin montar Evolution: último mensaje del cliente (no
   `fromMe`), con silencio suficiente (no una conversación en curso) y no más
   viejo que la ventana (una disculpa de hace un mes incomoda). Grupos,
   difusiones y canales quedan fuera.

4. **La respuesta sale como nota de voz.** Es lo que hace que una disculpa por
   la demora se sienta de una persona y no de un formulario. El texto revisado
   se sintetiza con el TTS ya configurado y se manda como audio; el envío queda
   registrado y deja la conversación "con una persona" (HUMANO_ATENDIENDO),
   igual que responder a mano — la IA no contesta encima.

---

## 2. El flujo en tres pantallazos

```
LISTAR                      ANALIZAR (botón)              REVISAR Y ENVIAR
findChats/{instancia}       por chat (tope ~15):          por cada propuesta marcada:
  → filtrar (función pura)    - findMessages para contexto  - sintetizar texto (TTS)
  → chats con la última       - PromptComposer + tarea      - enviarAudio(telefono)
    palabra del cliente       - LLM devuelve JSON:          - registrar saliente
                                {responder, motivo, texto}  - conversación → HUMANO
                              (NADA se envía)               (solo lo que la persona marcó)
```

- **La IA puede sugerir NO responder** (un «gracias», spam, un chat ya cerrado)
  y explica por qué. La persona puede contradecirla escribiendo la respuesta.
- **El texto se va a leer en voz alta**: el prompt le prohíbe enlaces, correos,
  listas, formato y emojis, y le pide frases cortas y cálidas. La disculpa por
  la demora solo si el mensaje lleva horas esperando.
- **Tope de análisis por pasada** (~15 chats): cada propuesta es una llamada al
  LLM y hay una persona esperando con el navegador abierto.

---

## 3. La función pura, contrato portable

```
filtrar(chats[], ahora) -> pendientes[]
  incluye un chat si:
    remoteJid no es @g.us / @broadcast / @newsletter
    lastMessage existe y NO es fromMe
    SILENCIO_MINIMO <= (ahora - timestamp) <= VENTANA
  cada pendiente lleva: {jid, telefono, nombre, tipo, texto, cuando}
  ordenados del más reciente al más viejo
```

Constantes que importan (valores de referencia): `SILENCIO_MINIMO = 1800`
(media hora: menos que eso es una conversación viva, no un olvido) y
`VENTANA = 7 días`.

**El teléfono se deriva del JID con cuidado (regla del LID):** si el JID es
`…@s.whatsapp.net`, el destino son los dígitos; si es `…@lid` (WhatsApp ocultó
el número), el destino es **el JID entero** — mandarle solo los dígitos de un
LID responde 400 `"exists": false`. Regla: si trae `@` que no sea
`s.whatsapp.net`, viaja completo.

**Los tipos sin texto se rotulan, no se inventan:** una nota de voz entrante es
`[nota de voz]`, una imagen sin pie es `[imagen]` (con pie, el pie). Así la
persona y el LLM saben qué llegó aunque no qué decía.

---

## 4. Las dos llamadas nuevas al canal

El resto del ciclo (create/QR/estado/logout/webhook/envíos) ya existe en un
cliente de Evolution bien hecho. Esto añade dos lecturas:

| Operación | Llamada a Evolution | Notas duras |
|---|---|---|
| **Listar chats** | `POST /chat/findChats/{instancia}` con `{}` | Devuelve un arreglo; cada elemento trae `remoteJid`, `pushName` y `lastMessage` (con `key.fromMe`, `message`, `messageTimestamp`) |
| **Historial de un chat** | `POST /chat/findMessages/{instancia}` con `{where:{key:{remoteJid}}, limit}` | La respuesta anida los registros en `messages.records`. **Tras una re-vinculación el historial puede venir incompleto** — es lo que el teléfono quiso sincronizar, no la conversación entera. El LLM trabaja con lo que hay y lo sabe |

---

## 5. El prompt de la propuesta

Se compone con **el mismo prompt del bot** (rol, contexto del negocio y —clave—
las reglas de dominio NO editables) y se le suma una capa de tarea puntual que:

- deja claro que **no está conversando**: propone UNA respuesta que una persona
  revisará antes de enviarla como nota de voz;
- exige devolver **solo** un JSON `{responder, motivo, texto}` (se pela
  tolerando cercas de código y texto alrededor);
- pone `responder:false` para lo que no espera nada, con `texto` vacío;
- prohíbe lo que se dicta mal (enlaces, datos duros, formato) y pide tono
  cálido, breve, con disculpa por la demora solo si aplica;
- prohíbe inventar datos del caso o del cliente.

Que las reglas de dominio entren por composición —y no reescritas a mano— es lo
que impide que esta vía puentee las restricciones legales del bot (en un
despacho: sin plazos, sin normas con número, sin promesas de resultado).

---

## 6. Higiene de permisos y registro

- **Ver la pantalla** exige el permiso de leer casos; **analizar** y **enviar**
  exigen el de editarlos. El corte del canal (Evolution no configurado) va
  ANTES de gastar un peso en TTS o en LLM.
- Cada envío registra el mensaje saliente y deja la conversación en
  HUMANO_ATENDIENDO; si no existía la conversación (el chat se cayó antes de
  llegar a la base), se crea. Las acciones quedan en la auditoría
  (`pendientes_analizados`, `pendientes_respondidos` con conteos).
- **Best effort real:** si la síntesis de un chat falla, ese chat reporta su
  error y los demás siguen; nadie se queda a medias por uno.

---

## 7. Mapa a la implementación de referencia (PAduanero)

| Pieza | Archivo |
|---|---|
| Servicio: filtrar (pura), proponer (LLM), enviarNotaDeVoz (TTS) | `src/Wa/PendientesSinResponder.php` |
| Dos lecturas nuevas del canal (`listarChats`, `mensajesDeChat`) | `packages/whatsapp-engine/src/Channel/EvolutionClient.php` |
| Endpoints del panel (listar / analizar / enviar) | `src/Panel/WhatsappControlador.php` → `pendientes()`, `proponerPendientes()`, `enviarPendientes()` |
| Rutas | `src/Panel/Panel.php` → `GET|POST /whatsapp/pendientes[/analizar|/enviar]` |
| Pantalla (tres tiempos, casillas, textos editables) | `plantillas/panel/whatsapp_pendientes.php` |
| Composición del prompt (reglas de dominio no editables) | `packages/whatsapp-engine/src/Core/PromptComposer.php` |
| Síntesis de voz (ElevenLabs / OpenAI / Piper / Voicebox) | `packages/whatsapp-engine/src/Media/TtsManager.php` |
| Pruebas de la función pura y de los cortes de permiso/canal | `tests/Unidad/PendientesSinResponderTest.php`, `tests/Integracion/PanelWhatsappTest.php` |

**Dependencias del proyecto destino:** un cliente de Evolution por instancia,
un compositor de prompt que exponga las reglas no editables, un gestor de TTS y
una tabla de conversaciones/mensajes. Nada de esto es exclusivo de un despacho:
cambia el vocabulario de la capa de tarea (§5) y el feature es el mismo.
