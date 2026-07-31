# RUNBOOK DE OPERACIÓN

> Manual de guardia. Para leer **antes** de que algo falle, no durante.
> Cuando algo se caiga, no habrá tiempo de entender la arquitectura desde cero.
>
> Contacto de escalamiento: PO (técnico) → Pedro (negocio).
> Regla general: si la duda es entre "apago la IA" y "sigo investigando",
> se apaga la IA. Chatwoot y WhatsApp siguen funcionando sin ella.

---

## 0. Los tres interruptores

Memorízalos. Resuelven el 80 % de las emergencias sin tocar código.

| Qué | Dónde | Efecto |
|---|---|---|
| **Pausar la IA** | Panel → Configuración → `motor_ia_pausado = true` | El bot calla. Pedro sigue atendiendo desde Chatwoot. |
| **Modo sombra** | Panel → Configuración → `motor_modo_sombra = true` | La IA escribe como nota privada, no envía nada al cliente. |
| **Pausar una conversación** | Chatwoot → asignar la conversación a un humano | La IA se apaga solo en ese hilo. |

Si no puedes entrar al panel:

```sql
UPDATE configuraciones SET valor = 'true' WHERE clave = 'motor_ia_pausado';
```

Y luego toca el centinela de caché para que el worker lo note sin esperar 60 s:

```bash
touch /var/www/pedro/storage/config.sentinel
```

---

## 1. Mapa de servicios

| Servicio | Dónde corre | Puerto | Se reinicia con |
|---|---|---|---|
| App PHP (motor + panel + landing) | PHP-FPM + Nginx, nativo | 443 | `systemctl reload php8.2-fpm nginx` |
| Worker del outbox | systemd, PHP CLI | — | `systemctl restart pedro-outbox` |
| MySQL 8 | Docker o nativo | 3306 | `systemctl restart mysql` |
| Chatwoot | Docker Compose | 3000 | `docker compose -f /opt/chatwoot/docker-compose.yml restart` |
| Postgres de Chatwoot | Docker | 5432 | (interno de ese compose) |
| Redis de Chatwoot | Docker | 6379 | (interno de ese compose) |
| Evolution API | Docker | 8080 | `docker compose -f /opt/evolution/docker-compose.yml restart` |

Los tres crones:

```cron
*/5  * * * *  php /var/www/pedro/bin/cron-expirar-reservas.php
*/10 * * * *  /var/www/pedro/bin/salud.sh
15   3 * * *  /var/www/pedro/bin/respaldo.sh
```

---

## 2. Diagnóstico rápido

```bash
/var/www/pedro/bin/salud.sh
```

Devuelve verde o rojo por componente. Si no tienes el script a mano:

```bash
# ¿Responde la app?
curl -sf https://pedroabogadoaduanero.com/salud || echo "APP CAÍDA"

# ¿Está viva la instancia de WhatsApp?
curl -s -H "apikey: $EVOLUTION_API_KEY" \
  http://127.0.0.1:8080/instance/connectionState/pedro

# ¿Cuánto lleva atascado el outbox?
mysql -e "SELECT estado, COUNT(*) FROM eventos_outbox GROUP BY estado;" pedro_aduanero

# ¿Chatwoot arriba?
curl -sf https://chat.pedroabogadoaduanero.com/api || echo "CHATWOOT CAÍDO"
```

---

## 3. Incidentes

### 3.1 WhatsApp desconectado

**Síntoma:** `connectionState` distinto de `open`. No entran mensajes nuevos.

Causas por frecuencia:

1. **Sesión caída.** Reconectar y, si pide QR, escanearlo desde el celular del
   número del negocio.
   ```bash
   curl -X GET -H "apikey: $EVOLUTION_API_KEY" \
     http://127.0.0.1:8080/instance/connect/pedro
   ```
2. **Contenedor reiniciado sin activar licencia.** Evolution devuelve 503 hasta
   activar. Si `EVOLUTION_OPERATOR_EMAIL` está bien configurado se autoactiva; si
   no, entrar al Manager en `/manager` y activar a mano. **Esta es la causa más
   probable de una caída silenciosa tras un reinicio no supervisado.**
3. **Celular sin internet más de 14 días.** WhatsApp Web cierra la sesión.

Mientras tanto: avisar a Pedro por otro canal. Los mensajes que no entran no se
recuperan; los clientes verán "entregado" pero nadie responderá.

### 3.2 Número bloqueado por WhatsApp

**Esto es el peor escenario del proyecto** y no tiene arreglo técnico.
Baileys/whatsmeow no son API oficial de Meta.

Qué hacer, en orden:

1. Solicitar revisión desde la app de WhatsApp Business con el número afectado.
2. Publicar en la landing y en redes un número alterno **de inmediato**. La landing
   lee el número de `whatsapp_numero_negocio`: cambiarlo en el panel actualiza
   todos los botones sin desplegar nada.
3. Migrar a WhatsApp Business Cloud API oficial. Evolution soporta ese backend, así
   que es cambio de configuración, no reescritura.

Prevención, y es la parte que importa: número dedicado, nunca mensajería masiva no
solicitada, volumen de envío conservador, y no responder a listas compradas. La
mayoría de los bloqueos vienen de que la gente reporta el número como spam.

### 3.3 Los pagos no se confirman

**Síntoma:** el cliente dice que pagó, la consulta sigue en `reservada`.

```sql
SELECT p.estado, p.firma_verificada, p.creado_en, p.payload_webhook
FROM pagos p WHERE p.referencia = 'REFERENCIA';
```

- **No hay fila de webhook:** la pasarela no está llamando. Verificar la URL
  registrada en el panel de Wompi y que el firewall no la bloquee.
- **Hay fila con `firma_verificada = 0`:** la firma no valida. Casi siempre es que
  se está firmando sobre el JSON parseado en vez del cuerpo crudo, o que la
  credencial es de pruebas y el evento de producción.
- **La pasarela dice aprobado y nosotros no:** confirmar manualmente desde el panel
  (queda en auditoría) y luego arreglar la causa. El cliente no debe pagar el
  precio de nuestro bug.

Nunca marcar `pagada` con un UPDATE directo sin dejar registro en `auditoria`.

### 3.4 El bot dijo algo que no debía

Un plazo, un artículo, una promesa de resultado. Es el incidente más grave del
sistema aunque nada esté "caído".

1. **Pausar la IA de inmediato** (interruptor 1).
2. Avisar a Pedro. Él decide qué se le dice al cliente. No corregir por cuenta propia.
3. Reconstruir qué pasó:
   ```sql
   SELECT ce.prompt_version_id, p.version, p.contenido
   FROM conversacion_estado ce
   JOIN prompts p ON p.id = ce.prompt_version_id
   WHERE ce.chatwoot_conv_id = ?;
   ```
   Para eso existe el versionado de prompts: poder responder qué instrucciones
   tenía el bot ese día.
4. Corregir el prompt, crear versión nueva, **que Pedro la apruebe**, activar.
5. Volver a modo sombra durante una semana antes de reabrir el envío automático.

### 3.5 El outbox se atascó

```sql
SELECT tipo, intentos, ultimo_error, COUNT(*)
FROM eventos_outbox WHERE estado IN ('pendiente','fallido')
GROUP BY tipo, intentos, ultimo_error;
```

Si hay muchos `procesando` viejos, el worker murió a mitad:

```sql
UPDATE eventos_outbox SET estado='pendiente'
WHERE estado='procesando' AND creado_en < NOW() - INTERVAL 15 MINUTE;
```

```bash
systemctl restart pedro-outbox
journalctl -u pedro-outbox -n 100
```

### 3.6 Presupuesto de IA agotado

El motor corta solo al superar `presupuesto_ia_mensual_usd` y escala a humano.
No es una falla: es la protección funcionando. Revisar `consumo_ia` por si hay una
conversación en bucle antes de subir el tope.

```sql
SELECT DATE(creado_en) d, SUM(costo_usd), COUNT(*)
FROM consumo_ia WHERE creado_en > NOW() - INTERVAL 30 DAY GROUP BY d ORDER BY d DESC;
```

### 3.7 Se perdió la MASTER_KEY o el PEPPER_TELEFONO

No hay recuperación para ninguna de las dos. Son los únicos datos del sistema cuya
pérdida no se arregla con un restore, y por eso se respaldan por separado y fuera
del servidor (`RESPALDOS.md` §4).

**MASTER_KEY.** Las credenciales cifradas son irrecuperables.

1. Restaurar la clave desde el respaldo fuera del servidor.
2. Si no existe ese respaldo: rotar **todas** las credenciales en cada proveedor
   (Wompi, LLM, Chatwoot, Evolution, SMTP), generar `MASTER_KEY` nueva, vaciar la
   tabla `credenciales` y volver a cargarlas desde el panel.

**PEPPER_TELEFONO.** Es peor, porque falla en silencio: la aplicación arranca, pero
`ContactoRepo::porTelefono()` deja de encontrar a los contactos existentes y el
motor empieza a crear duplicados de gente que ya estaba en la base.

1. Restaurar el pepper desde el respaldo. Es la única salida limpia.
2. Si no existe: los `telefono_hash` viejos son basura permanente. Hay que generar
   un pepper nuevo y **recalcular la columna entera** desde `contactos.telefono`,
   que sí está en claro. Es un script de una pasada, pero mientras no se corra el
   sistema duplica contactos.

Por eso el pepper no rota nunca y por eso no se deriva de la `MASTER_KEY`: rotar la
maestra habría provocado este mismo incidente en cada rotación programada.

### 3.8 Chatwoot caído

El bot no puede responder. Los mensajes de WhatsApp siguen llegando a Evolution y
se acumulan; Chatwoot los ingesta al volver.

```bash
cd /opt/chatwoot && docker compose logs --tail=200 && docker compose restart
```

Causa habitual: disco lleno o el Postgres de Chatwoot sin espacio. Revisar `df -h`
antes que nada.

---

## 4. Rutinas

**Diaria (5 min).** Salida de `salud.sh`. Outbox sin fallidos. Reservas expiradas
del día anterior — si hay muchas, hay fricción en el pago.

**Semanal (20 min).** Muestreo de 10 conversaciones del bot buscando violaciones de
las reglas inviolables. Gasto de IA contra presupuesto. Espacio en disco. Que el
respaldo de anoche exista y pese lo razonable.

**Mensual (1 h).** **Restauración de prueba** en entorno aparte (ver `RESPALDOS.md`
§5) — un respaldo no probado no es un respaldo. Revisión de logs de acceso al
panel. Rotación de credenciales que estén por vencer. Actualización de Chatwoot y
Evolution en ventana acordada con Pedro, nunca en día hábil por la mañana.

---

## 4 bis. Bloque de Nginx

Los estáticos viven en `public/` pero se sirven desde la raíz de la URL:
`landing_ruta_imagenes` vale `/img` porque es lo que acaba en el atributo
`src`, mientras que en disco están en `public/img` (`CLAUDE.md` §12.6). Sin el
`alias`, las fotos dan 404 y la landing sale sin imágenes.

```nginx
server {
    server_name pedroabogadoaduanero.com;
    root /var/www/pedro;
    index index.php;

    location ~ ^/(img|css|js)/ {
        alias /var/www/pedro/public/;
        try_files $uri =404;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # El endpoint de métricas es público y escribe en base. El tope por
    # sesión de `MetricasLanding` frena los bucles del JavaScript, pero no a
    # quien rote el identificador a propósito: eso se ataja aquí.
    location = /api/evento {
        limit_req zone=eventos burst=20 nodelay;
        try_files $uri /index.php$is_args$args;
    }

    # El .env lleva MASTER_KEY y PEPPER_TELEFONO.
    location ~ ^/(src|db|docs|bin|tests|storage|motor|vendor|\.git|\.env) {
        deny all;
        return 404;
    }

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}
```

La zona del `limit_req` se declara fuera del `server`, en el `http {}` de
`nginx.conf`:

```nginx
limit_req_zone $binary_remote_addr zone=eventos:10m rate=30r/m;
```

El `.htaccess` del repositorio hace lo mismo para el Apache de desarrollo
(Laragon). En el VPS no se usa. Para el servidor embebido de PHP existe
`bin/servidor-dev.php`, que no lee ninguno de los dos.

### Certificado TLS

```bash
apt install certbot python3-certbot-nginx
certbot --nginx -d pedroabogadoaduanero.com -d www.pedroabogadoaduanero.com
systemctl status certbot.timer      # la renovación automática debe estar activa
```

Comprobar que renueva **antes** de que haga falta; un certificado vencido deja
la landing inaccesible y los anuncios de Meta siguen pagando clics:

```bash
certbot renew --dry-run
```

---

## 5. Despliegue

```bash
cd /var/www/pedro
php bin/mantenimiento.php on          # el bot avisa que hay mantenimiento
git pull --ff-only
composer install --no-dev --optimize-autoloader
php bin/migrar.php                    # migraciones idempotentes
rm -f storage/cache/landing.html      # el HTML de la landing se regenera solo
systemctl restart pedro-outbox
systemctl reload php8.2-fpm
php bin/mantenimiento.php off
bin/salud.sh
```

**El VPS no necesita node.** `public/css/app.css` y las variantes AVIF/WebP de
`public/img/` se versionan ya compiladas. Se regeneran en la máquina de
desarrollo con `npm run build:css` y `php bin/optimizar-imagenes.php`, y se
commitean. Instalar node y Tailwind en producción para regenerar en cada
despliegue añadiría una dependencia de red y un punto de fallo a cambio de
nada.

Nunca desplegar viernes por la tarde ni con asesorías agendadas en la hora
siguiente. Antes de cualquier migración de esquema: respaldo manual.

**Rollback:** `git checkout <tag-anterior>` + `composer install` + reload. Las
migraciones deben ser reversibles o aditivas; jamás un `DROP COLUMN` en el mismo
despliegue que deja de usarla — se elimina uno o dos despliegues después.
