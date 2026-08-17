# RUNBOOK DE OPERACIÓN

> Manual de guardia. Para leer **antes** de que algo falle, no durante.
>
> Contacto de escalamiento: PO (técnico) → Pedro (negocio).

**Encogió mucho, y por una buena razón.** Este documento cubría seis
subsistemas que podían romperse solos y en silencio: WhatsApp desconectado,
número bloqueado por Meta, pagos sin confirmar, el bot diciendo algo que no
debía, el outbox atascado y el presupuesto de IA agotado. Todos se fueron con
el motor y la pasarela.

Lo que queda es **una aplicación PHP contra MySQL detrás de Nginx**. Se cae de
las formas en que se cae cualquier sitio web —el disco se llena, el
certificado vence, alguien pierde el segundo factor— y esas son las que quedan
documentadas aquí. No hay ningún proceso de fondo que vigilar: el worker del
outbox y los cron del motor ya no existen.

Los únicos trabajos programados son `bin/cron-purgar.php` (retención de IP,
diaria), `bin/respaldo.sh` (diario) y `bin/salud.sh` (cada 10 minutos).

---

## 3. Incidentes

### 3.8 Nadie puede entrar al panel: teléfono perdido y 2FA

**Es el incidente de domingo por excelencia.** El segundo factor es obligatorio
para `super_admin` y `abogado`. Si Pedro cambia de teléfono sin migrar la
aplicación de autenticación, o se le pierde, queda fuera del panel de su propio
negocio. Y lo mismo aplica al perfil técnico.

No hay que improvisar un `UPDATE` sobre producción. Hay un comando:

```bash
cd /var/www/pedro
php bin/restablecer-2fa.php pedro@ejemplo.com
```

Pide confirmar escribiendo **el correo completo** —no un «s/n», porque
equivocarse de usuario en un listado es fácil— y un motivo, que queda en la
bitácora.

Qué hace, y por qué cada parte:

| Acción | Motivo |
|---|---|
| Borra el secreto TOTP y su contador | El siguiente secreto empieza limpio |
| **Revoca todas las sesiones del usuario** | Si el teléfono se perdió, una sesión abierta en ese teléfono sigue abierta |
| Registra en `auditoria` quién, cuándo y por qué | Es una operación privilegiada: tiene que dejar rastro |

Qué **no** hace: no cambia la contraseña ni entra a ninguna cuenta. Después,
el usuario entra con su contraseña de siempre y el panel le obliga a
configurar el segundo factor otra vez.

> **Por qué es defendible que este comando exista.** Quien puede ejecutarlo ya
> tiene shell en el VPS, y con shell ya tiene la `MASTER_KEY`, la base y todo
> lo demás: su privilegio es máximo con o sin él. No añade superficie de
> ataque — hace usable una recuperación que, de otro modo, se haría con un
> `UPDATE` improvisado a las tres de la mañana, sin registro y con el riesgo
> de tocar la fila equivocada.

Después del incidente, revisar en la bitácora que el restablecimiento fue el
esperado: `Bitácora → acción = totp_restablecido`.

## 4. Rutinas

**Diaria (5 min).** Salida de `salud.sh`. Que el respaldo de anoche exista y
pese lo razonable.

**Semanal (20 min).** Espacio en disco. Métricas de la landing en `/panel`: si
las visitas caen a cero de golpe, casi siempre es que algo rompió el registro
de eventos, no que se acabó la pauta.

**Mensual (1 h).** **Restauración de prueba** en entorno aparte (ver
`RESPALDOS.md` §5) — un respaldo no probado no es un respaldo. Revisión de
logs de acceso al panel. Auditoría de la landing con
`node bin/auditar-landing.mjs`: el presupuesto de 300 KB y el LCP se degradan
solos a base de cambios pequeños que ninguno parece caro.

Y una comprobación de fallo silencioso que sigue viva: **que la purga esté
corriendo**. `intentos_acceso` guarda IP, que es dato personal bajo la Ley
1581 de 2012; si el cron se detiene, la retención deja de cumplirse sin que
nada se rompa. `salud.sh` lo vigila.

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

    # Compresión. NO es opcional y NO viene por defecto: `gzip` está en
    # `off` en la configuración de fábrica de Nginx, y `gzip_types` de
    # fábrica solo cubre `text/html`. Sin estas cuatro líneas la landing
    # viaja en 60 KB donde caben 13, y el diagnóstico en 80 donde caben 15
    # — el HTML lleva el CSS incrustado, así que es el archivo que más se
    # beneficia y justo el que está en el camino crítico del LCP.
    #
    # `font/woff2`, `image/avif` y `image/webp` quedan fuera a propósito:
    # ya vienen comprimidos y volver a pasarlos por gzip gasta CPU para
    # dejarlos igual o un poco más grandes.
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/javascript application/json
               image/svg+xml application/xml;

    # `fonts` incluido: las tres tipografías se autoalojan en
    # `public/fonts` y se piden como `/fonts/*.woff2`. Sin esta línea la
    # página no falla — cae a la tipografía de reserva— y ese es el
    # problema: se ve peor sin dar un solo error en ninguna parte.
    location ~ ^/(img|css|js|fonts)/ {
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

    # El .env lleva la MASTER_KEY.
    location ~ ^/(src|db|docs|bin|tests|storage|resources|vendor|node_modules|\.git|\.env) {
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

### El panel en su propio subdominio

`docs/PANEL_ADMIN.md` §4.6 pide que el panel no comparta origen con la
landing. Mismo `root`, otro `server_name`, y ahí sí se puede restringir por IP
o dejarlo detrás de VPN:

```nginx
server {
    server_name panel.pedroabogadoaduanero.com;
    root /var/www/pedro;

    # allow 190.85.x.x;   ← si hay IP fija; complementa a PANEL_IPS_PERMITIDAS
    # deny all;

    location / { try_files $uri /index.php$is_args$args; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }

    # El login es el objetivo obvio de una fuerza bruta. El bloqueo por
    # cuenta y el rate limit por IP de la aplicación son la segunda línea.
    location = /panel/entrar {
        limit_req zone=login burst=5 nodelay;
        try_files $uri /index.php$is_args$args;
    }
}
```

En el `http {}` de `nginx.conf`:

```nginx
limit_req_zone $binary_remote_addr zone=login:10m rate=10r/m;
```

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
git pull --ff-only
composer install --no-dev --optimize-autoloader
php bin/migrar.php                    # migraciones idempotentes
rm -f storage/cache/*.html            # landing y diagnóstico se regeneran solos
systemctl reload php8.2-fpm
bin/salud.sh
```

**Borrar la caché a mano no es opcional.** `CachePagina` invalida por
centinela y por `mtime` del CSS, no por el de las plantillas: un despliegue
que solo cambie un `.php` sirve el HTML viejo hasta que expire el TTL.

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
