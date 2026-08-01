# PANEL ADMINISTRATIVO — Especificación

## 1. La frontera con Chatwoot

El error más caro de este proyecto sería construir una bandeja de conversaciones
propia. Chatwoot ya la tiene, con años de trabajo encima. La división es rígida:

| Vive en **Chatwoot** | Vive en el **panel propio** |
|---|---|
| Hilos de conversación, todos los canales | Configuración del sistema |
| Responder, asignar, etiquetar | Tarifas y modalidades de asesoría |
| Notas internas del equipo | Credenciales y pasarelas |
| Historial de mensajes y adjuntos | Proveedores y modelos de IA |
| Búsqueda de conversaciones | Prompts versionados |
| Perfil de contacto y atributos | Pipeline de casos y puntajes |
| Notificaciones al agente | Base de conocimiento jurídico |
| — | Contenido de la landing y artículos |
| — | Métricas de adquisición y conversión |
| — | Usuarios, roles y auditoría |

Punto de contacto: el panel muestra, en la ficha de cada caso, un enlace directo
al hilo de Chatwoot (`https://chat.pedroabogadoaduanero.com/app/accounts/1/conversations/{id}`).
No se embebe ni se replica. Un clic y estás en la bandeja.

**ADR-009 — Runtime del panel.** El panel corre en el **mismo proceso PHP** que el
motor y la landing, sobre la **misma base MySQL**. Razón: comparten
`configuraciones`, `credenciales` y la capa `src/Repositorios/`. Separarlos
obligaría a duplicar el cifrado de credenciales, la invalidación de caché y toda
la capa de datos, con dos fuentes de verdad sobre los mismos secretos.

> Este ADR decía "mismo proceso Node, mismo Postgres" y estaba marcado como
> pendiente de confirmación. Lo resolvió el **ADR-005** de `CLAUDE.md`
> (PHP 8.2+ / MySQL 8) el 2026-07-31; el argumento de no duplicar la capa de datos
> sobrevive intacto, solo cambia el runtime. Se renumeró de ADR-005 a ADR-009
> porque ese número ya estaba tomado por la decisión de stack.

---

## 2. Módulos

### 2.1 Tablero
Casos nuevos hoy, casos sin atender por antigüedad, asesorías del día, conversión
del mes, gasto de IA acumulado contra presupuesto. Alerta visible si el modo
sombra está activo o si la IA está pausada — que nunca se olvide encendida o
apagada por descuido.

### 2.2 Casos
Lista con filtros por estado, tipo, urgencia, canal y puntaje. Orden por defecto:
puntaje descendente dentro de los no atendidos. Ficha con: datos del caso, línea
de tiempo, contrapartes declaradas, consultas asociadas, pagos y enlace al hilo.
Acciones: reasignar estado, marcar fuera de alcance, descartar con motivo,
agendar manualmente sin cobro (hay casos que Pedro querrá atender de una).

### 2.3 Agenda y tarifas
CRUD de `modalidades_asesoria`: nombre, duración, **precio**, modalidad,
si requiere pago. CRUD de `horarios` semanales y `bloqueos` puntuales.
Vista de calendario con las consultas.

> El precio se define aquí, nunca en código. Al cambiarlo, las reservas ya
> creadas conservan el precio vigente al momento de reservar.

### 2.4 Pagos
Selección de pasarela activa, credenciales por entorno (pruebas/producción),
botón **Probar conexión**, URL del webhook para copiar y pegar en el panel de la
pasarela, política de reembolso, horas de cancelación sin costo.
Listado de transacciones con estado y conciliación.

### 2.5 Inteligencia artificial
- **Proveedores:** alta de proveedor (URL base, formato de API, país del servidor).
  El país es dato de cumplimiento, no adorno: si está fuera de Colombia, el panel
  muestra un aviso recordando que el consentimiento debe declarar transferencia
  internacional.
- **Modelos:** por propósito (conversación, embeddings, clasificación). Uno primario
  y una cascada de fallback ordenada. Costos por millón de tokens para proyección.

  El catálogo **se descubre solo** (ADR-016): un cron consulta a diario el endpoint
  de modelos de cada proveedor, así que un modelo nuevo aparece aquí al día
  siguiente sin tocar código. Lo que **no** es automático es adoptarlo. Lo nuevo
  entra inactivo, sin costo verificado y sin ser primario, con una etiqueta
  «nuevo · sin revisar». Tres puertas antes de que pueda ser primario:

  1. **Costo registrado y verificado.** Se teclea porque ningún proveedor lo
     publica en su endpoint. Sin él, el corte por `presupuesto_ia_mensual_usd`
     no corta: un modelo a coste cero nunca agota un presupuesto. Lo impone un
     CHECK en base, no solo la pantalla.
  2. **Activo.**
  3. **No retirado por el proveedor.**
  4. **Conjunto dorado en verde contra ese modelo**, con el prompt que está
     activo hoy. Si el prompt cambió después de la corrida, el verde caduca y
     hay que repetirla.

  Y **ascender lo hace el abogado, no el super_admin**: `ia.modelos.promover`
  es la tercera asimetría del ADR-007. El super_admin hace todo el trabajo
  técnico —descubrir, configurar, verificar costos, probar conexión, activar—
  y no puede promover, igual que no puede aprobar un prompt. Lo que el abogado
  firma no es la calidad técnica del modelo: es la responsabilidad profesional
  sobre lo que el bot diga desde ese momento. La puerta 4 es lo que convierte
  esa firma en un acto informado en vez de un trámite sobre un nombre.

  Ascender queda registrado en la bitácora con quién, cuándo y cuál era el
  anterior. El modelo que baja pasa a `orden_fallback = 1`: sigue activo y es
  el suplente natural.

  Si el proveedor retira el modelo primario, el panel lo avisa en rojo al entrar.
  No es una caída —la cascada de fallback lo cubre— y precisamente por eso hay
  que decirlo: el bot está respondiendo desde el suplente sin que nadie lo haya
  decidido.
- **Prompts:** editor con versionado. Un prompt nuevo nace inactivo; requiere
  **aprobación del abogado** para activarse. Botón de rollback a cualquier versión.
  Diff entre versiones.
- **Consumo:** gráfica de gasto diario, tokens, latencia media, tasa de error,
  y presupuesto mensual con alerta.

### 2.6 Base de conocimiento
Alta de documentos (tipo de fuente, referencia normativa, URL oficial, vigencia).
La búsqueda es MySQL: prefiltro FULLTEXT y coseno en PHP sobre `kb_chunks`
(ADR-005). No hay `pgvector`.
Cola de verificación: ningún chunk entra al RAG sin `verificado_por` del abogado.
Buscador de prueba: escribir una consulta y ver qué fragmentos recuperaría el
motor, con su puntaje de similitud. Sirve para depurar respuestas raras.

### 2.7 Contenido y landing
Bloques de la landing editables (`landing_bloques`) y artículos SEO
(`articulos`) con markdown, meta título y meta descripción. Gate obligatorio:
`revisado_por_abogado` debe estar en verdadero para publicar. Vista previa antes
de publicar.

### 2.8 Configuración general
Formulario generado automáticamente desde `configuraciones`, agrupado por `grupo`,
con la etiqueta, la ayuda y la validación de tipo y rango que traen las propias
filas. Añadir un parámetro nuevo es un `INSERT`, no un cambio de código.
Historial de cambios visible: quién cambió qué, cuándo y por qué.

### 2.9 Usuarios y auditoría
El primer `super_admin` no se crea desde aquí — el panel todavía no es accesible
cuando hace falta. Se crea por consola con `bin/crear-usuario.php`, una sola vez.
De ahí en adelante, alta de usuarios con rol. Al crear un usuario con rol `abogado` o `asistente`, el
panel aprovisiona el agente correspondiente en Chatwoot vía API — una sola alta,
no dos. 2FA obligatorio para `super_admin` y `abogado`.
Bitácora consultable de `auditoria` y `configuraciones_historial`.

---

## 3. Matriz de roles

| Módulo | super_admin | abogado | asistente | contador |
|---|:--:|:--:|:--:|:--:|
| Tablero | ✔ | ✔ | ✔ | lectura |
| Casos | ✔ | ✔ | ✔ | — |
| Agenda y tarifas | ✔ | ✔ | lectura | — |
| Pagos — transacciones | ✔ | ✔ | — | lectura |
| Pagos — credenciales | ✔ | — | — | — |
| IA — proveedores y modelos | ✔ | lectura | — | — |
| IA — prompts (editar) | ✔ | ✔ | — | — |
| IA — prompts (**aprobar**) | — | ✔ | — | — |
| Base de conocimiento (cargar) | ✔ | ✔ | ✔ | — |
| Base de conocimiento (**verificar**) | — | ✔ | — | — |
| Contenido (editar) | ✔ | ✔ | ✔ | — |
| Contenido (**publicar**) | — | ✔ | — | — |
| Configuración general | ✔ | ✔ (parcial) | — | — |
| Usuarios y auditoría | ✔ | lectura | — | — |
| Kill switch de IA | ✔ | ✔ | — | — |

Dos asimetrías deliberadas:

- **El `super_admin` no aprueba prompts, ni verifica normas, ni publica contenido.**
  Tú tienes las llaves técnicas; la responsabilidad profesional es de Pedro. Si el
  bot dice una barbaridad jurídica, la firma que la autorizó debe ser la suya.
- **El `abogado` no ve credenciales.** No las necesita y no debería poder filtrarlas.
  Solo ve máscaras y el botón de probar conexión.

---

## 4. Seguridad del panel

1. Argon2id para contraseñas. TOTP obligatorio en `super_admin` y `abogado`.
2. Sesiones en base con hash del token; nunca el token en claro. Rotación al
   cambiar contraseña. Revocación remota desde el panel.
3. Bloqueo tras 5 intentos fallidos, con espera creciente.
4. Rate limit por IP en login y en el webhook de pagos, contra la tabla
   `intentos_acceso`. `usuarios.intentos_fallidos` cuenta por cuenta y no cubre
   esto: quien prueba mil contraseñas contra mil usuarios distintos nunca dispara
   el bloqueo de la regla 3.
5. CSRF en todo formulario. CSP estricta. Cookies `HttpOnly`, `Secure`, `SameSite=Lax`.
6. El panel se sirve en subdominio propio (`panel.pedroabogadoaduanero.com`) y,
   si es viable, restringido por IP o detrás de VPN. No comparte origen con la landing.
7. Las credenciales se muestran siempre enmascaradas. Toda lectura del valor real
   queda auditada con usuario, IP y momento.
8. Sin `MASTER_KEY` en el entorno, el proceso no arranca. Backup de esa clave fuera
   del servidor: si se pierde, se pierden todas las credenciales cifradas.

---

## 5. Landing pública

Estáticamente generada o renderizada en servidor desde `landing_bloques` y
`articulos`, con caché agresiva. La landing **no** consulta MySQL en cada visita
ni carga JavaScript del panel.

Requisitos de rendimiento (son criterio de aceptación, no aspiración):
LCP < 2 s en 4G, CLS < 0.1, peso inicial < 300 KB, sin fuentes bloqueantes.

Instrumentación: cada visita registra un evento en `eventos_landing` con hash de
sesión no identificable y UTMs. El botón de WhatsApp arrastra los UTMs al mensaje
prellenado, para poder atribuir cada caso a su campaña.
