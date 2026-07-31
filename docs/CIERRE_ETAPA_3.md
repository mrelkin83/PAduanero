# CIERRE DE LA ETAPA 3 — Panel: configuración y tarifas

> **Estado: código completo. No cerrada.**
> Ver `PLAN_BUILD.md` §Dos estados por etapa.
>
> **Criterio de cierre:** Pedro entra al panel, guarda las credenciales de
> Wompi, pulsa **Probar conexión** y obtiene verde. La bitácora registra cada
> cambio con su autor.
>
> El verde depende de credenciales de comercio reales, que son del PO. Todo lo
> demás está construido y probado.

---

## 0. Qué está verificado ya, y cómo

| Comprobación | Cómo |
|---|---|
| TOTP correcto | Los 6 vectores oficiales del RFC 6238 §B |
| Base32 | Los vectores del RFC 4648 §10, más minúsculas, espacios, guiones y relleno |
| Antirreplay | Un código no entra dos veces (RFC 6238 §5.2) |
| Tiempo constante | Guarda en el propio código fuente contra volver a `===` |
| Tope del segundo factor | Por cuenta, no por IP: rotar de salida no lo esquiva |
| Probador de Wompi | 15 pruebas con respuestas grabadas del sandbox |
| Matriz de roles | Las dos asimetrías del ADR-007, contra la base |
| Bloqueo y rate limit | Por cuenta y por IP, con espera creciente |
| La credencial nunca sale en claro | Recorrido en navegador real y en pruebas |
| Cookie de sesión HttpOnly + SameSite | Recorrido en navegador real |
| CSRF | POST sin token → 419, idem |

```bash
composer test:criticas                 # nivel 1
node bin/verificar-panel.mjs           # recorrido en navegador real
```

**225 pruebas, 447 aserciones.** Cobertura medida contra los objetivos de
`PRUEBAS.md` §7: Credenciales **100 %**, Pagos **100 %**, Repositorios
**87 %**, Panel **82 %**. El Motor llega en la Etapa 4.

Los códigos que distingue el probador de Wompi (`422` con llave desconocida en
`/merchants`, `401` sin token y `422` con token inválido en `/transactions`)
se midieron contra el sandbox público **una vez**, y desde entonces viven como
respuestas grabadas: **la suite no sale a la red**. Un mantenimiento de Wompi
no puede poner las pruebas en rojo.

---

## 1. Puesta en marcha

```bash
cd /var/www/pedro
git pull --ff-only
composer install --no-dev --optimize-autoloader
php bin/migrar.php
npm run build                     # en la máquina de desarrollo, y commitear
```

El panel vive en `panel.pedroabogadoaduanero.com` (`RUNBOOK.md` §4bis).

Primer usuario, una sola vez y por consola — el panel no puede crear su propio
primer administrador porque nadie podría entrar a usarlo:

```bash
php bin/crear-usuario.php          # rol super_admin
```

Y el cron de purga, que no es opcional:

```cron
30 4 * * *  php /var/www/pedro/bin/cron-purgar.php
```

---

## 2. Lista de verificación manual

### Acceso y segundo factor

- [ ] `/panel` sin sesión redirige a `/panel/entrar`.
- [ ] Entrar con el `super_admin` recién creado.
- [ ] Al entrar **lleva a Seguridad y exige activar 2FA**: es obligatorio para
      `super_admin` y `abogado` (`PANEL_ADMIN.md` §4.1).
- [ ] Añadir la clave a la aplicación de autenticación **a mano** (no hay QR:
      generarlo exige una dependencia nueva, ver §4) y confirmar con el código.
- [ ] Cerrar sesión, volver a entrar: ahora **pide el código** en un segundo paso.
- [ ] **Reutilizar el código que acaba de usar** para entrar, dentro de sus 30
      segundos de vida: debe **rechazarlo**. Es el antirreplay del RFC 6238
      §5.2 — sin él, un código visto por encima del hombro sirve medio minuto.
- [ ] Meter un código equivocado cinco veces y comprobar que **bloquea**, con
      la espera creciendo en cada intento.

### Recuperación — probar ANTES de necesitarla

- [ ] Desde el servidor: `php bin/restablecer-2fa.php <su-correo>`.
- [ ] Confirmar escribiendo el correo completo y un motivo.
- [ ] Entrar de nuevo con la contraseña de siempre: el panel **obliga a
      reconfigurar** el segundo factor.
- [ ] Bitácora: aparece `totp_restablecido` con el actor de consola y el motivo.

> Probar esto un martes por la tarde cuesta cinco minutos. Descubrirlo un
> domingo con Pedro fuera del panel cuesta mucho más. Procedimiento completo
> en `RUNBOOK.md` §3.8.

### Tarifas — el corazón de la etapa

- [ ] Agenda y tarifas muestra la modalidad sembrada en **$400.000**.
- [ ] Cambiar el precio a `450000` y guardar. Confirmar que **no hizo falta
      tocar código ni desplegar**.
- [ ] Intentar guardar `40000000` (centavos donde van pesos): debe **rechazarlo**
      avisando de que el precio va en pesos.
- [ ] Devolverlo a `400000`.
- [ ] Bitácora: los dos cambios aparecen con **precio antes y después**, y con
      el correo de quien los hizo.

### Configuración

- [ ] El formulario se pinta solo desde las filas de `configuraciones`.
- [ ] Cambiar `minutos_reserva_pago` a `9999`: lo **rechaza** por rango, con el
      mensaje de la propia fila.
- [ ] Cambiarlo a `120` con un motivo escrito. Aparece en la Bitácora,
      pestaña de cambios de configuración, con el motivo.
- [ ] Con un usuario `abogado`: las filas marcadas `super_admin`
      (`pasarela_activa`, `llm_pais_permitido`…) salen **deshabilitadas**.

### Pagos — el criterio de cierre

- [ ] Con un usuario `abogado`: la sección de credenciales **no aparece**, y en
      su lugar hay un aviso. Es deliberado (ADR-007).
- [ ] Con el `super_admin`: guardar las **cuatro llaves de sandbox** de Wompi.
- [ ] Cada una muestra solo la **máscara**. Recargar: sigue sin verse el valor.
- [ ] Ver código fuente de la página: **buscar la llave privada completa. No
      debe aparecer.**
- [ ] **Probar conexión** en Pruebas → **verde**, con el nombre del comercio.
- [ ] Pegar a propósito una llave de producción en el entorno de pruebas:
      debe avisar de que **el prefijo no corresponde al entorno**, sin salir a
      la red.
- [ ] Guardar las llaves de **producción** y probar → **verde**.
      **← Este es el criterio de cierre.**
- [ ] Copiar la URL del webhook y pegarla en el panel de Wompi.
- [ ] Bitácora: hay entradas `credencial / guardar` y `credencial / probar`, y
      **ninguna contiene el valor**.

### Usuarios

- [ ] Crear un usuario con rol `abogado`. Si Chatwoot ya está desplegado, se
      crea también su agente y aparece el id.
- [ ] Si Chatwoot no está disponible, **el usuario se crea igual** y avisa. Es
      deliberado: que no se pueda crear un usuario del panel porque Chatwoot
      esté caído sería acoplar dos sistemas que no tienen por qué caerse juntos.
- [ ] Contraseña de menos de 12 caracteres: rechazada.
- [ ] Correo repetido: rechazado con mensaje claro.

### Licencia

- [ ] El pie del panel muestra el aviso de uso de **Evolution API**. Lo exige
      la cláusula 1.b de su LICENSE (`CLAUDE.md` §1.3). **No se quita.**

---

## 3. Al terminar

Con todas las casillas marcadas, la Etapa 3 queda **cerrada** y se puede pasar
a la Etapa 4 — que sí necesita, además, la Etapa 2 cerrada, porque el motor
necesita los canales vivos.

---

## 4. Decisiones que conviene conocer

**Sin código QR para el TOTP.** Generarlo exige una dependencia nueva
(`endroid/qr-code`), y las dependencias necesitan aprobación del PO. Todas las
aplicaciones de autenticación permiten introducir la clave a mano. Si se
prefiere el QR, es aprobar el paquete y una tarde de trabajo.

**El TOTP va escrito en el proyecto, no con librería.** `docs/CONTRATOS.md`
prohíbe el cifrado casero — y con razón, pero esto no es cifrado: es una
función derivada de `hash_hmac`, especificada en el RFC 6238, que publica
vectores de prueba oficiales. Los seis están en la suite. Si se prefiere
`spomky-labs/otphp`, el cambio es de una clase y las pruebas siguen valiendo.

**Un contrato ampliado, sin tocar `Credenciales`.** El listado de credenciales
del panel va por `CredencialRepo`, cuyo `SELECT` **no incluye
`valor_cifrado`**. Así, por esa ruta, es estructuralmente imposible que un
secreto llegue a una plantilla: no hay que acordarse de no imprimirlo, es que
no está.
