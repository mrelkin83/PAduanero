# Catálogo público de cursos — sub-proyecto 1 de 3

**Fecha:** 2026-08-26 · **Estado:** aprobado por el PO en chat, pendiente de implementación
**Relación con otros sub-proyectos:** este documento cubre únicamente el catálogo público
(cursos, categorías, temario, pestaña de menú). **No incluye** cobro (sub-proyecto 2) ni
cuentas de acceso/entrega de contenido (sub-proyecto 3) — cada uno tendrá su propio spec.

## 0. Por qué se partió en tres

El pedido original ("sección de cursos + venta + acceso a plataforma, estilo Udemy") junta
tres subsistemas independientes: catálogo, cobro, y autenticación de compradores con entrega
de contenido protegido. Cada uno tiene su propio riesgo y sus propias preguntas de diseño
(pasarela de pago, modelo de cuentas). Se decidió diseñar e implementar en ese orden para
poder lanzar el catálogo sin esperar a que cobro y accesos estén resueltos.

## 1. Decisión que reabre una puerta cerrada — Wompi

CLAUDE.md (ADR-010, §0) documenta que el 2026-08-17 se retiró la pasarela de pago por
completo: *"no hay cobros que conciliar, no hay bot que pueda decir una barbaridad
jurídica"*. El PO confirmó explícitamente en esta sesión que el módulo de cursos **sí**
tendrá pago real vía Wompi, revirtiendo esa parte de la decisión — acotada al módulo de
cursos, no al resto del sitio.

Hallazgo relevante para el sub-proyecto 2: el motor de WhatsApp v2 (§0.2 de CLAUDE.md,
2026-08-22) **ya reintrodujo Wompi parcialmente** para el cobro de citas de asesoría —
`WhatsappControlador::guardarWompi()`, credenciales `wompi_public_key`/`wompi_private_key`/
`wompi_events_secret`/`wompi_integrity_secret` en `wa_config`, y el webhook
`POST /api/wa/pago/{token}`. El sub-proyecto 2 debe decidir si el checkout de cursos
reutiliza esas mismas credenciales/webhook o si necesita las suyas propias; este spec no
lo decide, solo lo deja anotado porque cambia el punto de partida de ese diseño.

## 2. Alcance de este sub-proyecto

Incluye:
- Categorías de cursos, editables desde el panel.
- Cursos con precio (`precio_cop`, PESOS enteros, ADR-010), nivel, portada, descripción,
  "lo que aprenderás", y temario (módulos → lecciones, solo títulos y duración).
- Estado borrador/publicado por curso.
- Página pública `/cursos` (catálogo, filtrable por categoría) y `/cursos/{slug}` (ficha).
- Pestaña "Cursos" en el menú superior de la landing, con interruptor global en el panel.
- Pantallas de panel para crear/editar/publicar cursos y categorías.

No incluye (quedan para sub-proyectos 2 y 3):
- Checkout, pago, webhooks de Wompi para cursos.
- Cuentas de comprador, login, tokens de acceso.
- Contenido real de las lecciones (video/archivo) ni su protección.
- Cualquier lógica de "quién puede ver esta lección".

## 3. Decisiones tomadas (resumen)

| Punto | Decisión |
|---|---|
| Categorías | Editables desde el panel (CRUD), no catálogo cerrado en código |
| Quién edita cursos | Mismos roles que editan contenido público hoy (`abogado`, `super_admin`) |
| Temario | Se modela ahora (módulos/lecciones con título y duración); el contenido protegido va después |
| Interruptor de menú | Solo oculta el enlace del menú; `/cursos` y `/cursos/{slug}` siguen respondiendo por URL directa |
| Estado por curso | Borrador/publicado, independiente del interruptor global |
| Curso en borrador + link directo | Visible (no 404) — permite a Pedro compartir una vista previa antes de publicar |
| Botón "Comprar" | Apunta a `/cursos/{slug}/comprar`, que este sub-proyecto NO implementa; la pasarela será Wompi (sub-proyecto 2) |
| Ubicación del enlace en el menú | Nuevo enlace de salida junto al botón de Diagnóstico (rompe a propósito el principio "un solo botón de salida" de la landing, documentado en `pagina.php`, porque ahora hay dos objetivos de negocio legítimos) |

## 4. Modelo de datos

Migración aditiva `db/migraciones/0028_cursos.sql`, siguiendo el patrón de
`modalidades_asesoria`/`consultas` (UUID `CHAR(36)`, `precio_cop BIGINT` en pesos enteros,
`activo`/`estado`, `ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`):

```sql
CREATE TABLE categorias_curso (
  id         CHAR(36)     NOT NULL DEFAULT (UUID()),
  nombre     VARCHAR(80)  NOT NULL,
  slug       VARCHAR(80)  NOT NULL,
  orden      SMALLINT     NOT NULL DEFAULT 0,
  activa     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY ux_categorias_curso_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cursos (
  id                CHAR(36)     NOT NULL DEFAULT (UUID()),
  categoria_id      CHAR(36)     NOT NULL,
  titulo            VARCHAR(150) NOT NULL,
  slug              VARCHAR(150) NOT NULL,
  resumen           VARCHAR(300) NOT NULL,
  descripcion       TEXT         NOT NULL,
  lo_que_aprendera  JSON         NOT NULL,
  nivel             ENUM('basico','intermedio','avanzado') NOT NULL DEFAULT 'basico',
  precio_cop        BIGINT       NOT NULL,
  imagen_portada    VARCHAR(255) NULL,
  estado            ENUM('borrador','publicado') NOT NULL DEFAULT 'borrador',
  orden             SMALLINT     NOT NULL DEFAULT 0,
  creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  publicado_en      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_cursos_slug (slug),
  KEY ix_cursos_estado (estado, orden),
  CONSTRAINT fk_cursos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_curso(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE curso_modulos (
  id        CHAR(36)     NOT NULL DEFAULT (UUID()),
  curso_id  CHAR(36)     NOT NULL,
  titulo    VARCHAR(150) NOT NULL,
  orden     SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_modulos_curso (curso_id, orden),
  CONSTRAINT fk_modulos_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE curso_lecciones (
  id                  CHAR(36)     NOT NULL DEFAULT (UUID()),
  modulo_id           CHAR(36)     NOT NULL,
  titulo              VARCHAR(150) NOT NULL,
  duracion_min        SMALLINT     NULL,
  orden               SMALLINT     NOT NULL DEFAULT 0,
  vista_previa_gratis TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_lecciones_modulo (modulo_id, orden),
  CONSTRAINT fk_lecciones_modulo FOREIGN KEY (modulo_id) REFERENCES curso_modulos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

`curso_lecciones` no tiene columna de contenido (video/archivo/texto protegido) — se añade
en el sub-proyecto 3 vía migración aditiva, sin tocar esta tabla más que para agregar
columnas.

**El interruptor de menú no crea tabla nueva.** Es una fila en la tabla genérica
`configuraciones` (ya existente, `src/Servicios/Config.php`): `clave='cursos_activo'`,
`tipo='booleano'`, `grupo='cursos'`. El panel ya sabe pintar el formulario de cualquier fila
de esa tabla sin código nuevo.

La migración no siembra categorías ni cursos de ejemplo — el proyecto ya tiene un
precedente documentado de confundir datos de prueba con datos reales (`confianza`, ver
memoria del proyecto), así que las categorías las crea Pedro desde el panel cuando tenga
cursos reales que clasificar. La única semilla es el `INSERT` de `cursos_activo = 0`
(apagado por defecto, se enciende explícitamente desde `/panel/configuracion` cuando haya
cursos publicados que mostrar).

## 5. Rutas públicas y renderizado

Nuevo `App\Servicios\Cursos`, mismo patrón que `App\Servicios\Perfil` (página propia, no
bloque de landing; `CachePagina` propia; SEO propio):

```
GET /cursos              → catálogo: tarjetas con portada, título, categoría, nivel, precio;
                            filtro por categoría (?categoria=slug)
GET /cursos/{slug}       → ficha: descripción, "lo que aprenderás", temario (módulos y
                            lecciones con duración), botón "Comprar — $X COP"
```

Reglas de visibilidad:
- `/cursos` lista solo cursos `estado='publicado'`, ordenados por `orden`. Si no hay
  ninguno publicado, muestra un estado vacío explícito ("Todavía no hay cursos
  publicados"), nunca un error.
- `/cursos/{slug}` responde para cualquier curso que exista, publicado o borrador —
  un borrador es accesible por link directo pero no aparece en el listado.
- El interruptor `cursos_activo` **no** afecta estas rutas — controla únicamente si el
  enlace aparece en el menú de la landing.

En `plantillas/landing/pagina.php`, el enlace se añade junto al botón de diagnóstico
existente (fuera del array `$menu` de anclas internas, que es un mecanismo distinto):

```php
<?php if ($config->get('cursos_activo', false)): ?>
<a href="/cursos" data-evento="cursos_inicio" class="menu-enlace">Cursos</a>
<?php endif; ?>
```

Esto rompe deliberadamente el principio documentado en esa misma plantilla ("la landing es
un documento único... la única salida real es /perfil") — decisión explícita del PO: ahora
hay dos objetivos de conversión legítimos (asesoría 1:1 y venta de cursos), no uno.

## 6. Panel: pantallas y permisos

Nuevo `App\Panel\PanelCursosControlador`, registrado en `Panel.php` junto a los módulos
existentes (mismo patrón que `TarifasControlador`: recibe `BD` y `AuditoriaRepo`, exige
permisos vía `$ctx->permisos->exigir()`, registra cada escritura en auditoría):

```
GET  /panel/cursos                    listar cursos (tabla: título, categoría, precio, estado)
GET  /panel/cursos/nuevo              formulario vacío
GET  /panel/cursos/{id}/editar        formulario con datos + editor de temario
POST /panel/cursos/guardar            crear/actualizar curso (datos generales + temario)
POST /panel/cursos/{id}/publicar      borrador → publicado (exige título, precio > 0,
                                       categoría válida, ≥1 módulo con ≥1 lección)
POST /panel/cursos/{id}/despublicar   publicado → borrador
GET  /panel/cursos/categorias         listar/crear/editar categorías
POST /panel/cursos/categorias/guardar
```

Permisos nuevos (mismo mecanismo que `agenda.ver`/`agenda.editar`, tabla `permisos` +
`rol_permisos`, sembrados en migración): **`cursos.ver`** y **`cursos.editar`**, asignados
a los roles `abogado` y `super_admin` — igual que hoy administran el contenido público de
la landing (§2 de CLAUDE.md).

El interruptor `cursos_activo` se administra desde `/panel/configuracion`, pantalla ya
existente que pinta cualquier fila de `configuraciones` — no se construye pantalla nueva
para esto.

Guarda de precio: mismo umbral que `TarifasControlador::guardar()` (rechaza precio_cop ≥
10.000.000 como probable error de centavos, con el mismo mensaje explicativo).

## 7. Botón de compra — stub hacia el sub-proyecto 2

La ficha del curso trae un botón "Comprar — $X.XXX.XXX COP" que enlaza a
`/cursos/{slug}/comprar`. Esa ruta **no se implementa en este sub-proyecto** — queda
reservada. El sub-proyecto 2 decide su forma (página de checkout propia, redirección
directa a un link de pago de Wompi generado al vuelo, etc.) y si reutiliza las
credenciales/webhook de Wompi que ya existen para el cobro de citas (§1).

Hasta que el sub-proyecto 2 exista, esa ruta puede devolver un 404 normal del enrutador —
no hace falta una página "Próximamente" a propósito para este spec.

## 8. Imágenes

Sigue el patrón de `public/img/` (§7 de CLAUDE.md): portadas de curso en
`public/img/cursos/{slug}.jpg`, servidas por URL `/img/cursos/{slug}.jpg`. El campo
`imagen_portada` en la tabla guarda el nombre de archivo, no la ruta completa (igual
patrón que las fotos de la landing).

## 9. Pruebas

Sigue el estándar del proyecto (`vendor/bin/phpunit`, se espera 100% verde):

- **`CursosTest`** (integración, público):
  - `/cursos` lista solo publicados, respeta filtro por categoría, ordena por `orden`.
  - `/cursos` sin cursos publicados muestra el estado vacío, no error.
  - `/cursos/{slug}` de un curso borrador responde 200 (visible por link directo).
  - `/cursos/{slug}` de un slug inexistente responde 404.
  - La ficha pinta el temario completo (módulos y lecciones en orden).
  - El precio se muestra formateado en pesos, nunca en centavos.
- **`PanelCursosTest`** (integración, panel):
  - Crear/editar/publicar/despublicar un curso; auditoría registra cada acción.
  - Publicar sin módulos, sin precio o sin categoría falla con mensaje claro.
  - `cursos.ver`/`cursos.editar` bloquean sin el rol adecuado (403 o redirección a login).
  - Guarda de precio: rechaza `precio_cop >= 10_000_000` con el mismo mensaje que tarifas.
  - CRUD de categorías: crear, editar, slug único.
- **`ConfigTest`** (ya existente): añadir caso para `cursos_activo` — el menú de la landing
  muestra/oculta el enlace según ese valor.

## 10. Fuera de alcance (recordatorio)

Explícitamente no cubierto aquí, para que quede trazado hacia los siguientes specs:
- Checkout y confirmación de pago (sub-proyecto 2 — Wompi).
- Registro/login de compradores, tokens de acceso (sub-proyecto 3).
- Almacenamiento y protección del contenido real de las lecciones (sub-proyecto 3).
- Certificados, progreso del alumno, reseñas/calificaciones — nada de esto se pidió; no se
  diseña por adelantado (YAGNI).
