# Evolution API — error 463 y licencia gratuita 2.4

> Guía nacida del incidente del 2026-08-24 en PAduanero. Aplica igual a
> Control_BarMax, Maytech POS y cualquier proyecto que use Evolution API
> autoalojada con Baileys. Léela entera antes de tocar producción.

## 1. El síntoma

- La instancia aparece `open` y **recibe** mensajes con normalidad.
- **Ningún envío llega** al destinatario. En la API, cada mensaje enviado
  queda con `MessageUpdate: [{"status":"ERROR"}]`.
- El mismo número **sí envía** desde la app oficial del teléfono.
- Suele dispararse tras un `device_removed` (alguien desvinculó el
  dispositivo en WhatsApp) y el re-escaneo del QR.

## 2. La causa — error 463 «NackCallerReachoutTimelocked»

WhatsApp impone un **bloqueo temporal de envíos** a clientes que no mandan
los campos de privacidad `tctoken`/`cstoken` en los mensajes salientes.
Los Baileys viejos (6.x, los que trae Evolution ≤ 2.3.7) no los mandan, y
el servidor cuenta cada envío como «reach-out» y lo castiga con un
time-lock. Referencias:

- https://github.com/WhiskeySockets/Baileys/issues/2441 (investigación)
- https://github.com/evolution-foundation/evolution-api/issues/2650 (caso idéntico)

Cómo confirmarlo (diagnóstico en segundos):

```bash
# 1. En el compose de Evolution: LOG_LEVEL=ERROR,WARN,DEBUG,VERBOSE y recrear.
# 2. Enviar UN mensaje de prueba por la API.
# 3. Buscar el veredicto del servidor:
docker logs --since 5m <contenedor-evolution> 2>&1 | grep -A6 'Update messages'
#    → "status": 0, "messageStubParameters": ["463"]  ⇒  es este bug.
```

Reglas de oro mientras dura el bloqueo:

- **NO** reintentar en ráfaga: cada 463 puede alargar el castigo.
- **NO** re-vincular el QR una y otra vez: cada re-vinculación lo agrava.
- El bloqueo es temporal (horas). Probar con UN mensaje cada varias horas.
- Para atender clientes YA: responder desde la app oficial del teléfono,
  que no está bloqueada.

## 3. La cura — Baileys 7 (trae `tctoken`)

El arreglo está en Baileys 7.x. En imágenes de Evolution:

| Imagen | Baileys | ¿Sirve? |
|---|---|---|
| `evoapicloud/evolution-api:latest` (=2.3.7, may-2026) | 6.19 | NO — es la que sufre el 463 |
| `evoapicloud/evolution-api:homolog` (jul-2026) | — | NO — imagen ROTA (Prisma migrate falla al arrancar) |
| `evoapicloud/evolution-api:2.4.0-rc2` | 7.0.0-rc.9 | SÍ — trae `tctoken` (falta `cstoken`, menor) |

Verificar qué Baileys trae una imagen sin desplegarla:

```bash
docker run --rm --entrypoint sh <imagen> -c \
  'grep "\"baileys\"" /evolution/package.json; \
   grep -ril "tctoken" /evolution/node_modules/baileys/lib | head -3'
```

OJO con 2.4.0-rc2: al arrancar aplica ~59 migraciones Prisma a su
Postgres. **2.3.7 tolera ese esquema ampliado** (rollback probado), pero
haz respaldo del Postgres de Evolution antes si el proyecto lo permite.

## 4. La licencia de Evolution 2.4 — es GRATIS

Evolution se volvió «comercial» en 2.4, pero el nivel **community** es
100% gratuito (cobran nube gestionada y soporte, no el software). Sin
activar, la API entera responde 503 `LICENSE_REQUIRED`.

Flujo de activación por API (el que funcionó; el manager del navegador
puede quedarse con la SPA vieja en caché y despistar):

```bash
# 0. Con la 2.4 ya corriendo, cualquier llamada devuelve el instance_id de licencia:
curl -s http://127.0.0.1:8080/instance/fetchInstances -H 'apikey: x'
#    → {"code":"LICENSE_REQUIRED","instance_id":"<UUID>", ...}

# 1. Iniciar el registro (token válido 30 min):
curl -s -X POST https://license.evolutionfoundation.com.br/v1/register/init \
  -H 'Content-Type: application/json' \
  -d '{"tier":"community","version":"2.4.0","instance_id":"<UUID>"}'
#    → {"register_url":"https://license.evolutionfoundation.com.br/register?token=...", ...}

# 2. Abrir register_url en un navegador (es pública) y autenticarse:
#    Magic Link con un correo del negocio, o Google/GitHub.
#    Al final la página muestra un AUTHORIZATION CODE (64 hex, expira en 5 MIN).

# 3. Canjear el código YA (antes de 5 minutos):
curl -s -X POST https://license.evolutionfoundation.com.br/v1/register/exchange \
  -H 'Content-Type: application/json' \
  -d '{"authorization_code":"<código>","instance_id":"<UUID>","tier":"community","version":"2.4.0"}'
#    → {"api_key":"<64 hex>", "customer_id":N, "tier":"community"}

# 4. Esa api_key ES la nueva AUTHENTICATION_API_KEY:
#    - Ponerla en el .env que alimenta el compose y recrear el contenedor.
#    - La instancia se auto-activa contra el servidor de licencias (heartbeat cada 5 min).
```

**Consecuencia importante**: `AUTHENTICATION_API_KEY` cambia, así que
**toda aplicación que llame a Evolution debe actualizar su apikey**
(en PAduanero: `php bin/wa-configurar.php --poner evolution_apikey=<api_key>`;
en otros proyectos, donde guarden esa credencial). El manager del
navegador también entra con esta clave nueva.

La sesión de WhatsApp (el vínculo QR) **sobrevive** al salto
2.3.7 → 2.4.0-rc2: vive en el Postgres de Evolution.

Reactivaciones futuras sin navegador: registrar
`EVOLUTION_OPERATOR_EMAIL=<correo ya activado>` en el entorno y la
instancia usa `POST /v1/register/auto` sola (idempotente). Docs:
https://docs.evolutionfoundation.com.br/licensing

## 5. Trampas vividas en el camino (no repetir)

- El estado `MessageUpdate: ERROR` de Evolution 2.3.7 **no es confiable**:
  también aparece cuando su base interna falla al casar los acuses
  («Original message not found for update»). El único juez de entrega es
  un teléfono real.
- `docker restart` del contenedor NO cura el 463 (es castigo del servidor
  de WhatsApp, no estado local). Tampoco lo cura borrar y recrear la
  instancia con QR nuevo.
- El texto de un mensaje sacado con `mysql -N` lleva `\n` LITERALES:
  des-escapar antes de reenviarlo por API o el cliente ve las barras.
- Mensaje enviado con formato roto: se puede retirar con
  `DELETE /chat/deleteMessageForEveryone/<instancia>` pasando
  `{id, remoteJid, fromMe}` — pero si el canal está bloqueado, la
  revocación tampoco sale.
