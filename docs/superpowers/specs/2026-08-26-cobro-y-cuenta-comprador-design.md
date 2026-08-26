# Cobro con Wompi y cuenta de comprador — sub-proyectos 2a+2b (fusionados)

**Fecha:** 2026-08-26 · **Estado:** diseño aprobado en chat, pendiente de escribir el plan de implementación
**Relación con el resto del módulo de cursos:** este documento cubre el cobro real
(Wompi) y la cuenta de comprador que se crea justo después de pagar. **No cubre**
la entrega del contenido protegido de las lecciones (video/archivos) ni el
certificado de finalización — esos quedan como piezas 3 y 4, cada una con su
propio spec cuando llegue el momento (ver §0).

## 0. Cómo se llegó a este alcance

El pedido original para "sub-proyecto 2" era solo cobro. Al diseñar qué pasa
después de que Wompi confirma un pago, el PO pidió que el acceso se habilite
de inmediato — lo cual no tiene sentido sin una cuenta a la que habilitarle
nada. Al diseñar la cuenta, el PO pidió también recolectar documento de
identidad "para el certificado, como Udemy" — lo cual reveló una cuarta pieza
(certificado de finalización) que a su vez depende de saber cuándo un curso
está "completado", que a su vez depende de que exista contenido real
(sub-proyecto 3, hoy solo tiene títulos y duración).

La descomposición completa del módulo, de la que este documento cubre las dos
primeras piezas:

| Pieza | Qué hace | Depende de | Estado |
|---|---|---|---|
| 1 — Catálogo | Cursos, categorías, temario, panel | — | **Hecho y desplegado** |
| **2a — Cobro** | `compras_curso`, checkout con Wompi, webhook, confirmación automática | Reusa infraestructura existente | Este documento |
| **2b — Cuenta de comprador** | Registro/login post-pago, `/mis-cursos` | 2a | Este documento |
| 3 — Contenido protegido | Video/archivo real de las lecciones, control de acceso | 2b | Spec futuro |
| 4 — Certificado | PDF con nombre/documento al completar un curso | 3 (necesita saber qué es "completar") | Spec futuro |

## 1. Decisión que reabre una puerta cerrada — Wompi, otra vez

Ya se documentó en el spec del catálogo (2026-08-26, §1) que el PO confirmó
pagos reales con Wompi para cursos, revirtiendo el retiro de pasarela del
2026-08-17. Este documento es donde esa decisión se hace concreta.

**Hallazgo central de esta sesión:** el motor de WhatsApp v2 ya trae dos
piezas reusables, genuinamente independientes de la conversación de chat:

- `ElkinLinan\WhatsappAiEngine\Payments\WompiAdapter` — cliente de Wompi
  verificado contra la API real de sandbox (2026-08-13), con trampas reales ya
  documentadas en su propio código: los enlaces de pago (`payment_links`)
  **descartan la referencia que se les manda y generan la suya**, y esa
  referencia **rota en cada sesión de checkout** — un pago real quedó
  invisible por esto el 2026-08-23. Implementa `PaymentAdapterInterface`
  (`nombre()`, `requisitosFaltantes()`, `crearCobro()`, `consultar()`,
  `verificarWebhook()`), el mismo patrón de puertos que ya usa el proyecto
  para proveedores de IA.
- `ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient::enviarTexto(string $telefono, string $texto)`
  — manda un WhatsApp a cualquier número, sin necesitar conversación previa.

Ambas clases están en el namespace `ElkinLinan\WhatsappAiEngine\`, mapeado en
el `composer.json` principal (no es una dependencia aislada) — se pueden usar
desde cualquier controlador del sitio.

**Las credenciales de Wompi son una sola fila** (`wa_config.id = 1`, motor
single-tenant): es la cuenta de comercio de Pedro, no algo por-instancia. Este
módulo reusa esa misma fila — Pedro no configura Wompi dos veces.

**El webhook también se reusa, no se crea uno nuevo.** Las pasarelas de pago
suelen permitir una sola URL de eventos por cuenta de comercio. Como cursos
usa la misma cuenta de Wompi que las citas, los eventos de pago de cursos
llegarán al webhook que ya existe (`POST /api/wa/pago/{token}`,
`App\Wa\WebhookControlador::pago()`), no a uno nuevo. Ese método se extiende
de forma aditiva: después de verificar la firma, revisa si la referencia
corresponde a una `compras_curso` pendiente antes de entregarle el evento al
`PaymentManager` del motor de citas. Si no es una compra de curso, el camino
existente de citas sigue exactamente igual.

## 2. Alcance de este documento

Incluye:
- Checkout de un curso: formulario corto (nombre, correo) → Wompi → confirmación.
- Webhook de Wompi extendido para reconocer pagos de cursos.
- Aviso automático a Pedro por WhatsApp cuando se confirma un pago.
- Cuenta de comprador: registro (con documento de identidad cifrado, para el
  futuro certificado), login, recuperación de contraseña por correo.
- Vinculación de una compra nueva a una cuenta ya existente (mismo correo).
- Página `/mis-cursos`: lista de cursos pagados, con su temario en modo
  solo-lectura (sin contenido).
- Pantalla de panel para ver compras y aprobar una a mano si el webhook falla.

No incluye:
- Contenido real de las lecciones (video/archivo) ni su protección — pieza 3.
- Certificado de finalización ni seguimiento de progreso — pieza 4.
- Facturación electrónica (el documento de identidad se guarda para el
  certificado, no se emite factura DIAN en este alcance).
- Reembolsos o cancelaciones de compra.

## 3. Decisiones tomadas (resumen)

| Punto | Decisión |
|---|---|
| Credenciales de Wompi | Reusar `wa_config` (misma cuenta de comercio) |
| Cliente de Wompi | Reusar `WompiAdapter` vía `PaymentAdapterInterface`, sin reescribirlo |
| Webhook | Reusar `POST /api/wa/pago/{token}` existente, extendido de forma aditiva |
| Datos pedidos antes de pagar | Nombre y correo (formulario corto en el sitio) |
| Tras el pago confirmado | Automático: (a) WhatsApp a Pedro vía `EvolutionClient`, (b) correo al comprador con enlace de un solo uso para completar registro o iniciar sesión |
| Documento de identidad | Sí se recolecta (cifrado, ADR-011) — es para el futuro certificado de finalización (pieza 4), no para facturación |
| Comprador que ya tiene cuenta | Se le pide iniciar sesión (no registrarse de nuevo); la compra nueva se vincula a su cuenta existente por correo |
| Qué ve el comprador en su cuenta | Lista de sus cursos pagados con temario en solo-lectura, sin contenido (pieza 3 lo activa después) |
| Recuperar contraseña | Sí, por correo, desde el día uno — usa el `Smtp` que ya existe en el proyecto |
| Sesión de comprador | Completamente separada de `usuarios`/`sesiones` del panel: sin roles, sin 2FA |
| Redirección post-pago | Se agrega un 5º parámetro opcional `?string $redirectUrl = null` a `WompiAdapter::crearCobro()` (con default que no cambia el comportamiento actual de citas) |

## 4. Modelo de datos

Migración aditiva `db/migraciones/0030_cobro_y_cuentas_comprador.sql`:

```sql
CREATE TABLE compras_curso (
  id                CHAR(36)     NOT NULL DEFAULT (UUID()),
  curso_id          CHAR(36)     NOT NULL,
  comprador_id      CHAR(36)     NULL,       -- nula hasta completar registro/login
  nombre            VARCHAR(150) NOT NULL,   -- dato del formulario corto, antes de tener cuenta
  correo            VARCHAR(180) NOT NULL,
  precio_cop        BIGINT       NOT NULL,   -- congelado al comprar, igual razón que consultas.precio_cop
  referencia_wompi  VARCHAR(120) NULL,       -- la que WOMPI genera, no la que se le manda (ver §1)
  externo_id        VARCHAR(120) NULL,       -- id del payment_link, respaldo de emparejamiento
  estado            ENUM('pendiente','pagada','fallida') NOT NULL DEFAULT 'pendiente',
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagado_en         DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_compras_curso (curso_id, estado),
  KEY ix_compras_referencia (referencia_wompi),
  CONSTRAINT fk_compras_curso FOREIGN KEY (curso_id) REFERENCES cursos(id),
  CONSTRAINT fk_compras_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE compradores (
  id                        CHAR(36)     NOT NULL DEFAULT (UUID()),
  nombres                   VARCHAR(150) NOT NULL,
  apellidos                 VARCHAR(150) NOT NULL,
  tipo_documento            ENUM('CC','CE','PASAPORTE','NIT') NOT NULL,
  numero_documento_cifrado  VARBINARY(255) NOT NULL,   -- App\Soporte\Cifrado, formato ADR-011
  celular                   VARCHAR(20)  NOT NULL,
  correo                    VARCHAR(180) NOT NULL,
  password_hash             VARCHAR(255) NOT NULL,     -- Argon2id, igual que usuarios del panel
  creado_en                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_compradores_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE compradores_sesiones (
  id            CHAR(36)  NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)  NOT NULL,
  token_hash    CHAR(64)  NOT NULL,   -- SHA-256 del token en claro; el token nunca se guarda
  creado_en     DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_sesiones_token (token_hash),
  CONSTRAINT fk_sesiones_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE compradores_enlaces (
  id            CHAR(36)     NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)     NULL,       -- null cuando el enlace es "completar registro" (aún no existe la cuenta)
  compra_id     CHAR(36)     NULL,       -- la compra que originó el enlace, si aplica
  tipo          ENUM('completar_registro','reset_password') NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME     NOT NULL,
  usado         TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY ux_enlaces_token (token_hash),
  CONSTRAINT fk_enlaces_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id) ON DELETE CASCADE,
  CONSTRAINT fk_enlaces_compra FOREIGN KEY (compra_id) REFERENCES compras_curso(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

`compradores_enlaces` cubre los dos tipos de enlace de un solo uso (completar
registro tras pagar, y recuperar contraseña) en una sola tabla — misma forma,
mismo ciclo de vida (se crean, se usan una vez, expiran).

## 5. Cambios a código existente (fuera de este módulo, aditivos)

- **`WompiAdapter::crearCobro()`** (en `packages/whatsapp-engine`): se le
  agrega un quinto parámetro opcional `?string $redirectUrl = null`. Si viene,
  se incluye como `redirect_url` en el cuerpo del `POST /payment_links`. El
  cobro de citas no pasa este parámetro, así que su comportamiento no cambia.
- **`App\Wa\WebhookControlador::pago()`**: antes de la línea que llama a
  `PaymentManager::aplicarWebhook($v)`, se agrega una comprobación: si
  `$v['referencia']` (o el `payment_link_id` de respaldo) coincide con una
  fila de `compras_curso` en estado `pendiente`, se maneja ahí mismo (marcar
  `pagada`, avisar a Pedro, generar el enlace de registro) y se retorna sin
  tocar el camino de citas.

## 6. Flujo público

```
GET  /cursos/{slug}/comprar     → formulario: nombre, correo
POST /cursos/{slug}/comprar     → crea compras_curso (pendiente),
                                   WompiAdapter::crearCobro(), redirige (302) a Wompi
GET  /cursos/{slug}/gracias     → página informativa tras volver de Wompi
                                   (consulta WompiAdapter::consultar() solo para
                                   mostrar un estado aproximado; NUNCA es la fuente
                                   de verdad — esa es siempre el webhook)

GET  /mis-cursos/completar      → ?token=... de compradores_enlaces (tipo completar_registro)
                                   Si el correo de la compra ya tiene cuenta: pide
                                   iniciar sesión. Si no: formulario de registro
                                   (nombres, apellidos, tipo/número de documento,
                                   celular, contraseña — correo ya viene fijo).
                                   Al completar: vincula compras_curso.comprador_id,
                                   crea sesión, redirige a /mis-cursos.

GET  /entrar                    → login general (correo + contraseña)
POST /entrar
POST /salir

GET  /recuperar                 → pide correo, crea un enlace tipo reset_password
POST /recuperar                   y lo manda por correo (Smtp::enviar)
GET  /recuperar/confirmar       → ?token=..., pide contraseña nueva
POST /recuperar/confirmar

GET  /mis-cursos                → (requiere sesión) cursos pagados del comprador,
                                   con temario en solo-lectura
```

Cuando se confirma un pago (webhook), además de marcar `pagada`:
1. `EvolutionClient::enviarTexto()` al `handoff_numero` de `wa_config`, avisando
   nombre, correo y curso.
2. Se crea una fila en `compradores_enlaces` (tipo `completar_registro`,
   ligada a la compra) y se manda por correo vía `Smtp::enviar()` — si
   `Smtp::desdeEntorno()` devuelve `null` (SMTP sin configurar, el estado
   actual del VPS), el correo simplemente no sale y solo queda el aviso de
   WhatsApp a Pedro; no es un error, es el mismo criterio que ya usa
   `Recordatorios` para el mismo caso.

## 7. Seguridad de la cuenta de comprador

- **Contraseñas:** `password_hash($clave, PASSWORD_ARGON2ID)`, igual que
  `UsuarioRepo::crear()` para el panel.
- **Documento de identidad:** cifrado con `App\Soporte\Cifrado` (mismo
  formato ADR-011 que ya usa `usuarios.totp_secret_cifrado`). Nunca se
  devuelve descifrado a ninguna plantilla; solo se descifra cuando el
  sub-proyecto 4 (certificado) lo necesite para imprimirlo en el PDF.
- **Sesión:** token aleatorio de 256 bits, se guarda su hash SHA-256 (nunca el
  token en claro) en `compradores_sesiones`, igual patrón que el webhook del
  motor de WhatsApp usa para sus tokens de URL. Cookie `httponly`, `secure`,
  `samesite=Lax`, separada de la cookie de sesión del panel.
- **Enlaces de un solo uso** (registro y reset): mismo esquema de token +
  hash. `completar_registro` expira a las **48 horas** de creado (el
  comprador ya pagó; darle margen para revisar el correo con calma pesa más
  que acortar la ventana). `reset_password` expira a las **2 horas** (una
  recuperación de clave se usa o se pide de nuevo el mismo día). `usado` se
  marca en el primer uso — un segundo intento con el mismo token falla.
- **Separación completa de `usuarios`/`sesiones` del panel:** un comprador no
  puede autenticarse en `/panel` ni viceversa. Son dos sistemas de sesión
  independientes que comparten servidor pero no comparten tabla, cookie, ni
  código de verificación.

## 8. Panel: ver compras y respaldo manual

`GET /panel/cursos/compras` (permiso `cursos.ver`): lista de compras con
curso, nombre, correo, estado, fecha. `POST /panel/cursos/compras/aprobar`
(permiso `cursos.editar`, id por campo de formulario): marca una compra como
pagada a mano si el webhook nunca llegó — mismo patrón que `aprobarManual()`
ya tiene para los pagos de citas por transferencia. Aprobar a mano dispara el
mismo correo de "completa tu registro" que dispararía el webhook.

## 9. Manejo de errores

- **Wompi no responde al crear el enlace:** la compra queda `fallida` de
  inmediato; no se redirige a un enlace roto.
- **El webhook llega dos veces** (reintento normal de las pasarelas): se
  revisa el estado actual antes de marcar `pagada` — si ya estaba pagada, no
  se reenvía el aviso a Pedro ni se genera un segundo enlace de registro.
- **Pago rechazado:** la compra queda `fallida`; el comprador puede repetir el
  formulario de compra (sin restricción de una sola compra por correo).
- **Token de un solo uso expirado o ya usado:** mensaje claro, con opción de
  pedir uno nuevo (reenvía el correo si es de registro; en reset de
  contraseña, vuelve a `/recuperar`).
- **Alguien intenta completar registro con un correo que ya tiene cuenta,
  pero escribe mal la contraseña:** error normal de login, sin filtrar si el
  correo existe o no en el mensaje (mismo cuidado que ya tiene
  `UsuarioRepo::verificarPassword()` contra enumeración de correos).

## 10. Pruebas

Igual que en el catálogo, el punto que hace esto probable sin tocar la API
real de Wompi: el código nuevo depende de `PaymentAdapterInterface`
(inyectado), no de `WompiAdapter` en concreto. Las pruebas inyectan un
adaptador falso.

- **Checkout:** formulario crea la compra y redirige; precio en pesos, nunca
  centavos, en la llamada a `crearCobro()`.
- **Webhook (extendido):** una referencia de `compras_curso` marca `pagada`,
  genera el enlace de registro, y llama al notificador de WhatsApp (falso en
  la prueba); una referencia que no es de curso sigue el camino de citas sin
  cambios (prueba de regresión explícita sobre el comportamiento existente).
- **Webhook duplicado:** segunda llegada del mismo evento no reenvía el aviso
  ni genera un segundo enlace.
- **Registro tras pago:** con correo nuevo pide registro; con correo existente
  pide login y vincula la compra a la cuenta.
- **Login / sesión / logout.**
- **Recuperar contraseña:** token válido cambia la clave; token usado o
  vencido falla con mensaje claro; sin SMTP configurado, la solicitud no
  truena (degrada igual que `Recordatorios`).
- **`/mis-cursos`:** solo muestra compras `pagada` del comprador en sesión,
  nunca las de otro comprador ni las `pendiente`/`fallida`.
- **Panel:** `cursos.ver`/`cursos.editar` gatean listar y aprobar; aprobar a
  mano dispara el mismo enlace de registro que el webhook.

## 11. Pendiente operativo, no de diseño

- **SMTP real en el VPS** (`SMTP_HOST` vacío hoy) — sin esto, ni el correo de
  "completa tu registro" ni el de recuperar contraseña salen. No bloquea
  implementar ni probar (el `Smtp` degrada a `null` con gracia), pero sí
  bloquea que el flujo automático funcione de verdad en producción.
- **Credenciales de Wompi en modo producción** en `wa_config` — hoy están
  pensadas para el cobro de citas; falta confirmar que Pedro quiere que el
  mismo ambiente (sandbox/producción) aplique igual a cursos.
- **Política de tratamiento de datos** (ya mencionada en el spec del
  catálogo como pendiente del motor de WhatsApp) ahora es más urgente: este
  módulo guarda documento de identidad cifrado además de nombre/correo/
  celular. No se puede cobrar el primer curso sin esa política redactada y
  publicada.
