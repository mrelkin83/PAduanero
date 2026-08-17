# PANEL ADMINISTRATIVO — Especificación

> **Encogió con el sistema.** Este documento describía nueve módulos; cuatro
> —Casos, Pagos, Inteligencia artificial y Base de conocimiento— se retiraron
> con el motor y la pasarela, y con ellos la sección «La frontera con
> Chatwoot», que ya no tiene frontera que trazar.
>
> Lo que el panel administra hoy es **contenido y acceso**. Si algún día
> vuelve una bandeja de conversaciones, vuelve como sistema aparte: el panel
> no la reimplementa (ADR-006).

## 2. Módulos

### 2.1 Tablero
Precio vigente de la asesoría, lo que falta para publicar la landing, y las
métricas de la landing por canal en un rango de fechas: vistas, lecturas a
media página, entradas al diagnóstico y clics a WhatsApp, cruzados con la
inversión publicitaria anotada a mano.

> **La pantalla dice hasta dónde mide, y tiene que seguir diciéndolo.** Su
> «conversión» es a conversación iniciada, no a cliente: lo que ocurre tras
> el clic pasa en WhatsApp, fuera de este sistema. Hay una prueba que exige
> que ese aviso siga en pantalla.

### 2.3 Tarifas
CRUD de `modalidades_asesoria`: nombre, duración y **precio**.

> El precio se define aquí, nunca en código. Ya no lo cobra nadie —no hay
> pasarela— pero lo pintan la landing y el diagnóstico, y por eso la pantalla
> sobrevivió al recorte. La guarda contra teclear centavos donde van pesos se
> queda: quien escribe el número sigue siendo una persona.

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
De ahí en adelante, alta de usuarios con rol. 2FA obligatorio para
`super_admin` y `abogado`. Bitácora consultable de `auditoria` y
`configuraciones_historial`.

> Aquí se daba de alta además el agente en Chatwoot, para que un usuario del
> panel fuera una sola alta y no dos. Se retiró con la bandeja: ya no hay
> segundo sistema al que dar de alta a nadie.

---

## 3. Matriz de roles

Los permisos de los módulos retirados —Casos, Pagos, IA, prompts, base de
conocimiento y el kill switch— **siguen sembrados en la base**, igual que sus
tablas: no molestan y evitan una migración destructiva. Simplemente no hay
pantalla que los consulte.

| Módulo | super_admin | abogado | asistente | contador |
|---|:--:|:--:|:--:|:--:|
| Tablero | ✔ | ✔ | ✔ | lectura |
| Tarifas | ✔ | ✔ | lectura | — |
| Contenido (editar) | ✔ | ✔ | ✔ | — |
| Contenido (**publicar**) | — | ✔ | — | — |
| Configuración general | ✔ | ✔ (parcial) | — | — |
| Usuarios y auditoría | ✔ | lectura | — | — |

La asimetría deliberada que sobrevive:

- **El `super_admin` no publica contenido.** Tiene las llaves técnicas; la
  responsabilidad profesional es de Pedro. Lo que sale en la página lleva su
  firma como abogado, y bajo la Ley 1123 de 2007 esa firma tiene consecuencias
  que un perfil técnico no puede asumir por él.

  Eran tres asimetrías —aprobar prompts, verificar normas y publicar
  contenido—. Las dos primeras se fueron con la IA y el RAG; el principio es
  el mismo y ahora recae entero sobre el copy público.

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
