# DESPLIEGUE DE LOS CUATRO CANALES — Etapa 2

> Procedimiento de la Etapa 2 de `PLAN_BUILD.md`. Se ejecuta **en el VPS**, no
> en la máquina de desarrollo.
>
> **Criterio de cierre:** un mensaje enviado por cada uno de los cuatro
> canales aparece en la bandeja de Chatwoot y Pedro puede responder desde un
> solo lugar. Sin IA todavía.
>
> Al terminar cada bloque: `php bin/verificar-canales.php`.

---

## 0. Antes de empezar — quién hace qué

No son requisitos que falten: son tareas con dueño. La infraestructura existe;
lo que queda es ejecutar este documento.

| Tarea | Dueño | Notas |
|---|---|---|
| VPS con Docker ≥ 20.10 y Compose ≥ 2.14, 8 GB de RAM | **PO** | Chatwoot solo ya pide 4 (`CLAUDE.md` §1.1) |
| DNS `chat.pedroabogadoaduanero.com` → IP, y TLS | **PO** | §1.1 |
| Credenciales SMTP | **PO** | §6 |
| Escanear el QR de WhatsApp | **Pedro** | Presencial, ~10 min, celular del `573159923676` |
| Meta Business: Instagram profesional + página de Facebook | **Pedro** | §4. **Es lo que más tarda**: empezar por aquí |

Solo dos tareas dependen de Pedro, y una son diez minutos con el celular. La
de Meta es la de camino crítico: los permisos de Instagram y la vinculación
con la página pueden tomar días si la cuenta no está ya como profesional.

**El número no puede ser el personal de Pedro.** No es una preferencia: si
Meta bloquea el número —y Baileys no es API oficial, así que puede pasar—
se pierde también su WhatsApp personal. Ver `RUNBOOK.md` §3.2.

---

## 1. Chatwoot

```bash
mkdir -p /opt/chatwoot && cd /opt/chatwoot

# Desde el repositorio del proyecto:
cp /var/www/pedro/infra/chatwoot/docker-compose.yml .
cp /var/www/pedro/infra/chatwoot/.env.example .env
```

Rellenar el `.env`:

```bash
# SECRET_KEY_BASE
docker compose run --rm rails bundle exec rails secret

# Contraseñas de Postgres y Redis
openssl rand -hex 24
```

**Confirmar la etiqueta de la imagen antes del primer arranque.** El compose
viene pineado a `v4.9.0-ce`; comprobar cuál es la vigente en
[Docker Hub](https://hub.docker.com/r/chatwoot/chatwoot/tags). Tiene que
terminar en **`-ce`**: la etiqueta por defecto incluye el directorio
`enterprise/`, bajo licencia comercial que exige suscripción en producción
(`CLAUDE.md` §1.1).

```bash
docker compose run --rm rails bundle exec rails db:chatwoot_prepare
docker compose up -d
docker compose ps          # rails y sidekiq deben estar healthy
```

### 1.1 Nginx y TLS

```nginx
server {
    server_name chat.pedroabogadoaduanero.com;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # El widget web y la bandeja usan WebSocket. Sin esto la bandeja
        # carga pero los mensajes nuevos no aparecen hasta recargar, y el
        # síntoma parece «Chatwoot va lento».
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 300s;
    }

    client_max_body_size 40M;   # adjuntos: actas y declaraciones escaneadas
}
```

```bash
certbot --nginx -d chat.pedroabogadoaduanero.com
```

### 1.2 Cuenta y cierre del registro

1. Entrar a `https://chat.pedroabogadoaduanero.com` y crear la cuenta de Pedro.
2. **Comprobar que `ENABLE_ACCOUNT_SIGNUP=false`** en el `.env` y reiniciar.
   Por defecto Chatwoot deja que cualquiera con la URL se cree una cuenta. En
   una bandeja con expedientes bajo secreto profesional eso no es una
   preferencia de configuración, es una brecha.
3. Crear el **agent bot** en Ajustes → Integraciones y guardar su token en
   `CHATWOOT_BOT_TOKEN` del `.env` de la aplicación. Todavía no hace nada:
   la IA llega en la Etapa 4. El token se necesita ya para que
   `verificar-canales.php` pueda leer las bandejas.

---

## 2. Evolution API

```bash
mkdir -p /opt/evolution && cd /opt/evolution
cp /var/www/pedro/infra/evolution/docker-compose.yml .
cp /var/www/pedro/infra/evolution/.env.example .env

openssl rand -hex 32        # AUTHENTICATION_API_KEY
docker compose up -d

curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/   # debe dar 200
```

**No usar `latest`.** Hoy apunta a una release candidate de la 2.4.0, marcada
por el propio proyecto como no apta para producción. El compose viene pineado
en `v2.3.7`, que es la última estable.

### 2.1 Activación de la licencia — **hoy no aplica**

El compose está pineado en **v2.3.7**, que es la última estable y es anterior
a la 2.4.0: **no pide activación de ninguna clase**. Este bloque se salta.

Se conserva escrito porque la 2.4.0 acabará saliendo y habrá que subir —
Baileys sigue el protocolo de WhatsApp, que cambia, y quedarse atrás termina
en una conexión caída sin arreglo. Cuando ese día llegue, el **orden importa**:

1. **Primero, activación manual.** Túnel SSH al Manager, que no se expone:
   ```bash
   ssh -L 8080:127.0.0.1:8080 usuario@vps
   ```
   Abrir `http://127.0.0.1:8080/manager` y activar con el correo del despacho.

2. **Después, poner ese mismo correo** en `EVOLUTION_OPERATOR_EMAIL` y
   reiniciar. La autoactivación headless **solo funciona con un correo ya
   registrado**: ponerlo antes del paso 1 no sirve de nada.

3. Comprobar que quedó:
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/
   # 200 = activada · 503 = sigue pendiente
   ```

> **Por qué este orden importa tanto.** Al recrear el contenedor —una
> actualización de imagen, un `docker compose pull`, un volumen perdido— la
> API vuelve a 503 y **WhatsApp queda caído sin que nadie haya tocado nada**.
> Con `EVOLUTION_OPERATOR_EMAIL` bien puesto se reactiva sola. Por eso el
> compose pinea la etiqueta y nunca usa `latest`, y por eso `bin/salud.sh`
> distingue el 503 de un «desconectado» normal.

En v2.3.7 nada de esto ocurre: la instancia arranca sirviendo. Pero el pineo
de la etiqueta sigue siendo obligatorio, porque `latest` apunta hoy a una
release candidate.

### 2.2 Instancia de WhatsApp

```bash
curl -X POST http://127.0.0.1:8080/instance/create \
  -H "apikey: $AUTHENTICATION_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "instanceName": "pedro",
    "integration": "WHATSAPP-BAILEYS",
    "qrcode": true
  }'
```

Escanear el QR **desde el celular del número del negocio**. El QR sale en la
respuesta y también en el Manager.

```bash
curl -s -H "apikey: $AUTHENTICATION_API_KEY" \
  http://127.0.0.1:8080/instance/connectionState/pedro
# {"instance":{"state":"open"}}
```

---

## 3. Canal 1 — WhatsApp en la bandeja

La integración es nativa: no hay puente que escribir (`CLAUDE.md` §1.1).

```bash
curl -X POST http://127.0.0.1:8080/chatwoot/set/pedro \
  -H "apikey: $AUTHENTICATION_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "enabled": true,
    "accountId": "1",
    "token": "TOKEN_DEL_AGENT_BOT",
    "url": "https://chat.pedroabogadoaduanero.com",
    "signMsg": false,
    "reopenConversation": true,
    "conversationPending": false,
    "importContacts": false,
    "importMessages": false,
    "nameInbox": "WhatsApp"
  }'
```

- `signMsg: false` — Chatwoot firmaría cada mensaje con el nombre del agente.
  En WhatsApp eso se ve como ruido pegado al texto.
- `reopenConversation: true` — si el cliente vuelve a escribir semanas
  después, sigue el mismo hilo en vez de abrir uno nuevo. En un caso jurídico
  el historial es el contexto.
- `importMessages: false` — el número es nuevo y no hay nada que importar.
  Con un número usado, importar mete conversaciones personales en la bandeja
  del despacho.

Al terminar, Chatwoot tendrá una bandeja de tipo API llamada «WhatsApp».

---

## 4. Canal 2 y 3 — Instagram y Messenger

Ambos se conectan desde Chatwoot: **Ajustes → Bandejas → Añadir bandeja**.

Requisitos previos, todos de Pedro y todos de Meta:

- La cuenta de Instagram tiene que ser **profesional** (Empresa o Creador) y
  estar **vinculada a una página de Facebook**. Una cuenta personal no se
  puede conectar.
- Pedro tiene que ser administrador de esa página.
- En los ajustes de Instagram, **«Permitir acceso a mensajes» activado**. Sin
  eso la conexión aparenta funcionar y no entra ni un mensaje.

El flujo es OAuth contra Meta. Chatwoot pide los permisos y crea la bandeja.

> **Los tokens de Meta caducan.** Cuando pase, la bandeja deja de recibir sin
> avisar. Es un fallo silencioso: hay que reconectar desde Chatwoot. Se anota
> en la rutina mensual del `RUNBOOK.md` §4.

---

## 5. Canal 4 — Widget web

1. En Chatwoot: **Ajustes → Bandejas → Añadir → Sitio web**.
   - Dominio: `pedroabogadoaduanero.com`
   - Color: `#16213A` (el azul tinta de la landing)
2. Copiar el **website token**.
3. Guardarlo en la configuración de la aplicación:

```sql
UPDATE configuraciones SET valor = '"EL_TOKEN"'
 WHERE clave = 'chatwoot_widget_token';

UPDATE configuraciones SET valor = '"https://chat.pedroabogadoaduanero.com"'
 WHERE clave = 'chatwoot_widget_url';
```

```bash
touch /var/www/pedro/storage/config.sentinel     # invalida la caché de Config
rm -f /var/www/pedro/storage/cache/landing.html  # regenera el HTML
```

La landing ya trae el widget implementado desde la Etapa 1 y **solo lo emite
cuando ambas claves tienen valor**. No hay que tocar código ni desplegar.

Comprobar en `https://pedroabogadoaduanero.com` que aparece la burbuja.

---

## 6. Alertas por correo

En el `.env` de Chatwoot: `SMTP_ADDRESS`, `SMTP_PORT`, `SMTP_USERNAME`,
`SMTP_PASSWORD`, `MAILER_SENDER_EMAIL`. Reiniciar y probar:

**Ajustes → Perfil → Notificaciones**: activar correo para conversación nueva
y para mensaje nuevo asignado.

```bash
docker compose exec rails bundle exec rails runner \
  'ActionMailer::Base.mail(to: "pedro@…", subject: "Prueba", body: "ok").deliver_now'
```

Si el correo no llega, mirar `docker compose logs sidekiq`: el envío es un
trabajo en segundo plano, así que el error aparece ahí y no en `rails`.

---

## 7. Cierre de la etapa

### Automático

```bash
php /var/www/pedro/bin/verificar-canales.php
```

Comprueba que Chatwoot responde, que existen las cuatro bandejas, que la
instancia de WhatsApp está en `open`, que el widget está configurado y que el
SMTP está puesto.

### Manual — esto no lo puede hacer una máquina

El criterio de cierre dice «un mensaje enviado por cada uno de los cuatro
canales aparece en la bandeja». Hace falta una persona con un teléfono y las
cuentas. Anotar el resultado:

- [ ] **`ENABLE_ACCOUNT_SIGNUP=false`** y Chatwoot reiniciado. Comprobarlo
      abriendo `/app/auth/signup` en una ventana privada: **no debe dejar
      crear cuenta**. Viene en `true` de fábrica, y dejarlo así permite que
      cualquiera con la URL entre a una bandeja con expedientes bajo secreto
      profesional. Va primero en esta lista a propósito.
- [ ] **WhatsApp** — escribir a `573159923676` desde otro teléfono.
- [ ] **Instagram** — mandar un DM a la cuenta del despacho desde otra cuenta.
- [ ] **Messenger** — escribir a la página de Facebook desde otro perfil.
- [ ] **Widget web** — abrir la landing en una ventana privada y escribir.
- [ ] Los cuatro aparecen en **la misma bandeja** de Chatwoot.
- [ ] Pedro **responde desde Chatwoot** a los cuatro y la respuesta llega.
- [ ] Le llega **el correo** de aviso de conversación nueva.
- [ ] Ninguna respuesta automática: **no hay IA todavía**. Si algo responde
      solo, es la configuración de Chatwoot y hay que apagarla.

Con eso, la Etapa 2 queda cerrada y Pedro atiende los cuatro canales desde un
solo sitio.

---

## 8. Después del despliegue

Añadir al `RUNBOOK.md` §4, rutina mensual:

- Reconectar Instagram y Messenger si los tokens de Meta caducaron.
- Comprobar que `EVOLUTION_OPERATOR_EMAIL` sigue puesto tras cada
  actualización de Evolution.
- Actualizar Chatwoot y Evolution en ventana acordada con Pedro, **nunca en
  día hábil por la mañana**, y con etiqueta fija: subir la versión en el
  compose a mano, no con `latest`.

Y añadir el respaldo del volumen de Evolution, que `bin/respaldo.sh` ya
contempla en `/opt/evolution/instances`.
