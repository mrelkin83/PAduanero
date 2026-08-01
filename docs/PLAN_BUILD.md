# PLAN DE CONSTRUCCIÓN PARA CLAUDE CODE

> Orden no negociable. Cada etapa termina con un criterio verificable. **No se
> avanza a la siguiente sin cerrar la anterior.** Al arrancar cada sesión, Claude
> Code debe leer `CLAUDE.md`, `docs/CONTRATOS.md` y este archivo.

## Dos estados por etapa

Varias etapas dependen de credenciales de terceros o de infraestructura que
solo el PO puede tocar. Eso no es una excepción ni un bloqueo: es cómo son
estas etapas. Se distinguen dos estados, con dueños distintos:

| Estado | Qué significa | Quién lo declara |
|---|---|---|
| **Código completo** | Todo el artefacto de repositorio hecho, probado y verificable | Claude Code |
| **Etapa cerrada** | El criterio de cierre verificado en el entorno real | El PO, tras ejecutar la lista manual |

Aplica a las etapas **2, 3, 5 y 6**. Las etapas 0, 1, 4, 7 y 8 se cierran de
una sola vez porque no dependen de nada externo.

Cada etapa con dos estados entrega una **lista de verificación manual** con
casillas, y donde sea posible un script que compruebe la parte automatizable
(`bin/verificar-canales.php` para la 2, y el equivalente en cada una).

Consecuencia práctica: «código completo» no autoriza a pasar a la etapa
siguiente si esta depende funcionalmente de la anterior. La Etapa 3 se puede
construir sobre una Etapa 2 no cerrada porque no la necesita corriendo; la
Etapa 4 sí necesita los canales vivos.

---

## Regla de supervisión

Requieren aprobación explícita del PO antes de ejecutarse:
cambios de esquema, nuevas dependencias de Composer, cambios en contratos de
servicios, cualquier cosa que toque `src/Servicios/Credenciales`, y el paso de
modo sombra a envío automático.

---

**Son nueve etapas: de la 0 a la 8.** Las tres primeras (0, 1, 2) ya entregan
valor y no dependen de nada que Pedro tenga pendiente.

---

## Etapa 0 — Cimientos (sin lógica de negocio)

Estructura del repo con `index.php` en la raíz, front controller y router propios,
`composer.json` (PSR-4, sin framework), conexión PDO a MySQL, migraciones desde
`db/schema.sql`, `db/schema_admin.sql` y `db/seeds.sql`, logger con redacción de
PII, `App\Servicios\Config`, `App\Soporte\Fechas`, y
**`App\Servicios\Credenciales` con sus pruebas**.

Docker solo para Chatwoot, Evolution y MySQL. La aplicación PHP corre directo
sobre el VPS con PHP-FPM + Nginx.

**Cierre:** las migraciones corren limpias sobre MySQL 8, la app responde en `/`,
y existe una prueba que guarda una credencial, la recupera descifrada y verifica
que la respuesta HTTP solo devuelve la máscara. Sin `MASTER_KEY`, la app no
arranca — eso también se prueba.

Decisiones cerradas al arrancar esta etapa (unidades de dinero, formato del blob
cifrado, pepper del teléfono, migraciones, radicado, concurrencia de la reserva):
`CLAUDE.md` §12, ADR-010 a ADR-015.

---

## Etapa 1 — Landing

Landing estática desde `landing_bloques`, botón de WhatsApp con UTMs, widget de
Chatwoot, `eventos_landing`, SEO base (sitemap, robots, datos estructurados de
`LegalService`), certificado TLS.

**Cierre:** Lighthouse ≥ 95 en rendimiento móvil, LCP < 2 s, y un clic en el botón
de WhatsApp registra el evento con su `utm_campaign`.

*Aquí ya hay valor entregado: Pedro puede empezar a recibir clientes aunque no
exista nada más.*

---

## Etapa 2 — Centralización

Evolution API desplegado con número dedicado, integración nativa con Chatwoot,
Instagram y Messenger conectados, widget web enlazado a la misma cuenta, alertas
por correo.

**Procedimiento completo: `docs/DESPLIEGUE_CANALES.md`.** Los compose están en
`infra/`, listos para copiar a `/opt/chatwoot` y `/opt/evolution`.

**Cierre:** un mensaje enviado por cada uno de los cuatro canales aparece en la
bandeja de Chatwoot y Pedro puede responder desde un solo lugar. Sin IA todavía.

La parte automatizable la comprueba `php bin/verificar-canales.php`. La otra
—enviar un mensaje real por cada canal— necesita un teléfono y las cuentas de
Meta, así que la hace una persona: lista en `DESPLIEGUE_CANALES.md` §7.

---

## Etapa 3 — Panel: configuración y tarifas

Autenticación con `password_hash(PASSWORD_ARGON2ID)` y TOTP, roles y permisos,
módulos de Configuración general, Agenda y tarifas, Pagos (credenciales + webhook),
Usuarios y auditoría. Aprovisionamiento de agentes en Chatwoot desde el alta de
usuario.

La tarifa ya viene sembrada en $400.000; el panel debe permitir cambiarla sin
tocar código, y las reservas vivas conservan el precio congelado en
`consultas.precio_cop`.

**Cierre:** Pedro entra al panel, guarda las credenciales de Wompi, pulsa **Probar
conexión** y obtiene verde. La bitácora registra cada cambio con su autor.

El verde depende de credenciales de comercio reales, así que esta etapa tiene
dos estados. Lista de verificación manual en `docs/CIERRE_ETAPA_3.md`; la
parte automatizable, en `composer test:criticas` y `node bin/verificar-panel.mjs`.

---

## Etapa 4 — Motor en modo sombra

**Aquí es donde se entrega `motor/index.js`**, y solo aquí. Es código Node de otro
proyecto: sirve como referencia conceptual de la máquina de estados, el system
prompt y el despacho de acciones. Se traduce a `App\Motor\MotorConversacional`,
no se copia.

`App\Repositorios\*`, `App\Servicios\*` según contratos, el motor, el worker del
outbox (`bin/worker-outbox.php` bajo systemd) y el webhook de Chatwoot.
`motor_modo_sombra = true`: la IA redacta como **nota privada**, no envía nada.

**Cierre:** 30 conversaciones reales procesadas. Pedro revisa las 30 notas privadas.
Cero violaciones de las reglas inviolables (§4 del CLAUDE.md) — sobre todo cero
menciones de plazos, términos o normas numeradas. Si aparece una sola, se ajusta
el prompt y se reinicia el conteo.

### Condiciones del PO, desde el primer commit

Esta es la etapa donde el proyecto deja de parecerse a los anteriores. Hasta
aquí todo era verificable de forma binaria: una migración corre o no corre, un
webhook valida o no valida. A partir de ahora el criterio de cierre es que una
persona lea treinta respuestas y no encuentre una sola violación.

1. **`motor_modo_sombra` arranca en `true` y no lo cambia Claude Code.** Ni
   para probar. El paso a envío automático es la Etapa 6 y lo autoriza el PO.
2. **`motor/index.js` es referencia conceptual, no fuente a traducir.** Se
   reescribe idiomático en PHP. Señales de que se está transliterando en vez
   de reescribiendo: aparece `async`, promesas emuladas, o un método llamado
   `process()`.
3. **La regla 14 (R1 vs R5) va desde el principio, no como parche.** En un
   escalamiento sin consentimiento vigente se persiste teléfono, motivo, marca
   de tiempo y `chatwoot_conv_id`. **Cero contenido del mensaje.**

Y el catálogo de tipos de caso: **`CLAUDE.md` §5 es normativo**. El array
`TIPOS_CASO` del `index.js` tiene 21 valores y ninguno tributario — ese es el
que está desactualizado, no la especificación.

**Arranca cuando el PO confirme el verde de Wompi** (`docs/CIERRE_ETAPA_3.md`).

---

## Etapa 5 — Cobro y agenda

Reserva con expiración, generación del enlace de pago, webhook con verificación de
firma, confirmación automática, recordatorios, `expirarVencidas` en cron.

**Cierre:** un pago real de prueba recorre el ciclo completo: reserva → enlace →
pago → `pagada` → confirmación en WhatsApp → recordatorio a 24 h. Y un webhook
duplicado no confirma dos veces.

### Deuda anotada, con disparador

`eventos_outbox.disponible_en` tiene **dos significados según el estado**: en
`pendiente` es cuándo el evento estará listo; en `procesando` es cuándo se
reclamó. Se reutilizó así para no pedir un cambio de esquema por algo que se
resolvía sin él, y está documentado en `docs/CONTRATOS.md`.

El riesgo es concreto: la siguiente consulta que razone sobre esa columna se
puede escribir mal sin que nada falle en rojo. Ya pasó una vez en `bin/salud.sh`,
que medía el atasco contra `creado_en`.

**Disparador:** si en esta etapa aparece una segunda consulta que necesite
razonar sobre `disponible_en` —recordatorios, reintentos de pasarela, cualquier
cosa que mire la cola— se añade la columna `tomado_en` y se deja de sobrecargar.
Una columna nueva es más barata que la documentación que hay que recordar leer.

---

## Etapa 6 — Envío automático

Se apaga el modo sombra. Kill switch probado. Escalamiento a humano probado en los
seis motivos. Horario del bot activo.

**Cierre:** una semana en producción sin intervención correctiva.

---

## Etapa 7 — Conocimiento jurídico

`App\Servicios\BaseConocimiento` operativo (embeddings en `JSON`, prefiltro
FULLTEXT y coseno en PHP — **no `pgvector`**, ver ADR-005), carga de los 130+
escenarios, cola de verificación, buscador de prueba en el panel.

**Cierre:** los 130 escenarios verificados por Pedro. Muestreo de 50 respuestas del
bot con cero violaciones de §4.

---

## Etapa 8 — Contenido, métricas y campañas

CMS de artículos, tablero de métricas, atribución por UTM, integración con Meta
Ads y Google Ads.

**Cierre:** el tablero muestra costo por lead y tasa de conversión a asesoría
pagada, por canal.

---

## Prompt inicial sugerido para Claude Code

```
Lee CLAUDE.md, docs/CONTRATOS.md, docs/PANEL_ADMIN.md, docs/PLAN_BUILD.md
y db/*.sql completos antes de escribir nada.

Stack cerrado: PHP 8.2+, MySQL 8, TailwindCSS, JavaScript vanilla con fetch.
Sin frameworks, sin ORM. index.php en la raíz.

Vamos por la Etapa 0. Antes de generar código, dame:
  1. El árbol de archivos que vas a crear.
  2. La lista de dependencias de Composer, con justificación de una línea cada una.
  3. Cualquier ambigüedad que encuentres en los contratos.

No escribas código hasta que yo apruebe esos tres puntos.
```

Ese freno importa: sin él, la primera sesión genera cuarenta archivos y la mitad
no cuadra con los contratos.

---

## Qué NO debe construir Claude Code

- Bandeja de conversaciones, chat en vivo, gestión de agentes → **eso es Chatwoot**.
- Cliente de WhatsApp propio → **eso es Evolution**.
- Sistema de plantillas de correo desde cero → librería existente.
- Su propio cifrado → `openssl_encrypt` con AES-256-GCM, nada casero.
- Un ORM o un framework → PDO y clases propias en `src/Repositorios/`.
- Una traducción literal del `index.js` → se reescribe idiomático en PHP.
