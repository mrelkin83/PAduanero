# Contenido protegido de las lecciones — diseño

**Fecha:** 2026-08-28 · **Sub-proyecto:** 3 de 4 del módulo de cursos
**Depende de:** sub-proyecto 1 (catálogo, `db/migraciones/0028_cursos.sql`) y sub-proyecto 2
(cobro + cuenta de comprador, `docs/superpowers/specs/2026-08-26-cobro-y-cuenta-comprador-design.md`),
ambos ya en producción.

## 0. Qué resuelve esto

Hasta hoy el camino del comprador termina en `/mis-cursos`: una lista de los cursos que pagó,
sin ningún enlace hacia el contenido real. `curso_lecciones` (sub-proyecto 1) solo tiene
título, duración y orden — ni un campo de contenido. Este sub-proyecto:

1. Agrega el contenido de cada lección: video, texto, materiales descargables.
2. Construye la entrega protegida de ese contenido: solo el comprador autenticado que pagó
   ESE curso puede verlo — ni otro comprador, ni un visitante anónimo, ni copiando la URL.
3. Deja ver gratis, sin cuenta, las lecciones marcadas `vista_previa_gratis` (ya existe esa
   columna desde el sub-proyecto 1) — es la herramienta de venta de la ficha pública.

**Explícitamente fuera de alcance** (decisión del PO, 2026-08-28): seguimiento de progreso
(qué lecciones ya vio el comprador, % completado). Eso lo resuelve el sub-proyecto 4
(certificado), que es quien define qué significa «completó el curso».

## 1. Decisiones del Product Owner

Todas tomadas en la sesión de brainstorming del 2026-08-28:

| Punto | Decisión |
|---|---|
| Tipos de contenido por lección | Video, texto/artículo, materiales descargables — los tres |
| Alojamiento de video | Bunny Stream (servicio externo con tokens firmados), no autoalojado ni YouTube/Vimeo sin plan de pago |
| Subida de video | Pedro sube el archivo en el panel de Bunny Stream (fuera de este sistema) y pega el ID del video en el panel de PAduanero — no se integra subida de video por API |
| Subida de materiales | Directa en el panel de PAduanero — construida en este sub-proyecto |
| Acceso a la vista previa gratis | Cualquier visitante, sin cuenta — igual que en Udemy, es venta |
| Navegación entre lecciones | Una página por lección, servida desde el servidor (sin JavaScript de por medio) — mismo patrón que el resto del sitio |
| Seguimiento de progreso | Fuera de alcance; queda para el sub-proyecto 4 |

## 2. Modelo de datos

Migración aditiva `db/migraciones/0034_contenido_lecciones.sql` (ADR-013).

**`curso_lecciones` gana dos columnas:**

```sql
ALTER TABLE curso_lecciones
  ADD COLUMN video_bunny_id   VARCHAR(64)  NULL AFTER vista_previa_gratis,
  ADD COLUMN contenido_texto  MEDIUMTEXT   NULL AFTER video_bunny_id;
```

`video_bunny_id` es el GUID que Bunny Stream le da al video (lo pega Pedro a mano en el
panel). `contenido_texto` es el cuerpo del artículo — texto plano con saltos de línea, NO
HTML de por medio (ver §5.3): se escapa igual que cualquier otro dato en las plantillas
(`Vista::e()`), y los saltos de línea se convierten en párrafos al mostrarlo. Ninguna de las
dos es obligatoria — una lección puede tener solo video, solo texto, o ambos.

**Tabla nueva `curso_materiales`** — un material descargable por fila:

```sql
CREATE TABLE curso_materiales (
  id              CHAR(36)      NOT NULL DEFAULT (UUID()),
  leccion_id      CHAR(36)      NOT NULL,
  nombre          VARCHAR(150)  NOT NULL,
  archivo         VARCHAR(80)   NOT NULL,
  extension       VARCHAR(10)   NOT NULL,
  tamanio_bytes   INT UNSIGNED  NOT NULL,
  orden           SMALLINT      NOT NULL DEFAULT 0,
  creado_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_materiales_leccion (leccion_id, orden),
  CONSTRAINT fk_materiales_leccion FOREIGN KEY (leccion_id) REFERENCES curso_lecciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

`nombre` es lo que ve el comprador (ej. «Plantilla de solicitud»). `archivo` es un nombre
generado (`bin2hex(random_bytes(16))`), nunca el nombre original ni datos que lo delaten —
`extension` se guarda aparte para poder nombrar el archivo en disco sin exponer ni confiar en
el nombre que subió el navegador. El archivo real vive en
`storage/cursos/materiales/{leccion_id}/{archivo}.{extension}`.

`storage/` ya está bloqueado en el nginx del VPS en ambos server blocks
(`location ~ ^/(src|db|docs|bin|tests|storage|...) { deny all; return 404; }`) — nadie llega
a ese archivo por URL directa aunque adivine el nombre exacto. La única puerta es la ruta de
descarga (§4), que valida la compra antes de leer el archivo.

## 3. Control de acceso — un solo punto de verdad

`src/Cuenta/AccesoLeccion.php`, una función pura sin estado propio:

```php
final class AccesoLeccion
{
    public function __construct(private readonly CompraCursoRepo $compras) {}

    public function puedeVer(?Comprador $comprador, array $leccion, string $cursoId): bool
    {
        if ((int) $leccion['vista_previa_gratis'] === 1) {
            return true;
        }

        return $comprador !== null && $this->compras->tienePagada($comprador->id, $cursoId);
    }
}
```

`CompraCursoRepo` gana `tienePagada(string $compradorId, string $cursoId): bool` (una
consulta `EXISTS` contra `compras_curso` filtrando `comprador_id`, `curso_id` y
`estado = 'pagada'`).

Las tres superficies que exponen contenido —la página de la lección, la descarga de un
material, y el token firmado de Bunny para el reproductor— llaman a `puedeVer()` antes de
mostrar nada. Un comprador que pagó el Curso A y adivina la URL de una lección del Curso B
choca con el mismo chequeo sin excepción: no hay una segunda ruta que alguien pudo dejar sin
proteger porque cada una reutiliza la misma función.

**Límite honesto de esta protección:** el token firmado de Bunny evita que la URL del video
sirva de nada fuera de esa sesión y después de que venza (ver §5.1) — evita compartir el
enlace, no evita que alguien grabe la pantalla mientras lo ve. Ningún sistema sin DRM real
evita eso, y no es lo que se pidió resolver aquí.

## 4. Rutas nuevas

Todas bajo el paraguas de sesión de comprador ya existente (`AccesoControlador::COOKIE`,
patrón establecido en el sub-proyecto 2):

| Ruta | Qué hace |
|---|---|
| `GET /mis-cursos/{slug}` | El «aula»: módulos y lecciones del curso, en el mismo orden que ya define el catálogo. Sin sesión → redirige a `/entrar`. Con sesión pero sin haber pagado ese curso → redirige a `/mis-cursos` (su propia lista), sin decir por qué — el curso ya es público en `/cursos/{slug}` de todos modos, así que no hay nada que ocultar confirmando o no su existencia. |
| `GET /mis-cursos/{slug}/leccion/{leccionId}` | El contenido: video embebido (si tiene), texto, lista de materiales con enlaces de descarga. Mismo chequeo de acceso; si la lección no pertenece al curso del slug de la URL, 404. |
| `GET /mis-cursos/{slug}/leccion/{leccionId}/material/{materialId}` | Transmite el archivo (`Content-Disposition: attachment`, `Content-Type` según `extension`). Mismo chequeo de acceso. Si el material no pertenece a esa lección, o el archivo no existe en disco, 404 — nunca un error de PHP crudo. |

El token firmado de Bunny **no tiene ruta propia**: se genera del lado del servidor al
renderizar la página de la lección y se incrusta directo en el `src` del `<iframe>` del
reproductor. Vence a las 4 horas — de sobra para ver la lección una vez; si el comprador
vuelve después, la próxima carga de la página genera un token nuevo.

**Ficha pública (`/cursos/{slug}`, sub-proyecto 1):** se extiende para incrustar el video de
las lecciones con `vista_previa_gratis = 1`, con el mismo mecanismo de token firmado pero sin
exigir sesión — es la vitrina de venta.

## 5. Integración con Bunny Stream

### 5.1 Token firmado

Bunny firma así: `token = base64_urlsafe(sha256(security_key . video_id . expires))`, URL:
`https://iframe.mediadelivery.net/embed/{library_id}/{video_id}?token={token}&expires={expires}`.
Vive en `src/Soporte/BunnyStream.php`, una clase sin estado que solo construye esa URL —
nunca llama a la API de Bunny por red (no hace falta: la firma es puramente criptográfica,
local).

### 5.2 Credenciales

`BUNNY_LIBRARY_ID` y `BUNNY_STREAM_SECURITY_KEY` en `.env` — mismo patrón que las
credenciales de SMTP y el cliente OAuth de Google (infraestructura que administra Elkin, no
algo que Pedro edite desde el panel). Si no están configuradas, `BunnyStream::disponible()`
devuelve `false` y la página de la lección muestra «Video no disponible por ahora» en vez de
tronar — mismo principio de degradación que ya usa `TtsManager` (`packages/whatsapp-engine`):
una dependencia externa caída nunca debe romper la respuesta.

### 5.3 Por qué el texto de la lección no es HTML de confianza

Aunque solo el staff lo escribe (mismo nivel de confianza que el copy de la landing), se
guarda y se muestra como texto escapado con saltos de línea convertidos a párrafos, no como
HTML crudo interpretado. Es más simple que evaluar un sanitizador de HTML nuevo (que sería
una dependencia nueva, contra ADR de "cero dependencias de producción"), y cierra de raíz el
escenario de una cuenta de panel comprometida inyectando script en una página que ve el
público comprador — defensa en profundidad barata.

## 6. Cambios en el panel

**Editor de lección** (ya existe desde el sub-proyecto 1, dentro de
`PanelCursosControlador`/`plantillas/panel/cursos_editar.php`): gana un campo de texto para
`video_bunny_id`, un `<textarea>` para `contenido_texto`, y una sección de materiales:
lista de los ya subidos (con tamaño y botón «Eliminar»), y un formulario para subir uno
nuevo.

**Rutas nuevas del panel:**
- `POST /cursos/lecciones/materiales/agregar` — sube un archivo (multipart), lo guarda en
  `storage/cursos/materiales/{leccionId}/` con nombre generado, inserta la fila.
- `POST /cursos/lecciones/materiales/eliminar` — borra la fila y el archivo del disco.

**nginx:** la subida de materiales necesita un límite de tamaño mayor al `client_max_body_size
8M` que ya protege el resto del panel (fijado el 2026-08-26 para las imágenes). Se agrega un
`location` específico para esa ruta de subida con `client_max_body_size 30M` — no se toca el
límite general.

## 7. Manejo de errores

- Sin sesión → `/entrar` (patrón ya establecido en `MisCursosControlador`).
- Con sesión pero sin haber pagado ese curso → `/mis-cursos`, silencioso.
- Lección/material que no pertenece al curso de la URL, o archivo que ya no existe en disco
  → 404 limpio, nunca una excepción sin capturar.
- Bunny sin configurar, o `video_bunny_id` vacío en la lección → no se renderiza el bloque de
  video, sin error.
- Subida de material con extensión no permitida (lista blanca: `pdf`, `doc`, `docx`, `xls`,
  `xlsx`, `zip`, `jpg`, `png`) o que exceda el límite → mensaje de error en el panel, no se
  guarda nada.

## 8. Pruebas

- `AccesoLeccionTest`: preview siempre `true` sin importar el comprador; comprador que pagó
  ese curso → `true`; comprador que pagó OTRO curso → `false`; anónimo sin preview → `false`.
- Rutas de `/mis-cursos/{slug}` y `/mis-cursos/{slug}/leccion/{id}`: sin sesión → 302 a
  `/entrar`; con sesión sin haber pagado → 302 a `/mis-cursos`; pagado → 200 con el contenido
  esperado; preview accesible sin sesión.
- Descarga de material: mismos tres casos de acceso, más el 404 cuando el archivo no existe
  en disco.
- Subida y borrado de material desde el panel: el archivo aparece/desaparece en disco además
  de en la fila de la tabla.
- `BunnyStream`: el token generado es estable dado el mismo `expires` (prueba de la fórmula,
  sin llamar a la red real).

## 9. Pendiente operativo (no es código)

- [ ] Contratar Bunny Stream y crear la librería de video — Elkin.
- [ ] `BUNNY_LIBRARY_ID` / `BUNNY_STREAM_SECURITY_KEY` en el `.env` del VPS.
- [ ] Pedro necesita saber subir un video en el panel de Bunny y copiar el ID — documentar el
      paso a paso una vez la librería exista.
