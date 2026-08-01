# Arranque local — de cero al panel

Guía para levantar el proyecto en Windows con Laragon y entrar al panel por
primera vez. **Todos los pasos de este documento se ejecutaron y se
verificaron** el 2026-08-01; no hay ninguno escrito de memoria.

Asume que no hay nada: ni base de datos, ni `.env`, ni usuario.

---

## 0. Lo que ya tienes con Laragon

Laragon trae PHP, MySQL y Apache. Los binarios están en `C:\laragon\bin\`, y
**no siempre están en el PATH de PowerShell**. Si `php` no se reconoce, es eso.

Para la sesión de terminal en la que trabajes:

```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;$env:Path"
php -v
```

Ajusta los nombres de carpeta si tu Laragon trae otras versiones (`dir
C:\laragon\bin\php`). Para no repetirlo cada vez, Laragon tiene
**Menú → Herramientas → Path → Añadir al Path**.

---

## 1. Crear la base de datos

Dos bases: la de trabajo y la de pruebas. La de pruebas **debe** terminar en
`_pruebas` — el arranque de PHPUnit aborta si no, porque las pruebas de
integración truncan tablas y apuntarlas a la base real borraría los casos.

```powershell
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS pedro_aduanero CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS pedro_pruebas  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
```

En Laragon el usuario `root` no tiene contraseña por defecto. Si le pusiste
una, añade `-p` y te la pedirá.

---

## 2. El archivo `.env`

```powershell
cd C:\laragon\www\PAduanero
Copy-Item .env.example .env
```

### 2.1 Generar las dos llaves

**No uses las llaves de ningún ejemplo, ni las que aparezcan en un chat.**
Genera las tuyas:

```powershell
php -r "echo 'MASTER_KEY=' . base64_encode(random_bytes(32)) . PHP_EOL;"
php -r "echo 'PEPPER_TELEFONO=' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

Copia cada línea completa a `.env`, reemplazando la que ya está.

Las dos son irrecuperables y no se rotan igual:

- `MASTER_KEY` descifra las credenciales. Perderla obliga a volver a pedir
  todas las llaves a cada proveedor.
- `PEPPER_TELEFONO` **no rota nunca** (ADR-012). Un hash no es reversible: si
  cambia, todos los `telefono_hash` quedan huérfanos y la búsqueda por
  teléfono deja de encontrar **en silencio**, que es la peor forma de fallar.

En producción las dos se respaldan aparte de la base, con tres copias
(`docs/RESPALDOS.md` §4). En local basta con no perderlas mientras trabajas.

### 2.2 Ajustar la base y la URL

En `.env`, comprueba que estas coincidan con tu Laragon:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pedro_aduanero
DB_USER=root
DB_PASS=
APP_ENV=desarrollo
APP_URL=http://padu.test
```

`APP_ENV=desarrollo` importa: con cualquier otro valor las cookies de sesión
se marcan `Secure` y el navegador no las guarda sobre `http://`, así que
**entrarías y volverías al formulario de entrada sin ningún mensaje de error**.

### 2.2 bis — Guarda el `.env` SIN BOM

Windows es propenso a añadir la marca de orden de bytes (`EF BB BF`) al
principio del archivo: la ponen Notepad y `Out-File` de PowerShell. El
cargador la quita desde el 2026-08-01, pero conviene no meterla:

```powershell
# Comprobar: si los tres primeros bytes son 239,187,191 hay BOM
[System.IO.File]::ReadAllBytes(".env")[0..2] -join ','

# Quitarlo
$c = Get-Content .env -Raw -Encoding UTF8
[System.IO.File]::WriteAllText("$PWD\.env", $c, (New-Object System.Text.UTF8Encoding $false))
```

Antes de arreglarlo, un `.env` con BOM **perdía su primera variable en
silencio**, porque la clave pasaba a llamarse `\u{FEFF}APP_ENV`. Y el peor
caso era el más probable: si la primera línea era `APP_ENV`, se asumía
`produccion` y aparecía justo el rebote de sesión sin mensaje de error que se
describe arriba.

### 2.3 El secreto del webhook

Hace falta más adelante, pero se genera ahora para no dejarlo vacío:

```powershell
php -r "echo 'CHATWOOT_WEBHOOK_SECRET=' . bin2hex(random_bytes(24)) . PHP_EOL;"
```

---

## 3. Dependencias y migraciones

```powershell
composer install
php bin/migrar.php
```

`migrar.php` es idempotente: se puede volver a correr sin miedo. Si una
migración ya aplicada cambió de contenido, **aborta** en vez de reaplicarla
(ADR-013).

Salida esperada la primera vez: nueve migraciones aplicadas, de
`0001_motor.sql` a `0009_dedup_webhook.sql`.

---

## 4. Crear tu usuario

El panel no puede crear su propio primer administrador: nadie podría entrar a
usarlo.

```powershell
php bin/crear-usuario.php
```

Te pide correo, nombre, rol y contraseña. **Elige el rol 1 (`super_admin`)**:
es el único que puede tocar credenciales y modelos.

Dos avisos sobre este script:

- La contraseña se ve en pantalla. En Windows no existe `stty -echo`, y el
  script lo dice en vez de fingir que la oculta.
- Mínimo 12 caracteres. Es una cuenta que ve credenciales de pasarela.

**Si vas a hacer el cierre de la Etapa 3, crea también el usuario de Pedro con
rol 2 (`abogado`)**: hay comprobaciones que exigen ver el panel con los dos
roles, y algunas cosas —activar un prompt, ascender un modelo— solo las puede
hacer él.

---

## 5. Levantar el servidor

### Opción A — Laragon (la normal)

Laragon crea un host virtual por carpeta. Con el proyecto en
`C:\laragon\www\PAduanero`, la URL es **`http://padu.test`** o
`http://pAduanero.test` según cómo tengas configurado el formato de nombre en
Laragon.

Tres cosas tienen que estar bien:

1. **Apache**, no Nginx. Menú → Apache. El `.htaccess` del proyecto es lo que
   reescribe las rutas hacia `index.php`, y Nginx no lo lee.
2. **`mod_rewrite` activo y `AllowOverride All`** en el vhost. Laragon lo trae
   así por defecto. Si al entrar a `/panel/entrar` ves un 404 de Apache en vez
   del formulario, es esto.
3. **Recargar los hosts virtuales** tras crear la carpeta: Menú → Apache →
   Recargar, o Menú → **Recargar todo**.

Si no sabes qué URL te asignó: Menú → **www** → aparece la lista de proyectos
con su enlace.

### Si el navegador dice que no encuentra el sitio

No es un problema de la aplicación: es que **Laragon no ha registrado el
proyecto todavía**. Los vhosts los genera al arrancar o al recargar, así que
una carpeta añadida después no existe para Apache. Se comprueba en dos sitios:

```powershell
# ¿Existe el vhost?
Get-ChildItem C:\laragon\etc\apache2\sites-enabled\auto.*.conf | Select-Object Name

# ¿Existe la entrada de hosts?
Select-String -Path C:\Windows\System32\drivers\etc\hosts -Pattern "aduanero"
```

Si falta alguno de los dos, **Menú → Recargar todo** los crea. Laragon
necesita permisos de administrador para escribir en `hosts`; si arrancó sin
ellos, ciérralo y ábrelo como administrador.

### Opción B — servidor embebido (si Laragon da problemas)

```powershell
php -S 127.0.0.1:8123 bin/servidor-dev.php
```

Y entra en `http://127.0.0.1:8123`. Recuerda poner `APP_URL=http://127.0.0.1:8123`
en el `.env`.

El router `bin/servidor-dev.php` existe porque el servidor embebido no lee el
`.htaccess`: sin él, `/img`, `/css` y `/js` devuelven 404 y la landing se ve
sin estilos.

### Comprobar que responde

```powershell
curl http://padu.test/salud
```

Debe devolver `{"ok":true,"base_datos":"arriba",...}`. Si dice que la base no
responde, el problema está en el `.env` o en MySQL, no en el servidor web.

---

## 6. Entrar por primera vez

1. Abre **`/panel/entrar`**.
2. Correo y contraseña de tu usuario.
3. Te lleva a **`/panel/verificar`**, y de ahí **directo a
   `/panel/seguridad`**: el rol exige segundo factor pero todavía no lo tienes
   activo, así que te deja pasar para activarlo. Bloquearte ahí dejaría a
   Pedro fuera de su propio panel el primer día.
4. En **Seguridad de mi cuenta**, pulsa preparar el segundo factor. Sale un
   **secreto en base32** para escribir a mano en Google Authenticator, Authy o
   1Password (no hay QR: generarlo obligaría a meter una librería de imágenes
   o a mandar el secreto a un servicio externo).
5. Introduce un código de la app para confirmarlo. **Hasta que confirmes, el
   segundo factor no queda activo** — si te equivocas al copiar el secreto, no
   te quedas fuera.
6. A partir del siguiente inicio de sesión te pedirá el código de seis dígitos.

**Si pierdes el teléfono:** `php bin/restablecer-2fa.php` desde la consola del
servidor. Está en `docs/RUNBOOK.md` §3.8.

---

## 7. Dónde está cada cosa

| Qué | Ruta | Quién |
|---|---|---|
| Tablero | `/panel` | todos |
| Agenda, precio y horarios | `/panel/tarifas` | abogado, super_admin |
| Pasarela de pago y sus llaves | `/panel/pagos` | super_admin |
| **Proveedores y modelos de IA** | `/panel/ia` | super_admin (ver: abogado) |
| **Credenciales de IA (Anthropic)** | `/panel/ia`, sección «Credenciales de los proveedores» | super_admin |
| **Prompts del bot** | `/panel/prompts` | editar: ambos · activar: **solo abogado** |
| Configuración general | `/panel/configuracion` | ambos (por fila) |
| Usuarios | `/panel/usuarios` | super_admin |
| Bitácora | `/panel/auditoria` | ambos |
| Mi segundo factor | `/panel/seguridad` | todos |

**La credencial del LLM va en `/panel/ia`, no en `/panel/pagos`.** El módulo de
pagos solo conoce pasarelas de cobro —Wompi, Bold, MercadoPago—; una llave de
LLM no es un pago y quien la busque ahí no la encuentra. El mecanismo de
guardado es el mismo (AES-256-GCM, auditoría, y el valor no vuelve a salir por
HTTP), pero la pantalla está donde corresponde.

---

## 8. Poner en marcha el motor

En este orden, porque cada paso depende del anterior.

### 8.1 Guardar la llave de Anthropic — `/panel/ia`

Sección **«Credenciales de los proveedores»**, fila de Anthropic. Pega la llave
y guarda. Verás una máscara; el valor no vuelve a mostrarse.

Pulsa **«Sincronizar ahora»** justo después: es la comprobación de que el
proveedor acepta la llave. Si sale mal, lo dice en la tabla de proveedores con
el motivo.

### 8.2 Verificar el costo — `/panel/ia`

En la ficha de `claude-opus-5`, los campos **Entrada** y **Salida** vienen
precargados con `5` y `25`. **Confírmalos contra la página de precios de
Anthropic** y pulsa «Guardar costo».

Se teclea porque ningún proveedor publica el precio en su endpoint de modelos,
y sin él el corte por presupuesto mensual no corta: a coste cero, un
presupuesto no se agota nunca.

### 8.3 Activar el modelo — `/panel/ia`

Botón **«Activar»** en la misma ficha. Está bloqueado hasta que el costo esté
verificado.

### 8.4 Correr el conjunto dorado — consola

```powershell
php bin/correr-dorado.php --prompt=1
```

Imprime caso por caso y, cuando alguno falla, la respuesta completa del modelo.
Al final registra el resultado en `modelos_ia` y dice cuánto costó la vuelta.

Si se corta por presupuesto, **no registra nada**: una corrida que no pudo
hablar con el modelo no es una corrida en rojo, es una corrida que no ocurrió.

Para iterar sobre un caso concreto sin pagar los otros:

```powershell
php bin/correr-dorado.php --prompt=1 --caso=plazo-01
```

### 8.5 Activar el prompt — `/panel/prompts`, **con la cuenta del abogado**

Botón **«Activar esta versión»**. Está bloqueado hasta que el dorado salga en
verde contra el modelo que va a hablar.

### 8.6 Ascender el modelo — `/panel/ia`, **con la cuenta del abogado**

Botón **«Hacer primario»**. Es `ia.modelos.promover`, que el `super_admin` no
tiene: lo que el abogado firma no es la calidad técnica del modelo, es la
responsabilidad profesional de lo que el bot diga desde ese momento.

---

## 9. Si algo no arranca

| Síntoma | Causa habitual |
|---|---|
| `php` no se reconoce | El PATH. Ver §0. |
| 503 y página en blanco | Falta `MASTER_KEY` o `PEPPER_TELEFONO`. El detalle va al log de PHP, nunca al navegador: decir qué variable falta es decirle a un atacante qué buscar. |
| 404 de Apache en `/panel/entrar` | `mod_rewrite` apagado o `AllowOverride` distinto de `All`. |
| La landing sin estilos | Estás con el servidor embebido sin `bin/servidor-dev.php`. |
| Entras y vuelves al formulario, sin error | `APP_ENV` distinto de `desarrollo` sobre `http://`. La cookie se marca `Secure` y el navegador la descarta. |
| «DB_NAME debe terminar en _pruebas» al correr las pruebas | Falta la base `pedro_pruebas` o el `.env.pruebas` apunta mal. |
| El bot escala en vez de responder | Es lo correcto si falta cualquiera de los pasos del §8: sin modelo autorizado o sin prompt activo, el motor escala a humano en vez de improvisar. |

---

## 10. Comprobar que todo está bien

```powershell
composer test
```

439 pruebas. Si alguna falla en una instalación nueva, no sigas: es un problema
del entorno, no del código.
