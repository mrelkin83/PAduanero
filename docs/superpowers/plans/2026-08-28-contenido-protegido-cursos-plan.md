# Contenido protegido de las lecciones — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar el contenido real de cada lección (video, texto, materiales descargables)
solo al comprador autenticado que pagó ese curso específico, y dejar ver gratis las lecciones
marcadas `vista_previa_gratis` sin exigir cuenta.

**Architecture:** Un único servicio (`AccesoLeccion::puedeVer()`) decide quién ve qué, y las
tres superficies que exponen contenido (página de lección, descarga de material, token de
video) lo consultan sin excepción. El video vive en Bunny Stream (tokens firmados,
generados localmente sin llamadas de red); los materiales viven en `storage/` (ya bloqueado
por nginx) y se sirven por una ruta PHP que revisa la compra antes de leer el archivo. Cada
lección es su propia URL, servida desde el servidor — sin JavaScript de por medio, mismo
patrón que el resto del sitio.

**Tech Stack:** PHP 8.2+, MySQL 8, sin dependencias nuevas de producción. Bunny Stream es un
servicio externo pero la integración es solo una fórmula HMAC local — no hay SDK ni llamada
de red hacia Bunny en este código.

**Spec:** `docs/superpowers/specs/2026-08-28-contenido-protegido-cursos-design.md`

## Global Constraints

- Migración aditiva: `db/migraciones/0034_contenido_lecciones.sql` (ADR-013). La última
  migración en disco hoy es `0033_wa_agentes_genero.sql` (de otra sesión, aún sin commitear)
  — `0034` es el siguiente número libre.
- El texto de la lección (`contenido_texto`) se guarda y se muestra como texto plano
  escapado con `App\Soporte\Vista::e()` — nunca como HTML interpretado. No se introduce
  ningún sanitizador de HTML.
- Los materiales se nombran en disco con `bin2hex(random_bytes(16))` — nunca el nombre
  original del archivo que subió el navegador. Viven en
  `storage/cursos/materiales/{leccionId}/{archivo}.{extension}`, ya bloqueado en el nginx del
  VPS (`location ~ ^/(...|storage|...) { deny all; return 404; }` en ambos server blocks —
  verificado en producción, no hace falta tocar nginx para esto).
- Extensiones permitidas en materiales: `pdf`, `doc`, `docx`, `xls`, `xlsx`, `zip`, `jpg`,
  `png`. Tamaño máximo: 30 MB por archivo (constante en código; el límite de nginx
  `client_max_body_size` para esa ruta específica es un cambio de infraestructura fuera de
  este plan — ver la nota operativa al final).
- `AccesoLeccion::puedeVer()` es el ÚNICO punto que decide acceso — ninguna otra ruta
  reimplementa esa lógica. Vista previa gratis (`vista_previa_gratis = 1`): accesible sin
  sesión. Si no es preview: exige comprador autenticado con una fila `compras_curso`
  `estado = 'pagada'` para ESE `curso_id`.
- Sin sesión de comprador en una ruta protegida → redirige a `/entrar` (patrón ya
  establecido en `MisCursosControlador`). Con sesión pero sin haber pagado ese curso →
  redirige a `/mis-cursos`, sin mensaje (el curso ya es público en `/cursos/{slug}`, no hay
  nada que ocultar confirmando o no su existencia).
- Bunny Stream sin credenciales configuradas, o lección sin `video_bunny_id` → no se
  renderiza el bloque de video, sin error. Mismo principio que `TtsManager`
  (`packages/whatsapp-engine`): una dependencia externa ausente nunca rompe la respuesta.
- Fuera de alcance (decisión del PO, 2026-08-28): seguimiento de progreso del comprador. Lo
  resuelve el sub-proyecto 4 (certificado).
- Cookie de sesión de comprador: `App\Cuenta\AccesoControlador::COOKIE` (`'pa_comprador'`).
  Nunca se reimplementa la lectura de esa cookie — se reutiliza
  `AutenticacionComprador::compradorDeSesion($token)`.
- CSRF: las rutas GET nuevas no lo necesitan. Las rutas POST nuevas del panel
  (`/cursos/lecciones/guardar`, `/cursos/lecciones/materiales/agregar`,
  `/cursos/lecciones/materiales/eliminar`) quedan cubiertas por el dispatcher central de CSRF
  de `Panel.php` — no necesitan validación manual (patrón ya establecido para todas las
  rutas de `/panel/*`).

---

### Task 1: Migración — esquema de contenido de lecciones

**Files:**
- Create: `db/migraciones/0034_contenido_lecciones.sql`
- Modify: `tests/CasoBaseBd.php` (agregar `curso_materiales` a la lista de `limpiar()`)

**Interfaces:**
- Produces: columnas `curso_lecciones.video_bunny_id`, `curso_lecciones.contenido_texto`;
  tabla `curso_materiales` con columnas `id, leccion_id, nombre, archivo, extension,
  tamanio_bytes, orden, creado_en`.

- [ ] **Step 1: Escribir la migración**

```sql
-- =====================================================================
-- 0034 — Contenido protegido de las lecciones (sub-proyecto 3)
--
-- Migración aditiva (ADR-013). Implementa
-- docs/superpowers/specs/2026-08-28-contenido-protegido-cursos-design.md.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE curso_lecciones
  ADD COLUMN video_bunny_id   VARCHAR(64)  NULL AFTER vista_previa_gratis,
  ADD COLUMN contenido_texto  MEDIUMTEXT   NULL AFTER video_bunny_id;

CREATE TABLE IF NOT EXISTS curso_materiales (
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

- [ ] **Step 2: Agregar `curso_materiales` a `tests/CasoBaseBd.php`**

En el array de `limpiar()`, agregar `'curso_materiales'` a la lista — debe ir ANTES de
`'curso_lecciones'` (el `TRUNCATE` con `FOREIGN_KEY_CHECKS = 0` no exige orden real, pero
mantener el orden padre-antes-que-hijo es la convención ya establecida en el archivo):

```php
            'prompts', 'curso_materiales', 'curso_lecciones', 'curso_modulos', 'cursos', 'categorias_curso',
```

(reemplaza la línea `'prompts', 'curso_lecciones', 'curso_modulos', 'cursos', 'categorias_curso',`
ya existente)

- [ ] **Step 3: Correr la migración en la base de pruebas y confirmar**

Run: `vendor/bin/phpunit tests/Integracion/CursosTest.php`
Expected: PASS (confirma que la migración no rompió nada del catálogo existente; la base de
pruebas se recrea desde cero en la primera prueba de la corrida, así que esto ya ejercita la
migración nueva)

- [ ] **Step 4: Commit**

```bash
git add db/migraciones/0034_contenido_lecciones.sql tests/CasoBaseBd.php
git commit -m "$(cat <<'EOF'
feat(cursos): esquema de contenido de lecciones (video, texto, materiales)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `CompraCursoRepo::tienePagada()`

**Files:**
- Modify: `src/Repositorios/CompraCursoRepo.php`
- Test: `tests/Integracion/CompraCursoRepoTest.php`

**Interfaces:**
- Consumes: nada nuevo — mismo `$this->bd` ya inyectado.
- Produces: `CompraCursoRepo::tienePagada(string $compradorId, string $cursoId): bool`.

- [ ] **Step 1: Escribir la prueba que falla**

El archivo ya tiene helpers `categoria(): string` y `curso(): string` (líneas 20-38), pero
`curso()` inserta siempre con el slug fijo `curso-de-prueba` — sirve para las pruebas
existentes porque cada una usa solo un curso, pero esta prueba necesita DOS cursos distintos
en el mismo test, así que no reutiliza `curso()` para el segundo: inserta el segundo curso
inline con su propio slug. Agregar a `tests/Integracion/CompraCursoRepoTest.php`:

```php
    #[Test]
    public function tienePagadaEsTrueSoloSiEseCompradorPagoEseCurso(): void
    {
        $cursoId = $this->curso();

        $catId = $this->categoria();
        $otroCursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$otroCursoId, $catId, 'Otro curso', 'otro-curso-tiene-pagada', 'r', 'd', '[]', 250000, 'publicado']);

        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $otroCompradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $compraId = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->marcarPagada($compraId);
        $this->repo->vincularComprador($compraId, $compradorId);

        self::assertTrue($this->repo->tienePagada($compradorId, $cursoId));
        self::assertFalse($this->repo->tienePagada($compradorId, $otroCursoId));
        self::assertFalse($this->repo->tienePagada($otroCompradorId, $cursoId));
    }

    #[Test]
    public function tienePagadaEsFalseSiLaCompraNoEstaPagada(): void
    {
        $cursoId = $this->curso();
        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $compraId = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->vincularComprador($compraId, $compradorId);
        // Deliberadamente sin marcarPagada(): sigue en 'pendiente'.

        self::assertFalse($this->repo->tienePagada($compradorId, $cursoId));
    }
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/CompraCursoRepoTest.php`
Expected: FAIL — `Call to undefined method App\Repositorios\CompraCursoRepo::tienePagada()`

- [ ] **Step 3: Implementar**

En `src/Repositorios/CompraCursoRepo.php`, agregar después de `vincularComprador()`:

```php
    public function tienePagada(string $compradorId, string $cursoId): bool
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM compras_curso
                 WHERE comprador_id = ? AND curso_id = ? AND estado = 'pagada'
             )"
        );
        $stmt->execute([$compradorId, $cursoId]);

        return (bool) $stmt->fetchColumn();
    }
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/CompraCursoRepoTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Repositorios/CompraCursoRepo.php tests/Integracion/CompraCursoRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CompraCursoRepo::tienePagada() para el control de acceso a lecciones

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `CursoMaterialRepo`

**Files:**
- Create: `src/Repositorios/CursoMaterialRepo.php`
- Test: `tests/Integracion/CursoMaterialRepoTest.php`

**Interfaces:**
- Consumes: `App\Core\BD` (mismo patrón que todos los repos del proyecto).
- Produces: `CursoMaterialRepo::crear(string $leccionId, string $nombre, string $archivo, string $extension, int $tamanioBytes): string` (retorna el id nuevo);
  `CursoMaterialRepo::porId(string $id): ?array`;
  `CursoMaterialRepo::deLeccion(string $leccionId): array` (lista ordenada por `orden`);
  `CursoMaterialRepo::eliminar(string $id): void` (solo borra la fila — el archivo en disco lo
  borra quien llama, que es quien sabe construir la ruta física).

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CursoMaterialRepo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CursoMaterialRepoTest extends CasoBaseBd
{
    private CursoMaterialRepo $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CursoMaterialRepo($this->bd);
    }

    private function leccion(): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-material']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso material', 'curso-material', 'r', 'd', '[]', 250000, 'publicado']);
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo 1', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId, $moduloId, 'Lección 1', 0]);

        return $leccionId;
    }

    #[Test]
    public function crearYPorIdDevuelvenLosMismosDatos(): void
    {
        $leccionId = $this->leccion();

        $id = $this->repo->crear($leccionId, 'Plantilla de solicitud', 'abc123', 'pdf', 204800);

        $fila = $this->repo->porId($id);
        self::assertNotNull($fila);
        self::assertSame('Plantilla de solicitud', $fila['nombre']);
        self::assertSame('abc123', $fila['archivo']);
        self::assertSame('pdf', $fila['extension']);
        self::assertSame($leccionId, $fila['leccion_id']);
    }

    #[Test]
    public function deLeccionListaSoloLosDeEsaLeccionOrdenados(): void
    {
        $leccionId = $this->leccion();
        $otraLeccionId = $this->leccion();

        $this->repo->crear($leccionId, 'Segundo', 'b', 'pdf', 100);
        $this->repo->crear($leccionId, 'Primero', 'a', 'pdf', 100);
        $this->repo->crear($otraLeccionId, 'De otra lección', 'c', 'pdf', 100);

        $lista = $this->repo->deLeccion($leccionId);

        self::assertCount(2, $lista);
        self::assertSame('Segundo', $lista[0]['nombre']);
        self::assertSame('Primero', $lista[1]['nombre']);
    }

    #[Test]
    public function eliminarBorraLaFila(): void
    {
        $leccionId = $this->leccion();
        $id = $this->repo->crear($leccionId, 'A borrar', 'x', 'pdf', 100);

        $this->repo->eliminar($id);

        self::assertNull($this->repo->porId($id));
    }
}
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/CursoMaterialRepoTest.php`
Expected: FAIL — `Class "App\Repositorios\CursoMaterialRepo" not found`

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

final class CursoMaterialRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    public function crear(string $leccionId, string $nombre, string $archivo, string $extension, int $tamanioBytes): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $siguienteOrden = $this->bd->pdo()->prepare(
            'SELECT COALESCE(MAX(orden), 0) + 1 FROM curso_materiales WHERE leccion_id = ?'
        );
        $siguienteOrden->execute([$leccionId]);
        $orden = (int) $siguienteOrden->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO curso_materiales (id, leccion_id, nombre, archivo, extension, tamanio_bytes, orden)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $leccionId, $nombre, $archivo, $extension, $tamanioBytes, $orden]);

        return $id;
    }

    /** @return array<string,mixed>|null */
    public function porId(string $id): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM curso_materiales WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /** @return list<array<string,mixed>> */
    public function deLeccion(string $leccionId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT * FROM curso_materiales WHERE leccion_id = ? ORDER BY orden'
        );
        $stmt->execute([$leccionId]);

        return $stmt->fetchAll();
    }

    public function eliminar(string $id): void
    {
        $this->bd->pdo()->prepare('DELETE FROM curso_materiales WHERE id = ?')->execute([$id]);
    }
}
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/CursoMaterialRepoTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Repositorios/CursoMaterialRepo.php tests/Integracion/CursoMaterialRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CursoMaterialRepo - CRUD de materiales descargables por leccion

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `App\Cuenta\AccesoLeccion` — el único punto de control de acceso

**Files:**
- Create: `src/Cuenta/AccesoLeccion.php`
- Test: `tests/Integracion/AccesoLeccionTest.php`

**Interfaces:**
- Consumes: `App\Repositorios\CompraCursoRepo::tienePagada()` (Task 2), `App\Modelos\Comprador`.
- Produces: `AccesoLeccion::__construct(CompraCursoRepo $compras)`,
  `AccesoLeccion::puedeVer(?Comprador $comprador, array $leccion, string $cursoId): bool`.
  **Toda ruta que sirva contenido de lección llama a este método — ninguna reimplementa la
  regla.**

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cuenta\AccesoLeccion;
use App\Modelos\Comprador;
use App\Repositorios\CompraCursoRepo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class AccesoLeccionTest extends CasoBaseBd
{
    private AccesoLeccion $acceso;
    private CompraCursoRepo $compras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = new CompraCursoRepo($this->bd);
        $this->acceso = new AccesoLeccion($this->compras);
    }

    private function comprador(string $id): Comprador
    {
        return new Comprador($id, 'Ana', 'Gómez', 'CC', '3001234567', 'ana@ejemplo.com');
    }

    #[Test]
    public function unaLeccionDeVistaPreviaEsVisibleParaCualquieraSinSesion(): void
    {
        $leccion = ['vista_previa_gratis' => 1];

        self::assertTrue($this->acceso->puedeVer(null, $leccion, 'curso-cualquiera'));
    }

    #[Test]
    public function unaLeccionQueNoEsPreviaNuncaEsVisibleSinSesion(): void
    {
        $leccion = ['vista_previa_gratis' => 0];

        self::assertFalse($this->acceso->puedeVer(null, $leccion, 'curso-cualquiera'));
    }

    #[Test]
    public function unCompradorQuePagoEseCursoVeLaLeccionNoPreview(): void
    {
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-acceso']);
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso acceso', 'curso-acceso-leccion', 'r', 'd', '[]', 250000, 'publicado']);

        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $leccion = ['vista_previa_gratis' => 0];

        self::assertTrue($this->acceso->puedeVer($this->comprador($compradorId), $leccion, $cursoId));
    }

    #[Test]
    public function unCompradorQuePagoOtroCursoNoVeEsteAunqueTengaSesion(): void
    {
        $leccion = ['vista_previa_gratis' => 0];
        $compradorSinComprasId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        self::assertFalse($this->acceso->puedeVer($this->comprador($compradorSinComprasId), $leccion, 'curso-que-no-compro'));
    }
}
```

(Revisa el constructor real de `App\Modelos\Comprador` antes de escribir esta prueba —
`src/Modelos/Comprador.php` — para pasar los argumentos en el orden correcto; el helper
`comprador()` de arriba asume `(id, nombres, apellidos, tipoDocumento, celular, correo)`.)

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/AccesoLeccionTest.php`
Expected: FAIL — `Class "App\Cuenta\AccesoLeccion" not found`

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Modelos\Comprador;
use App\Repositorios\CompraCursoRepo;

/**
 * Único punto de verdad de «¿puede este comprador ver esta lección?».
 *
 * Toda ruta que sirva contenido protegido (página de lección, descarga de
 * material, token de video) llama aquí antes de mostrar nada — así la regla
 * vive en un solo lugar, no repetida en cada controlador.
 */
final class AccesoLeccion
{
    public function __construct(private readonly CompraCursoRepo $compras)
    {
    }

    /** @param array<string,mixed> $leccion fila de curso_lecciones (al menos vista_previa_gratis) */
    public function puedeVer(?Comprador $comprador, array $leccion, string $cursoId): bool
    {
        if ((int) $leccion['vista_previa_gratis'] === 1) {
            return true;
        }

        return $comprador !== null && $this->compras->tienePagada($comprador->id, $cursoId);
    }
}
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/AccesoLeccionTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Cuenta/AccesoLeccion.php tests/Integracion/AccesoLeccionTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): AccesoLeccion - unico punto de control de acceso a contenido

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `App\Soporte\BunnyStream`

**Files:**
- Create: `src/Soporte/BunnyStream.php`
- Test: `tests/Unidad/BunnyStreamTest.php`

**Interfaces:**
- Consumes: `App\Soporte\Entorno::obtener()` (mismo patrón que `Logger::desdeEntorno()`,
  `Smtp::desdeEntorno()`).
- Produces: `BunnyStream::desdeEntorno(): self`, `BunnyStream::disponible(): bool`,
  `BunnyStream::urlEmbed(string $videoId, int $minutosVigencia = 240): string`.

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\BunnyStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BunnyStreamTest extends TestCase
{
    #[Test]
    public function sinLibraryIdNiSecurityKeyNoEstaDisponible(): void
    {
        $bunny = new BunnyStream('', '');

        self::assertFalse($bunny->disponible());
    }

    #[Test]
    public function conCredencialesEstaDisponible(): void
    {
        $bunny = new BunnyStream('12345', 'clave-secreta');

        self::assertTrue($bunny->disponible());
    }

    #[Test]
    public function urlEmbedTraeElLibraryIdYElVideoId(): void
    {
        $bunny = new BunnyStream('12345', 'clave-secreta');

        $url = $bunny->urlEmbed('video-abc', 240);

        self::assertStringStartsWith('https://iframe.mediadelivery.net/embed/12345/video-abc?', $url);
        self::assertStringContainsString('token=', $url);
        self::assertStringContainsString('expires=', $url);
    }

    #[Test]
    public function urlEmbedEsEstableParaElMismoVencimiento(): void
    {
        $bunny = new BunnyStream('12345', 'clave-secreta');

        $expira = time() + 240 * 60;
        $url1 = $bunny->urlEmbed('video-abc', 240, $expira);
        $url2 = $bunny->urlEmbed('video-abc', 240, $expira);

        self::assertSame($url1, $url2);
    }

    #[Test]
    public function elTokenCambiaSiCambiaElVideoId(): void
    {
        $bunny = new BunnyStream('12345', 'clave-secreta');
        $expira = time() + 240 * 60;

        $urlA = $bunny->urlEmbed('video-a', 240, $expira);
        $urlB = $bunny->urlEmbed('video-b', 240, $expira);

        self::assertNotSame($urlA, $urlB);
    }
}
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Unidad/BunnyStreamTest.php`
Expected: FAIL — `Class "App\Soporte\BunnyStream" not found`

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Genera la URL firmada del reproductor de Bunny Stream. Es pura fórmula
 * HMAC local (documentada por Bunny) — esta clase nunca llama a la red.
 *
 * Sin credenciales configuradas, `disponible()` es false y quien la use debe
 * degradar sin tronar (mismo principio que TtsManager en
 * packages/whatsapp-engine: una dependencia externa ausente nunca rompe la
 * respuesta).
 */
final class BunnyStream
{
    public function __construct(
        private readonly string $libraryId,
        private readonly string $securityKey,
    ) {
    }

    public static function desdeEntorno(): self
    {
        return new self(
            Entorno::obtener('BUNNY_LIBRARY_ID', '') ?? '',
            Entorno::obtener('BUNNY_STREAM_SECURITY_KEY', '') ?? '',
        );
    }

    public function disponible(): bool
    {
        return $this->libraryId !== '' && $this->securityKey !== '';
    }

    /**
     * @param int $minutosVigencia cuánto dura el token una vez generado
     * @param int|null $expiraEn timestamp Unix exacto de vencimiento — solo
     *     para pruebas deterministas; en producción se omite y se calcula
     *     desde `$minutosVigencia`.
     */
    public function urlEmbed(string $videoId, int $minutosVigencia = 240, ?int $expiraEn = null): string
    {
        $expira = $expiraEn ?? (time() + $minutosVigencia * 60);
        $token = hash('sha256', $this->securityKey . $videoId . $expira);

        return sprintf(
            'https://iframe.mediadelivery.net/embed/%s/%s?token=%s&expires=%d',
            rawurlencode($this->libraryId),
            rawurlencode($videoId),
            $token,
            $expira,
        );
    }
}
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Unidad/BunnyStreamTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Soporte/BunnyStream.php tests/Unidad/BunnyStreamTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): BunnyStream - token firmado local para el reproductor de video

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: `App\Soporte\SubidaMaterial`

**Files:**
- Create: `src/Soporte/SubidaMaterial.php`
- Test: `tests/Unidad/SubidaMaterialTest.php`

**Interfaces:**
- Produces: `SubidaMaterial::guardar(array $archivo, string $carpetaAbsoluta, ?callable $mover = null): array{ok:bool,archivo:string,extension:string,tamanioBytes:int,error:string}`.

Mismo patrón que `App\Soporte\SubidaImagen` (`src/Soporte/SubidaImagen.php`) — nombre
generado, `$mover` inyectable para pruebas, nunca confía en el nombre/tipo que manda el
navegador.

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\SubidaMaterial;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubidaMaterialTest extends TestCase
{
    private string $carpetaTmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpetaTmp = sys_get_temp_dir() . '/pa-materiales-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->carpetaTmp)) {
            array_map('unlink', glob($this->carpetaTmp . '/*') ?: []);
            rmdir($this->carpetaTmp);
        }
        parent::tearDown();
    }

    private function archivoFalso(string $contenido, string $nombre): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pa-mat-');
        file_put_contents($tmp, $contenido);

        return ['name' => $nombre, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contenido)];
    }

    #[Test]
    public function sinArchivoNoEsError(): void
    {
        $resultado = SubidaMaterial::guardar(['error' => UPLOAD_ERR_NO_FILE], $this->carpetaTmp, copy(...));

        self::assertFalse($resultado['ok']);
        self::assertSame('', $resultado['error']);
    }

    #[Test]
    public function unaExtensionNoPermitidaSeRechaza(): void
    {
        $archivo = $this->archivoFalso('contenido', 'virus.exe');

        $resultado = SubidaMaterial::guardar($archivo, $this->carpetaTmp, copy(...));

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('no permitido', $resultado['error']);
    }

    #[Test]
    public function unArchivoMasPesadoQueElLimiteSeRechaza(): void
    {
        $archivo = $this->archivoFalso('contenido', 'grande.pdf');
        $archivo['size'] = 31 * 1024 * 1024;

        $resultado = SubidaMaterial::guardar($archivo, $this->carpetaTmp, copy(...));

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('30 MB', $resultado['error']);
    }

    #[Test]
    public function unPdfValidoSeGuardaConNombreGenerado(): void
    {
        $archivo = $this->archivoFalso('%PDF-1.4 contenido', 'Plantilla de Solicitud.pdf');

        $resultado = SubidaMaterial::guardar($archivo, $this->carpetaTmp, copy(...));

        self::assertTrue($resultado['ok']);
        self::assertSame('', $resultado['error']);
        self::assertSame('pdf', $resultado['extension']);
        self::assertNotSame('Plantilla de Solicitud.pdf', $resultado['archivo']);
        self::assertFileExists($this->carpetaTmp . '/' . $resultado['archivo'] . '.pdf');
        self::assertSame(strlen('%PDF-1.4 contenido'), $resultado['tamanioBytes']);
    }
}
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Unidad/SubidaMaterialTest.php`
Expected: FAIL — `Class "App\Soporte\SubidaMaterial" not found`

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Guarda un material descargable subido por el panel (entrada cruda de
 * `$_FILES`) dentro de una carpeta de `storage/`.
 *
 * Mismo patrón que `SubidaImagen`: no confía en el nombre que manda el
 * navegador, el nombre final lo genera esta clase. A diferencia de
 * `SubidaImagen`, la validación de tipo es por EXTENSIÓN declarada (lista
 * blanca), no por contenido — un PDF o un .docx no tienen una firma binaria
 * tan uniforme como una imagen, y la lista blanca ya cierra la puerta a
 * ejecutables y scripts.
 */
final class SubidaMaterial
{
    private const EXTENSIONES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'jpg', 'png'];
    private const MAX_BYTES = 30 * 1024 * 1024;

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $archivo entrada de $_FILES
     * @return array{ok:bool,archivo:string,extension:string,tamanioBytes:int,error:string}
     */
    public static function guardar(array $archivo, string $carpetaAbsoluta, ?callable $mover = null): array
    {
        $mover ??= move_uploaded_file(...);

        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => ''];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'No se pudo recibir el archivo.'];
        }

        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'Archivo inválido.'];
        }

        $tamanio = (int) ($archivo['size'] ?? 0);
        if ($tamanio > self::MAX_BYTES) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'El archivo pesa más de 30 MB.'];
        }

        $extension = strtolower(pathinfo((string) ($archivo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONES, true)) {
            return [
                'ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0,
                'error' => 'Formato no permitido: use ' . implode(', ', self::EXTENSIONES) . '.',
            ];
        }

        if (!is_dir($carpetaAbsoluta) && !@mkdir($carpetaAbsoluta, 0775, true) && !is_dir($carpetaAbsoluta)) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'No se pudo crear la carpeta de destino.'];
        }

        $nombre = bin2hex(random_bytes(16));

        if (!$mover($tmp, rtrim($carpetaAbsoluta, '/') . '/' . $nombre . '.' . $extension)) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'No se pudo guardar el archivo en el servidor.'];
        }

        return ['ok' => true, 'archivo' => $nombre, 'extension' => $extension, 'tamanioBytes' => $tamanio, 'error' => ''];
    }
}
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Unidad/SubidaMaterialTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Soporte/SubidaMaterial.php tests/Unidad/SubidaMaterialTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): SubidaMaterial - subida de materiales descargables con lista blanca

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: `Respuesta::archivo()`

**Files:**
- Modify: `src/Core/Respuesta.php`
- Test: `tests/Unidad/RespuestaArchivoTest.php`

**Interfaces:**
- Produces: `Respuesta::archivo(string $contenido, string $nombreDescarga, string $mime): self`.

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Core\Respuesta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RespuestaArchivoTest extends TestCase
{
    #[Test]
    public function archivoTraeElCuerpoYLasCabecerasDeDescarga(): void
    {
        $r = Respuesta::archivo('contenido del archivo', 'plantilla.pdf', 'application/pdf');

        self::assertSame('contenido del archivo', $r->cuerpo);
        self::assertSame(200, $r->estado);
        self::assertSame('application/pdf', $r->cabeceras['Content-Type']);
        self::assertSame('attachment; filename="plantilla.pdf"', $r->cabeceras['Content-Disposition']);
        self::assertSame((string) strlen('contenido del archivo'), $r->cabeceras['Content-Length']);
    }
}
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Unidad/RespuestaArchivoTest.php`
Expected: FAIL — `Call to undefined method App\Core\Respuesta::archivo()`

- [ ] **Step 3: Implementar**

En `src/Core/Respuesta.php`, agregar después de `html()`:

```php
    public static function archivo(string $contenido, string $nombreDescarga, string $mime): self
    {
        return new self($contenido, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $nombreDescarga . '"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Unidad/RespuestaArchivoTest.php`
Expected: PASS

- [ ] **Step 5: Confirmar que nada existente se rompió**

Run: `vendor/bin/phpunit tests/Integracion/ArranqueTest.php tests/Integracion/PanelTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Core/Respuesta.php tests/Unidad/RespuestaArchivoTest.php
git commit -m "$(cat <<'EOF'
feat(core): Respuesta::archivo() para descargas de materiales

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Panel — editar contenido de la lección (video, texto)

**Files:**
- Modify: `src/Panel/PanelCursosControlador.php` (dos métodos nuevos)
- Modify: `src/Panel/Panel.php` (dos rutas nuevas)
- Modify: `plantillas/panel/cursos_editar.php` (enlace "Editar contenido" por lección)
- Create: `plantillas/panel/cursos_leccion_editar.php`
- Test: `tests/Integracion/PanelCursosTest.php`

**Interfaces:**
- Produces: `PanelCursosControlador::editarLeccion(Contexto $ctx): Respuesta` (GET, `?id=`),
  `PanelCursosControlador::guardarLeccion(Contexto $ctx): Respuesta` (POST).
- Consumes: nada nuevo del resto del plan — solo las columnas de la Task 1.

Esta pantalla es NUEVA (hoy una lección solo se crea o se borra desde el temario inline,
sin pantalla propia de edición — ver `agregarLeccion()`/`eliminarLeccion()` en
`PanelCursosControlador.php`, líneas 335-398).

- [ ] **Step 1: Escribir las pruebas que fallan**

El archivo ya tiene `categoriaId(string $nombre = 'Aduanero'): string` y
`crearCursoCompleto(string $categoriaId, int $precio = 250000): string` (líneas 85, 182) —
no hay todavía un helper que cree una lección completa; agrégalo siguiendo exactamente el
patrón inline que ya usa `publicarConTemarioFunciona()` (líneas 207-220: curso → módulo →
lección):

```php
    private function leccionDePrueba(): string
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());

        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$moduloId, $cursoId, 'Módulo de prueba', 1]);

        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$leccionId, $moduloId, 'Lección de prueba', 1]);

        return $leccionId;
    }
```

Y las pruebas nuevas. **Ojo:** `editarLeccion()` lee el id de la URL
(`$ctx->peticion->consulta['id']`, igual que el `editar()` de cursos ya existente en la línea
47 de `PanelCursosControlador.php`), NO del formulario — el tercer argumento de `ctx()` es
`$consulta`, no el segundo:

```php
    #[Test]
    public function editarLeccionMuestraElFormularioConLosDatosActuales(): void
    {
        $leccionId = $this->leccionDePrueba();
        $this->bd->pdo()->prepare('UPDATE curso_lecciones SET video_bunny_id = ?, contenido_texto = ? WHERE id = ?')
            ->execute(['video-abc', 'Texto de la lección.', $leccionId]);

        $html = $this->controlador()->editarLeccion($this->ctx('abogado', [], ['id' => $leccionId]))->cuerpo;

        self::assertStringContainsString('video-abc', $html);
        self::assertStringContainsString('Texto de la lección.', $html);
    }

    #[Test]
    public function guardarLeccionActualizaVideoYTexto(): void
    {
        $leccionId = $this->leccionDePrueba();

        $r = $this->controlador()->guardarLeccion($this->ctx('abogado', [
            'id' => $leccionId, 'titulo' => 'Lección actualizada', 'duracion_min' => '15',
            'video_bunny_id' => 'video-nuevo', 'contenido_texto' => 'Contenido nuevo.',
        ]));

        self::assertSame(302, $r->estado);
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM curso_lecciones WHERE id = ?');
        $stmt->execute([$leccionId]);
        $fila = $stmt->fetch();
        self::assertSame('Lección actualizada', $fila['titulo']);
        self::assertSame('video-nuevo', $fila['video_bunny_id']);
        self::assertSame('Contenido nuevo.', $fila['contenido_texto']);
    }

    #[Test]
    public function guardarLeccionSinTituloNoGuardaNada(): void
    {
        $leccionId = $this->leccionDePrueba();

        $this->controlador()->guardarLeccion($this->ctx('abogado', ['id' => $leccionId, 'titulo' => '']));

        $stmt = $this->bd->pdo()->prepare('SELECT titulo FROM curso_lecciones WHERE id = ?');
        $stmt->execute([$leccionId]);
        self::assertNotSame('', $stmt->fetchColumn());
    }
```

- [ ] **Step 2: Confirmar que fallan**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: FAIL — `Call to undefined method App\Panel\PanelCursosControlador::editarLeccion()`

- [ ] **Step 3: Implementar los métodos del controlador**

En `src/Panel/PanelCursosControlador.php`, agregar después de `eliminarLeccion()`:

```php
    public function editarLeccion(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.ver');

        $id = (string) ($ctx->peticion->consulta['id'] ?? '');
        $stmt = $this->bd->pdo()->prepare(
            'SELECT cl.*, cm.curso_id FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cl.id = ?'
        );
        $stmt->execute([$id]);
        $leccion = $stmt->fetch();

        if ($leccion === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Esa lección no existe.');
        }

        return $this->vista('panel/cursos_leccion_editar', [
            'ctx' => $ctx,
            'leccion' => $leccion,
            'materiales' => $this->materiales->deLeccion($id),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardarLeccion(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $titulo = $ctx->campo('titulo');
        $duracion = $ctx->campo('duracion_min');
        $videoBunnyId = $ctx->campo('video_bunny_id');
        $contenidoTexto = $ctx->campo('contenido_texto');
        $vistaPrevia = (int) ($ctx->campo('vista_previa_gratis') === '1');

        $stmt = $this->bd->pdo()->prepare(
            'SELECT cm.curso_id FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cl.id = ?'
        );
        $stmt->execute([$id]);
        $cursoId = $stmt->fetchColumn();

        if ($cursoId === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Esa lección no existe.');
        }

        if ($titulo === '') {
            return $this->redirigirCon('/panel/cursos/lecciones/editar?id=' . urlencode($id), 'error', 'La lección necesita un título.');
        }

        $duracionMin = preg_match('/^\d+$/', $duracion) === 1 ? (int) $duracion : null;

        $this->bd->pdo()->prepare(
            'UPDATE curso_lecciones
                SET titulo = ?, duracion_min = ?, vista_previa_gratis = ?, video_bunny_id = ?, contenido_texto = ?
              WHERE id = ?'
        )->execute([
            $titulo, $duracionMin, $vistaPrevia,
            $videoBunnyId !== '' ? $videoBunnyId : null,
            $contenidoTexto !== '' ? $contenidoTexto : null,
            $id,
        ]);

        $this->auditoria->registrar('curso_leccion', $id, 'actualizar', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

        return $this->redirigirCon('/panel/cursos/lecciones/editar?id=' . urlencode($id), 'ok', 'Lección actualizada.');
    }
```

El constructor gana la dependencia de `CursoMaterialRepo` (la Task 9 la usa en la misma
pantalla — se agrega ya para no volver a tocar el constructor dos veces):

```php
    public function __construct(
        private readonly BD $bd,
        private readonly AuditoriaRepo $auditoria,
        private readonly \App\Repositorios\CompraCursoRepo $compras,
        private readonly \App\Cuenta\ConfirmadorCompra $confirmador,
        private readonly \App\Repositorios\CursoMaterialRepo $materiales,
    ) {
    }
```

(reemplaza el constructor actual — hay exactamente dos sitios que instancian
`PanelCursosControlador` y ambos deben actualizarse en el mismo commit: `src/Panel/Panel.php`,
línea del array `modulos()`, ver Step 5 abajo; y el helper `controlador()` de
`tests/Integracion/PanelCursosTest.php`, líneas 63-83, al que hay que agregarle
`new \App\Repositorios\CursoMaterialRepo($this->bd),` como quinto argumento, justo después
del bloque de `ConfirmadorCompra`.)

- [ ] **Step 4: Escribir la plantilla nueva `plantillas/panel/cursos_leccion_editar.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var array<string,mixed> $leccion
 * @var list<array<string,mixed>> $materiales
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Editar lección';

$contenido = static function () use ($e, $ctx, $leccion, $materiales): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo">Editar lección</h2>

    <form method="post" action="/panel/cursos/lecciones/guardar" class="tarjeta mt-4 p-4">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="id" value="<?= $e((string) $leccion['id']) ?>">

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="rotulo">Título</label>
                <input name="titulo" value="<?= $e((string) $leccion['titulo']) ?>"
                       class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div>
                <label class="rotulo">Minutos</label>
                <input name="duracion_min" type="number" min="0"
                       value="<?= $e((string) ($leccion['duracion_min'] ?? '')) ?>"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="vista_previa_gratis" value="1"
                       <?= (int) $leccion['vista_previa_gratis'] === 1 ? 'checked' : '' ?>
                       <?= $editable ? '' : 'disabled' ?>>
                Vista previa gratis
            </label>

            <div class="sm:col-span-2">
                <label class="rotulo">ID de video en Bunny Stream</label>
                <input name="video_bunny_id" value="<?= $e((string) ($leccion['video_bunny_id'] ?? '')) ?>"
                       placeholder="Súbalo en el panel de Bunny y pegue aquí el ID del video"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Contenido de texto</label>
                <textarea name="contenido_texto" rows="8" class="campo mt-1"
                          <?= $editable ? '' : 'disabled' ?>><?= $e((string) ($leccion['contenido_texto'] ?? '')) ?></textarea>
            </div>
        </div>

        <?php if ($editable): ?>
        <button type="submit" class="boton mt-4">Guardar lección</button>
        <?php endif; ?>
    </form>

    <section class="mt-8">
        <h2 class="rotulo">Materiales descargables</h2>
        <ul class="mt-3 space-y-2">
            <?php foreach ($materiales as $m): ?>
            <li class="tarjeta flex items-center justify-between p-3">
                <span><?= $e((string) $m['nombre']) ?> <span class="text-xs text-acero">(<?= $e(number_format((int) $m['tamanio_bytes'] / 1024, 0)) ?> KB)</span></span>
                <?php if ($editable): ?>
                <form method="post" action="/panel/cursos/lecciones/materiales/eliminar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                    <input type="hidden" name="leccion_id" value="<?= $e((string) $leccion['id']) ?>">
                    <button type="submit" class="underline">Eliminar</button>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
            <?php if ($materiales === []): ?>
            <li class="text-sm text-acero">Todavía no hay materiales.</li>
            <?php endif; ?>
        </ul>

        <?php if ($editable): ?>
        <form method="post" action="/panel/cursos/lecciones/materiales/agregar" enctype="multipart/form-data"
              class="tarjeta mt-3 flex flex-wrap items-end gap-2 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <input type="hidden" name="leccion_id" value="<?= $e((string) $leccion['id']) ?>">
            <div>
                <label class="rotulo">Nombre a mostrar</label>
                <input name="nombre" class="campo mt-1" required>
            </div>
            <div>
                <label class="rotulo">Archivo</label>
                <input type="file" name="archivo" class="campo mt-1" required>
            </div>
            <button type="submit" class="boton-secundario">Subir material</button>
        </form>
        <p class="mt-1 text-xs text-acero">PDF, Word, Excel, ZIP, JPG o PNG. Máx. 30 MB.</p>
        <?php endif; ?>
    </section>
<?php };

require __DIR__ . '/_disposicion.php';
```

- [ ] **Step 5: Cablear las rutas en `src/Panel/Panel.php`**

Insertar inmediatamente después de `'POST /cursos/lecciones/eliminar' => $modulos['cursos']->eliminarLeccion($ctx),`:

```php
            'GET /cursos/lecciones/editar' => $modulos['cursos']->editarLeccion($ctx),
            'POST /cursos/lecciones/guardar' => $modulos['cursos']->guardarLeccion($ctx),
```

Y actualizar la instanciación en `modulos()`:

```php
            'cursos' => new PanelCursosControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(AuditoriaRepo::class),
                $this->c->obtener(\App\Repositorios\CompraCursoRepo::class),
                $this->c->obtener(\App\Cuenta\ConfirmadorCompra::class),
                $this->c->obtener(\App\Repositorios\CursoMaterialRepo::class),
            ),
```

`CursoMaterialRepo::class` aún no está registrado en el contenedor — agrégalo en
`src/Core/Aplicacion.php`, en `registrarServicios()`, justo después del registro de
`CompraCursoRepo::class`:

```php
        $this->contenedor->registrar(
            \App\Repositorios\CursoMaterialRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CursoMaterialRepo => new \App\Repositorios\CursoMaterialRepo(
                $c->obtener(BD::class),
            ),
        );
```

- [ ] **Step 6: Agregar el enlace «Editar contenido» en `plantillas/panel/cursos_editar.php`**

En el `<li>` de cada lección del temario (dentro del `foreach ($modulo['lecciones'] as $leccion)`),
agregar el enlace ANTES del formulario de «Eliminar»:

```php
                <li class="flex items-center justify-between gap-4">
                    <span>
                        <?= $e((string) $leccion['titulo']) ?>
                        <?= $leccion['duracion_min'] !== null ? ' · ' . $e((string) $leccion['duracion_min']) . ' min' : '' ?>
                        <?= (int) $leccion['vista_previa_gratis'] === 1 ? ' · vista previa gratis' : '' ?>
                    </span>
                    <span class="flex items-center gap-3">
                    <a href="/panel/cursos/lecciones/editar?id=<?= $e((string) $leccion['id']) ?>" class="underline">Editar contenido</a>
                    <?php if ($editable): ?>
                    <form method="post" action="/panel/cursos/lecciones/eliminar">
                        <?= $ctx->csrf->campoOculto() ?>
                        <input type="hidden" name="id" value="<?= $e((string) $leccion['id']) ?>">
                        <button type="submit" class="underline">Eliminar</button>
                    </form>
                    <?php endif; ?>
                    </span>
                </li>
```

(reemplaza el `<li>` existente completo — el único cambio real es envolver el botón
«Eliminar» junto con el nuevo enlace «Editar contenido» en un `<span>` común).

- [ ] **Step 7: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: PASS (todas las pruebas de este archivo, incluidas las 3 nuevas)

- [ ] **Step 8: Commit**

```bash
git add src/Panel/PanelCursosControlador.php src/Panel/Panel.php src/Core/Aplicacion.php \
        plantillas/panel/cursos_editar.php plantillas/panel/cursos_leccion_editar.php \
        tests/Integracion/PanelCursosTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): pantalla de panel para editar video y texto de una leccion

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Panel — subir y eliminar materiales

**Files:**
- Modify: `src/Panel/PanelCursosControlador.php` (dos métodos nuevos)
- Modify: `src/Panel/Panel.php` (dos rutas nuevas)
- Test: `tests/Integracion/PanelCursosTest.php`

**Interfaces:**
- Consumes: `App\Soporte\SubidaMaterial::guardar()` (Task 6), `CursoMaterialRepo` (Task 3,
  ya inyectado en el constructor desde la Task 8).
- Produces: `PanelCursosControlador::agregarMaterial(Contexto $ctx): Respuesta`,
  `PanelCursosControlador::eliminarMaterial(Contexto $ctx): Respuesta`.

- [ ] **Step 1: Extender el helper `ctx()` para poder simular una subida de archivo**

El helper `ctx(string $rol, array $formulario = [], array $consulta = []): Contexto`
(líneas 46-61) no tiene forma de simular `$_FILES` hoy — ninguna prueba de este archivo lo
necesitaba hasta ahora. Agrégale un cuarto parámetro opcional:

```php
    /**
     * @param array<string,mixed> $formulario
     * @param array<string,mixed> $archivos entrada cruda de $_FILES, por nombre de campo
     */
    private function ctx(string $rol, array $formulario = [], array $consulta = [], array $archivos = []): Contexto
    {
        return new Contexto(
            new Peticion(
                metodo: $formulario === [] ? 'GET' : 'POST',
                ruta: '/panel/cursos',
                consulta: $consulta,
                formulario: $formulario,
                ip: '190.85.1.1',
                archivos: $archivos,
            ),
            $this->usuario($rol),
            $this->permisos,
            new Csrf(false),
        );
    }
```

(reemplaza la firma y el cuerpo actual de `ctx()` — el único cambio real es agregar
`array $archivos = []` a la firma y `archivos: $archivos,` a la construcción de `Peticion`;
todas las llamadas existentes con 2 o 3 argumentos siguen funcionando igual.)

- [ ] **Step 2: Escribir las pruebas que fallan**

```php
    #[Test]
    public function agregarMaterialLoGuardaEnDiscoYEnLaBaseDeDatos(): void
    {
        $leccionId = $this->leccionDePrueba();
        $tmp = tempnam(sys_get_temp_dir(), 'pa-mat-test-');
        file_put_contents($tmp, '%PDF-1.4 contenido de prueba');

        $ctx = $this->ctx('abogado', ['leccion_id' => $leccionId, 'nombre' => 'Plantilla'], [], [
            'archivo' => ['name' => 'plantilla.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)],
        ]);

        $r = $this->controlador()->agregarMaterial($ctx);

        self::assertSame(302, $r->estado);
        $materiales = (new \App\Repositorios\CursoMaterialRepo($this->bd))->deLeccion($leccionId);
        self::assertCount(1, $materiales);
        self::assertSame('Plantilla', $materiales[0]['nombre']);

        $carpeta = dirname(__DIR__, 2) . '/storage/cursos/materiales/' . $leccionId;
        @unlink($carpeta . '/' . $materiales[0]['archivo'] . '.pdf');
        @rmdir($carpeta);
    }

    #[Test]
    public function eliminarMaterialLoBorraDeDiscoYDeLaBaseDeDatos(): void
    {
        $leccionId = $this->leccionDePrueba();
        $carpeta = dirname(__DIR__, 2) . '/storage/cursos/materiales/' . $leccionId;
        @mkdir($carpeta, 0775, true);
        file_put_contents($carpeta . '/abc123.pdf', 'contenido');

        $materiales = new \App\Repositorios\CursoMaterialRepo($this->bd);
        $id = $materiales->crear($leccionId, 'A borrar', 'abc123', 'pdf', 9);

        $r = $this->controlador()->eliminarMaterial($this->ctx('abogado', ['id' => $id, 'leccion_id' => $leccionId]));

        self::assertSame(302, $r->estado);
        self::assertNull($materiales->porId($id));
        self::assertFileDoesNotExist($carpeta . '/abc123.pdf');
    }
```

`SubidaMaterial::guardar()` (Task 6) usa `move_uploaded_file` por defecto, que en PHPUnit
(sin una petición HTTP real) siempre falla — esto es EXACTAMENTE la misma situación que ya
documenta `SubidaImagen` en su propio comentario de clase. Como `agregarMaterial()` no
recibe un `$mover` inyectable desde el controlador (a diferencia de las pruebas unitarias de
la Task 6, que sí lo inyectan directo), esta prueba de integración necesita que
`move_uploaded_file` funcione — cosa que no ocurre en CLI. **Antes de escribir estas dos
pruebas, revisa cómo el flujo ya existente de subida de imagen de portada del curso
(`PanelCursosControlador::guardar()`, que también usa `SubidaImagen::guardar()` sin `$mover`
inyectado) se prueba hoy en este mismo archivo** — si hay una prueba de integración que
ejercita esa subida de imagen con éxito, sigue exactamente el mismo mecanismo aquí (lo más
probable es que exista una forma de forzar `is_uploaded_file()`/`move_uploaded_file()` a
aceptar el archivo temporal en el entorno de pruebas, documentada donde sea que esa prueba
ya lo resuelve). Si no existe ese precedente todavía, la prueba de `agregarMaterial` puede
en cambio verificar solo el camino de error (extensión no permitida, sin archivo) con
aserciones sobre el mensaje de error — sin intentar que la subida real tenga éxito en CLI —
y dejar la verificación del camino exitoso para la Task 14 (verificación manual en el VPS,
donde sí hay una petición HTTP real).

- [ ] **Step 2: Confirmar que fallan**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: FAIL — `Call to undefined method App\Panel\PanelCursosControlador::agregarMaterial()`

- [ ] **Step 3: Implementar**

En `src/Panel/PanelCursosControlador.php`, agregar después de `guardarLeccion()`:

```php
    public function agregarMaterial(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $leccionId = $ctx->campo('leccion_id');
        $nombre = $ctx->campo('nombre');
        $rutaVuelta = '/panel/cursos/lecciones/editar?id=' . urlencode($leccionId);

        if ($nombre === '') {
            return $this->redirigirCon($rutaVuelta, 'error', 'El material necesita un nombre.');
        }

        $carpeta = dirname(__DIR__, 2) . '/storage/cursos/materiales/' . $leccionId;
        $subida = \App\Soporte\SubidaMaterial::guardar($ctx->archivo('archivo'), $carpeta);

        if ($subida['error'] !== '') {
            return $this->redirigirCon($rutaVuelta, 'error', $subida['error']);
        }
        if (!$subida['ok']) {
            return $this->redirigirCon($rutaVuelta, 'error', 'Seleccione un archivo.');
        }

        $id = $this->materiales->crear($leccionId, $nombre, $subida['archivo'], $subida['extension'], $subida['tamanioBytes']);
        $this->auditoria->registrar('curso_material', $id, 'crear', $ctx->actor(), ['nombre' => $nombre], $ctx->ip());

        return $this->redirigirCon($rutaVuelta, 'ok', 'Material subido.');
    }

    public function eliminarMaterial(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $leccionId = $ctx->campo('leccion_id');
        $rutaVuelta = '/panel/cursos/lecciones/editar?id=' . urlencode($leccionId);

        $material = $this->materiales->porId($id);
        if ($material === null) {
            return $this->redirigirCon($rutaVuelta, 'error', 'Ese material no existe.');
        }

        $ruta = dirname(__DIR__, 2) . '/storage/cursos/materiales/' . $material['leccion_id']
            . '/' . $material['archivo'] . '.' . $material['extension'];
        if (is_file($ruta)) {
            @unlink($ruta);
        }

        $this->materiales->eliminar($id);
        $this->auditoria->registrar('curso_material', $id, 'eliminar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($rutaVuelta, 'ok', 'Material eliminado.');
    }
```

- [ ] **Step 4: Cablear las rutas en `src/Panel/Panel.php`**

Insertar inmediatamente después de las rutas de la Task 8:

```php
            'POST /cursos/lecciones/materiales/agregar' => $modulos['cursos']->agregarMaterial($ctx),
            'POST /cursos/lecciones/materiales/eliminar' => $modulos['cursos']->eliminarMaterial($ctx),
```

- [ ] **Step 5: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Panel/PanelCursosControlador.php src/Panel/Panel.php tests/Integracion/PanelCursosTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): subir y eliminar materiales descargables desde el panel

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Buyer — el «aula» (`/mis-cursos/{slug}`)

**Files:**
- Create: `src/Cuenta/AulaControlador.php`
- Create: `plantillas/cuenta/aula.php`
- Test: `tests/Integracion/AulaControladorTest.php`

**Interfaces:**
- Consumes: `AutenticacionComprador::compradorDeSesion()`, `Cursos::porSlug()`,
  `AccesoLeccion::puedeVer()` (Task 4).
- Produces: `AulaControlador::__construct(AutenticacionComprador $auth, Cursos $cursos, CompraCursoRepo $compras, AccesoLeccion $acceso, BD $bd)`,
  `AulaControlador::aula(Peticion $peticion, string $slug): Respuesta`. Las Tasks 11 y 12
  agregan más métodos a esta misma clase — el constructor no vuelve a cambiar después
  (ya incluye todo lo que las tres rutas de este controlador van a necesitar).

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\AccesoControlador;
use App\Cuenta\AccesoLeccion;
use App\Cuenta\AulaControlador;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Servicios\ConfigMysql;
use App\Servicios\Cursos;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class AulaControladorTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private AulaControlador $controlador;
    private CompradorRepo $compradores;
    private CompraCursoRepo $compras;
    private AutenticacionComprador $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->compras = new CompraCursoRepo($this->bd);
        $sesiones = new CompradorSesionRepo($this->bd);
        $this->auth = new AutenticacionComprador($this->compradores, $sesiones, new IntentoAccesoRepo($this->bd));

        // Mismo patrón que tests/Integracion/CursosTest.php::setUp(): ConfigMysql
        // exige rutas de archivo propias por corrida para no chocar con otras
        // pruebas del mismo proceso.
        $sufijo = bin2hex(random_bytes(4));
        $config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-aula-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-aula-cfg-{$sufijo}.json",
        );
        $cursos = new Cursos($this->bd, $config, self::URL);

        $this->controlador = new AulaControlador(
            $this->auth,
            $cursos,
            $this->compras,
            new AccesoLeccion($this->compras),
            $this->bd,
        );
    }

    private function curso(string $slug): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-' . $slug]);
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, 'Curso aula', $slug, 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    private function peticion(): Peticion
    {
        return new Peticion(metodo: 'GET', ruta: '/mis-cursos/x');
    }

    #[Test]
    public function sinSesionRedirigeAEntrar(): void
    {
        $r = $this->controlador->aula($this->peticion(), 'cualquier-curso');

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function conSesionPeroSinHaberPagadoRedirigeAMisCursos(): void
    {
        $this->curso('curso-no-pagado');
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana@ejemplo.com', 'clave123');
        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->aula($this->peticion(), 'curso-no-pagado');

        self::assertSame(302, $r->estado);
        self::assertSame('/mis-cursos', $r->cabeceras['Location']);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function conElCursoPagadoMuestraElTemario(): void
    {
        $cursoId = $this->curso('curso-pagado-aula');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo uno', 0]);
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (UUID(), ?, ?, ?)')
            ->execute([$moduloId, 'Lección uno', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana2@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana2@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->aula($this->peticion(), 'curso-pagado-aula');

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('Módulo uno', $r->cuerpo);
        self::assertStringContainsString('Lección uno', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }
}
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: FAIL — `Class "App\Cuenta\AulaControlador" not found`

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\BD;
use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\AutenticacionComprador;
use App\Servicios\Cursos;

final class AulaControlador
{
    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly Cursos $cursos,
        private readonly CompraCursoRepo $compras,
        private readonly AccesoLeccion $acceso,
        private readonly BD $bd,
    ) {
    }

    public function aula(Peticion $peticion, string $slug): Respuesta
    {
        $comprador = $this->compradorActual();
        if ($comprador === null) {
            return new Respuesta('', 302, ['Location' => '/entrar']);
        }

        $curso = $this->cursos->porSlug($slug);
        if ($curso === null || !$this->compras->tienePagada($comprador->id, $curso['id'])) {
            return new Respuesta('', 302, ['Location' => '/mis-cursos']);
        }

        return Respuesta::vista('cuenta/aula', [
            'curso' => $curso,
            'modulos' => $this->temario($curso['id']),
        ]);
    }

    /** @return \App\Modelos\Comprador|null */
    private function compradorActual(): ?\App\Modelos\Comprador
    {
        $token = $_COOKIE[AccesoControlador::COOKIE] ?? null;

        return (is_string($token) && $token !== '') ? $this->auth->compradorDeSesion($token) : null;
    }

    /** @return list<array{id:string,titulo:string,lecciones:list<array<string,mixed>>}> */
    private function temario(string $cursoId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, titulo, orden FROM curso_modulos WHERE curso_id = ? ORDER BY orden'
        );
        $stmt->execute([$cursoId]);
        $modulos = $stmt->fetchAll();

        foreach ($modulos as &$modulo) {
            $stmtL = $this->bd->pdo()->prepare(
                'SELECT id, titulo, duracion_min, orden, vista_previa_gratis
                   FROM curso_lecciones WHERE modulo_id = ? ORDER BY orden'
            );
            $stmtL->execute([$modulo['id']]);
            $modulo['lecciones'] = $stmtL->fetchAll();
        }
        unset($modulo);

        return $modulos;
    }
}
```

- [ ] **Step 4: Escribir la plantilla `plantillas/cuenta/aula.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var list<array{id:string,titulo:string,lecciones:list<array<string,mixed>>}> $modulos
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e((string) $curso['titulo']) ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/mis-cursos" class="menu-enlace">← Mis cursos</a>
    </div>
</header>

<main class="mx-auto max-w-3xl px-5 py-12 md:px-7">
    <h1 class="titular-seccion"><?= $e((string) $curso['titulo']) ?></h1>

    <?php foreach ($modulos as $modulo): ?>
    <section class="doble-bisel mt-6 p-4">
        <h2 class="font-semibold"><?= $e((string) $modulo['titulo']) ?></h2>
        <ul class="mt-2 space-y-1 text-sm">
            <?php foreach ($modulo['lecciones'] as $leccion): ?>
            <li>
                <a href="/mis-cursos/<?= $e((string) $curso['slug']) ?>/leccion/<?= $e((string) $leccion['id']) ?>"
                   class="menu-enlace">
                    <?= $e((string) $leccion['titulo']) ?>
                    <?= $leccion['duracion_min'] !== null ? ' · ' . $e((string) $leccion['duracion_min']) . ' min' : '' ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
</main>

</body>
</html>
```

- [ ] **Step 5: Cablear la ruta en `src/Core/Aplicacion.php`**

Insertar inmediatamente después de la ruta `GET /mis-cursos` ya existente en
`registrarRutas()`:

```php
        $this->router->get('/mis-cursos/{slug}', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\AulaControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                new \App\Cuenta\AccesoLeccion($this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class)),
                $this->contenedor->obtener(BD::class),
            ))->aula($p, (string) $p->parametros['slug']);
        });
```

**Ojo con el orden de rutas:** `src/Core/Router.php` hace matching exacto segmento por
segmento (sin prioridad de especificidad) y resuelve con la PRIMERA ruta registrada que
coincida — `/mis-cursos/completar` y `/mis-cursos/{slug}` tienen la misma cantidad de
segmentos, así que si `{slug}` se registrara ANTES, capturaría por error la palabra
`completar` como si fuera un slug de curso. Ya verificado: `GET /mis-cursos/completar` está
registrada más arriba en `registrarRutas()` (dentro del bloque de `$accesoControlador`) que
la ruta `GET /mis-cursos` existente — insertar esta ruta nueva justo después de
`GET /mis-cursos` (como se indica arriba) la deja automáticamente después de
`/mis-cursos/completar` también. No hace falta mover nada más.

- [ ] **Step 6: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: PASS (3 tests)

- [ ] **Step 7: Confirmar que `/mis-cursos/completar` sigue funcionando**

Run: `vendor/bin/phpunit tests/Integracion/AccesoControladorTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add src/Cuenta/AulaControlador.php plantillas/cuenta/aula.php src/Core/Aplicacion.php \
        tests/Integracion/AulaControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): el aula - temario del curso ya pagado en /mis-cursos/{slug}

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Buyer — contenido de la lección (`/mis-cursos/{slug}/leccion/{id}`)

**Files:**
- Modify: `src/Cuenta/AulaControlador.php` (un método nuevo)
- Modify: `src/Soporte/Vista.php` (helper `parrafos()`)
- Create: `plantillas/cuenta/leccion.php`
- Modify: `src/Core/Aplicacion.php` (una ruta nueva)
- Test: `tests/Integracion/AulaControladorTest.php`

**Interfaces:**
- Consumes: `AccesoLeccion::puedeVer()`, `BunnyStream` (Task 5), `CursoMaterialRepo::deLeccion()` (Task 3).
- Produces: `AulaControlador::leccion(Peticion $peticion, string $slug, string $leccionId): Respuesta`.

- [ ] **Step 1: Escribir las pruebas que fallan**

Agregar a `tests/Integracion/AulaControladorTest.php`. El constructor de `AulaControlador`
gana `BunnyStream $bunny` y `CursoMaterialRepo $materiales` — actualiza `setUp()` para
pasarlos (`new BunnyStream('', '')` para las pruebas que no verifican el video, y
`new CursoMaterialRepo($this->bd)`):

```php
    private function leccionEnCurso(string $cursoId, bool $preview = false): string
    {
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden, vista_previa_gratis, contenido_texto)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$leccionId, $moduloId, 'Lección con contenido', 0, $preview ? 1 : 0, 'El contenido de la lección.']);

        return $leccionId;
    }

    #[Test]
    public function unaLeccionDePreviewSeVeSinSesion(): void
    {
        $cursoId = $this->curso('curso-preview-leccion');
        $leccionId = $this->leccionEnCurso($cursoId, preview: true);

        $r = $this->controlador->leccion($this->peticion(), 'curso-preview-leccion', $leccionId);

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('El contenido de la lección.', $r->cuerpo);
    }

    #[Test]
    public function unaLeccionNoPreviewSinSesionRedirigeAEntrar(): void
    {
        $cursoId = $this->curso('curso-no-preview-leccion');
        $leccionId = $this->leccionEnCurso($cursoId, preview: false);

        $r = $this->controlador->leccion($this->peticion(), 'curso-no-preview-leccion', $leccionId);

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function unaLeccionQueNoPerteneceAlCursoDeLaUrlDa404(): void
    {
        $cursoId = $this->curso('curso-a-leccion');
        $otroCursoId = $this->curso('curso-b-leccion');
        $leccionDeOtroCurso = $this->leccionEnCurso($otroCursoId, preview: true);

        $r = $this->controlador->leccion($this->peticion(), 'curso-a-leccion', $leccionDeOtroCurso);

        self::assertSame(404, $r->estado);
    }
```

- [ ] **Step 2: Confirmar que fallan**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: FAIL — `Call to undefined method App\Cuenta\AulaControlador::leccion()`

- [ ] **Step 3: Agregar el helper de párrafos a `src/Soporte/Vista.php`**

Agregar después de `e()`:

```php
    /**
     * Texto plano → párrafos escapados. Los saltos de línea dobles separan
     * párrafo; los simples se mantienen dentro del mismo `<p>` como `<br>`.
     * El texto en sí SIEMPRE pasa por `e()` primero — nunca se interpreta
     * como HTML, sin importar quién lo escribió (ver ADR de contenido de
     * lecciones, spec del sub-proyecto 3).
     */
    public static function parrafos(?string $texto): string
    {
        $texto = trim($texto ?? '');
        if ($texto === '') {
            return '';
        }

        $bloques = preg_split('/\n{2,}/', $texto) ?: [$texto];
        $html = '';
        foreach ($bloques as $bloque) {
            $html .= '<p>' . nl2br(self::e(trim($bloque)), false) . '</p>';
        }

        return $html;
    }
```

- [ ] **Step 4: Implementar el método del controlador**

El constructor de `AulaControlador` gana dos dependencias más:

```php
    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly Cursos $cursos,
        private readonly CompraCursoRepo $compras,
        private readonly AccesoLeccion $acceso,
        private readonly BD $bd,
        private readonly \App\Soporte\BunnyStream $bunny,
        private readonly \App\Repositorios\CursoMaterialRepo $materiales,
    ) {
    }
```

Agregar después de `aula()`:

```php
    public function leccion(Peticion $peticion, string $slug, string $leccionId): Respuesta
    {
        $curso = $this->cursos->porSlug($slug);
        if ($curso === null) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $leccion = $this->leccionDelCurso($curso['id'], $leccionId);
        if ($leccion === null) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $comprador = $this->compradorActual();
        if (!$this->acceso->puedeVer($comprador, $leccion, $curso['id'])) {
            return $comprador === null
                ? new Respuesta('', 302, ['Location' => '/entrar'])
                : new Respuesta('', 302, ['Location' => '/mis-cursos']);
        }

        return Respuesta::vista('cuenta/leccion', [
            'curso' => $curso,
            'leccion' => $leccion,
            'materiales' => $this->materiales->deLeccion($leccionId),
            'urlVideo' => ($leccion['video_bunny_id'] !== null && $this->bunny->disponible())
                ? $this->bunny->urlEmbed((string) $leccion['video_bunny_id'])
                : null,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function leccionDelCurso(string $cursoId, string $leccionId): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT cl.* FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cl.id = ? AND cm.curso_id = ?'
        );
        $stmt->execute([$leccionId, $cursoId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }
```

- [ ] **Step 5: Escribir la plantilla `plantillas/cuenta/leccion.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var array<string,mixed> $leccion
 * @var list<array<string,mixed>> $materiales
 * @var string|null $urlVideo
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e((string) $leccion['titulo']) ?> — <?= $e((string) $curso['titulo']) ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/mis-cursos/<?= $e((string) $curso['slug']) ?>" class="menu-enlace">← <?= $e((string) $curso['titulo']) ?></a>
    </div>
</header>

<main class="mx-auto max-w-3xl px-5 py-12 md:px-7">
    <h1 class="titular-seccion"><?= $e((string) $leccion['titulo']) ?></h1>

    <?php if ($urlVideo !== null): ?>
    <div class="mt-6 aspect-video">
        <iframe src="<?= $e($urlVideo) ?>" loading="lazy" allow="autoplay; fullscreen"
                class="h-full w-full rounded" allowfullscreen></iframe>
    </div>
    <?php elseif ($leccion['video_bunny_id'] !== null): ?>
    <p class="mt-6 text-acero">Video no disponible por ahora.</p>
    <?php endif; ?>

    <?php if (!empty($leccion['contenido_texto'])): ?>
    <div class="mt-6 space-y-4 text-acero">
        <?= Vista::parrafos((string) $leccion['contenido_texto']) ?>
    </div>
    <?php endif; ?>

    <?php if ($materiales !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Materiales</h2>
        <ul class="mt-3 space-y-2">
            <?php foreach ($materiales as $m): ?>
            <li>
                <a href="/mis-cursos/<?= $e((string) $curso['slug']) ?>/leccion/<?= $e((string) $leccion['id']) ?>/material/<?= $e((string) $m['id']) ?>"
                   class="menu-enlace">
                    <?= $e((string) $m['nombre']) ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</main>

</body>
</html>
```

- [ ] **Step 6: Cablear la ruta en `src/Core/Aplicacion.php`**

Insertar inmediatamente después de la ruta `/mis-cursos/{slug}` de la Task 10, actualizando
también esa construcción del controlador para pasar las dos dependencias nuevas:

```php
        $this->router->get('/mis-cursos/{slug}/leccion/{leccionId}', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\AulaControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                new \App\Cuenta\AccesoLeccion($this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class)),
                $this->contenedor->obtener(BD::class),
                \App\Soporte\BunnyStream::desdeEntorno(),
                $this->contenedor->obtener(\App\Repositorios\CursoMaterialRepo::class),
            ))->leccion($p, (string) $p->parametros['slug'], (string) $p->parametros['leccionId']);
        });
```

El constructor cambió, así que la ruta `/mis-cursos/{slug}` de la Task 10 (ya cableada, más
arriba en el archivo) también hay que actualizarla para que pase los mismos 7 argumentos —
reemplázala por:

```php
        $this->router->get('/mis-cursos/{slug}', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\AulaControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                new \App\Cuenta\AccesoLeccion($this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class)),
                $this->contenedor->obtener(BD::class),
                \App\Soporte\BunnyStream::desdeEntorno(),
                $this->contenedor->obtener(\App\Repositorios\CursoMaterialRepo::class),
            ))->aula($p, (string) $p->parametros['slug']);
        });
```

- [ ] **Step 7: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: PASS (todas, incluidas las 3 nuevas)

- [ ] **Step 8: Commit**

```bash
git add src/Cuenta/AulaControlador.php src/Soporte/Vista.php plantillas/cuenta/leccion.php \
        src/Core/Aplicacion.php tests/Integracion/AulaControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): pagina de contenido de la leccion - video, texto y materiales

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Buyer — descarga de material

**Files:**
- Modify: `src/Cuenta/AulaControlador.php` (un método nuevo)
- Modify: `src/Core/Aplicacion.php` (una ruta nueva)
- Test: `tests/Integracion/AulaControladorTest.php`

**Interfaces:**
- Consumes: `Respuesta::archivo()` (Task 7), `CursoMaterialRepo::porId()` (Task 3).
- Produces: `AulaControlador::material(Peticion $peticion, string $slug, string $leccionId, string $materialId): Respuesta`.

- [ ] **Step 1: Escribir las pruebas que fallan**

```php
    #[Test]
    public function descargarUnMaterialSinAccesoRedirige(): void
    {
        $cursoId = $this->curso('curso-material-sin-acceso');
        $leccionId = $this->leccionEnCurso($cursoId, preview: false);
        $materiales = new \App\Repositorios\CursoMaterialRepo($this->bd);
        $materialId = $materiales->crear($leccionId, 'Plantilla', 'abc', 'pdf', 10);

        $r = $this->controlador->material($this->peticion(), 'curso-material-sin-acceso', $leccionId, $materialId);

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function descargarUnMaterialConAccesoDevuelveElArchivo(): void
    {
        $cursoId = $this->curso('curso-material-con-acceso');
        $leccionId = $this->leccionEnCurso($cursoId, preview: true);
        $carpeta = dirname(__DIR__, 2) . '/storage/cursos/materiales/' . $leccionId;
        @mkdir($carpeta, 0775, true);
        file_put_contents($carpeta . '/abc123.pdf', '%PDF-1.4 contenido');

        $materiales = new \App\Repositorios\CursoMaterialRepo($this->bd);
        $materialId = $materiales->crear($leccionId, 'Plantilla', 'abc123', 'pdf', 19);

        $r = $this->controlador->material($this->peticion(), 'curso-material-con-acceso', $leccionId, $materialId);

        self::assertSame(200, $r->estado);
        self::assertSame('%PDF-1.4 contenido', $r->cuerpo);
        self::assertStringContainsString('Plantilla.pdf', $r->cabeceras['Content-Disposition']);

        unlink($carpeta . '/abc123.pdf');
        rmdir($carpeta);
    }

    #[Test]
    public function descargarUnMaterialDeOtraLeccionDa404(): void
    {
        $cursoId = $this->curso('curso-material-otra-leccion');
        $leccionId = $this->leccionEnCurso($cursoId, preview: true);
        $otraLeccionId = $this->leccionEnCurso($cursoId, preview: true);
        $materiales = new \App\Repositorios\CursoMaterialRepo($this->bd);
        $materialId = $materiales->crear($otraLeccionId, 'Plantilla', 'abc', 'pdf', 10);

        $r = $this->controlador->material($this->peticion(), 'curso-material-otra-leccion', $leccionId, $materialId);

        self::assertSame(404, $r->estado);
    }
```

- [ ] **Step 2: Confirmar que fallan**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: FAIL — `Call to undefined method App\Cuenta\AulaControlador::material()`

- [ ] **Step 3: Implementar**

Agregar a `src/Cuenta/AulaControlador.php`, después de `leccion()`:

```php
    private const MIMES_MATERIAL = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip', 'jpg' => 'image/jpeg', 'png' => 'image/png',
    ];

    public function material(Peticion $peticion, string $slug, string $leccionId, string $materialId): Respuesta
    {
        $curso = $this->cursos->porSlug($slug);
        if ($curso === null) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $leccion = $this->leccionDelCurso($curso['id'], $leccionId);
        if ($leccion === null) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $material = $this->materiales->porId($materialId);
        if ($material === null || $material['leccion_id'] !== $leccionId) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $comprador = $this->compradorActual();
        if (!$this->acceso->puedeVer($comprador, $leccion, $curso['id'])) {
            return $comprador === null
                ? new Respuesta('', 302, ['Location' => '/entrar'])
                : new Respuesta('', 302, ['Location' => '/mis-cursos']);
        }

        $ruta = dirname(__DIR__, 2) . '/storage/cursos/materiales/' . $leccionId
            . '/' . $material['archivo'] . '.' . $material['extension'];

        if (!is_file($ruta)) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $mime = self::MIMES_MATERIAL[$material['extension']] ?? 'application/octet-stream';

        return Respuesta::archivo(
            (string) file_get_contents($ruta),
            $material['nombre'] . '.' . $material['extension'],
            $mime,
        );
    }
```

- [ ] **Step 4: Cablear la ruta en `src/Core/Aplicacion.php`**

Insertar inmediatamente después de la ruta de la Task 11:

```php
        $this->router->get('/mis-cursos/{slug}/leccion/{leccionId}/material/{materialId}', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\AulaControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                new \App\Cuenta\AccesoLeccion($this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class)),
                $this->contenedor->obtener(BD::class),
                \App\Soporte\BunnyStream::desdeEntorno(),
                $this->contenedor->obtener(\App\Repositorios\CursoMaterialRepo::class),
            ))->material(
                $p,
                (string) $p->parametros['slug'],
                (string) $p->parametros['leccionId'],
                (string) $p->parametros['materialId'],
            );
        });
```

- [ ] **Step 5: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: PASS (todas)

- [ ] **Step 6: Commit**

```bash
git add src/Cuenta/AulaControlador.php src/Core/Aplicacion.php tests/Integracion/AulaControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): descarga protegida de materiales de una leccion

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: Ficha pública — video de vista previa

**Files:**
- Modify: `src/Servicios/Cursos.php` (`ficha()` y `temario()` privado)
- Modify: `plantillas/cursos/ficha.php`
- Test: `tests/Integracion/CursosTest.php`

**Interfaces:**
- Consumes: `BunnyStream` (Task 5).
- Produces: `Cursos::__construct()` gana `BunnyStream $bunny` — actualiza el registro en el
  contenedor y CUALQUIER otro sitio que construya `Cursos` directamente (revisa con Grep).

- [ ] **Step 1: Escribir la prueba que falla**

El archivo ya tiene `categoria(string $nombre = 'Aduanero'): string` y
`curso(string $categoriaId, array $overrides = []): string` (líneas 32-69), y `setUp()` ya
construye `$this->cursos` con un `ConfigMysql` de rutas temporales propias — reutilízalos tal
cual, sin inventar un `Config($this->bd)` que no existe (la interfaz se llama `Config`, la
implementación real es `ConfigMysql`, y exige 3 argumentos — mismo patrón que `setUp()` ya
usa). Como `$this->cursos` no lleva `BunnyStream`, esta prueba construye su PROPIA instancia
de `Cursos` con credenciales de Bunny de prueba:

```php
    #[Test]
    public function laFichaIncrustaElVideoDeUnaLeccionDePreview(): void
    {
        $catId = $this->categoria();
        $cursoId = $this->curso($catId, ['slug' => 'curso-con-preview-video', 'estado' => 'publicado']);
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden, vista_previa_gratis, video_bunny_id)
             VALUES (UUID(), ?, ?, ?, 1, ?)'
        )->execute([$moduloId, 'Lección de muestra', 0, 'video-preview-1']);

        $cursosConBunny = new Cursos(
            $this->bd, $this->config, self::URL,
            new \App\Soporte\BunnyStream('12345', 'clave-secreta'),
        );

        $r = $cursosConBunny->ficha('curso-con-preview-video');

        self::assertStringContainsString('iframe.mediadelivery.net', $r->cuerpo);
    }
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/CursosTest.php`
Expected: FAIL — la prueba nueva falla por `BunnyStream` inexistente (fallará distinto según
el orden de implementación; si escribes el test antes que la clase, falla con
`Class "App\Soporte\BunnyStream" not found` — es igual de válido como confirmación de que
falla).

- [ ] **Step 3: Implementar**

En `src/Servicios/Cursos.php`, actualizar el constructor:

```php
    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
        private readonly string $urlBase,
        private readonly \App\Soporte\BunnyStream $bunny,
    ) {
    }
```

Modificar `temario()` (privado) para incluir `video_bunny_id`:

```php
        $stmt = $this->bd->pdo()->prepare(
            "SELECT modulo_id, titulo, duracion_min, orden, vista_previa_gratis, video_bunny_id
               FROM curso_lecciones WHERE modulo_id IN ({$marcas}) ORDER BY orden"
        );
```

(reemplaza la línea `SELECT modulo_id, titulo, duracion_min, orden, vista_previa_gratis` ya
existente en ese método — es la única diferencia)

Modificar `ficha()` para calcular la URL de la primera lección de preview con video:

```php
    public function ficha(string $slug): Respuesta
    {
        $curso = $this->buscarPorSlug($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        $modulos = $this->temario($curso['id']);

        return Respuesta::vista('cursos/ficha', [
            'curso' => $curso,
            'modulos' => $modulos,
            'urlVideoPreview' => $this->urlVideoPreview($modulos),
            'meta' => $this->meta(
                $curso['titulo'],
                $curso['resumen'],
                '/cursos/' . $slug,
                $curso['estado'] === 'borrador' ? false : null,
            ),
        ]);
    }

    /** @param list<array{titulo:string,lecciones:list<array<string,mixed>>}> $modulos */
    private function urlVideoPreview(array $modulos): ?string
    {
        if (!$this->bunny->disponible()) {
            return null;
        }

        foreach ($modulos as $modulo) {
            foreach ($modulo['lecciones'] as $leccion) {
                if ((int) $leccion['vista_previa_gratis'] === 1 && $leccion['video_bunny_id'] !== null) {
                    return $this->bunny->urlEmbed((string) $leccion['video_bunny_id']);
                }
            }
        }

        return null;
    }
```

**Importante:** `tests/Integracion/CursosTest.php::setUp()` construye `$this->cursos = new Cursos($this->bd, $this->config, self::URL);` con 3 argumentos — con el constructor ya cambiado a 4, ESA línea rompe TODAS las demás pruebas del archivo, no solo la nueva. Actualízala en el mismo paso:

```php
        $this->cursos = new Cursos($this->bd, $this->config, self::URL, new \App\Soporte\BunnyStream('', ''));
```

(`BunnyStream('', '')` está deliberadamente sin credenciales — `disponible()` da `false`, así
que ninguna de las pruebas existentes que no hablan de video se ve afectada; solo la prueba
nueva de este Task construye su propia instancia de `Cursos` con un `BunnyStream` real, como
se ve en el Step 1.)

**Hay un segundo sitio con el mismo problema:** `tests/Integracion/ComprasControladorTest.php:34`
también construye `Cursos` con 3 argumentos (`$this->cursosServicio = new Cursos($this->bd, $config, self::URL);`).
Arréglalo igual:

```php
        $this->cursosServicio = new Cursos($this->bd, $config, self::URL, new \App\Soporte\BunnyStream('', ''));
```

Confirmado con Grep (`grep -rn "new Cursos(" src/ tests/`) que estos son los ÚNICOS DOS sitios
del repo que construyen `Cursos` directamente además del registro en el contenedor
(`src/Core/Aplicacion.php`) — no hay un tercero que se te vaya a escapar.

- [ ] **Step 4: Actualizar `plantillas/cursos/ficha.php`**

Agregar el bloque de video justo antes de la sección `<?php if ($curso['lo_que_aprendera'] !== []): ?>`:

```php
    <?php if ($urlVideoPreview !== null): ?>
    <div class="mt-6 aspect-video">
        <iframe src="<?= $e($urlVideoPreview) ?>" loading="lazy" allow="autoplay; fullscreen"
                class="h-full w-full rounded" allowfullscreen></iframe>
    </div>
    <?php endif; ?>
```

Y agregar `@var string|null $urlVideoPreview` al bloque de docblock `@var` al inicio del
archivo.

- [ ] **Step 5: Actualizar el registro del contenedor en `src/Core/Aplicacion.php`**

Busca `\App\Servicios\Cursos::class,` en `registrarServicios()` y agrega `BunnyStream` a su
factory:

```php
        $this->contenedor->registrar(
            \App\Servicios\Cursos::class,
            static fn (Contenedor $c): \App\Servicios\Cursos => new \App\Servicios\Cursos(
                $c->obtener(BD::class),
                $c->obtener(Config::class),
                $urlBase,
                \App\Soporte\BunnyStream::desdeEntorno(),
            ),
        );
```

(reemplaza el registro existente de `Cursos::class` — busca con Grep el bloque actual antes
de reemplazarlo, para conservar exactamente el resto de su forma.)

- [ ] **Step 6: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/CursosTest.php`
Expected: PASS (incluida la nueva)

- [ ] **Step 7: Confirmar que el checkout público sigue funcionando**

Run: `vendor/bin/phpunit tests/Integracion/ComprasControladorTest.php tests/Integracion/ArranqueTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add src/Servicios/Cursos.php plantillas/cursos/ficha.php src/Core/Aplicacion.php \
        tests/Integracion/CursosTest.php tests/Integracion/ComprasControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): video de vista previa incrustado en la ficha publica

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: Suite completa y verificación manual

**Files:** none (verification only)

- [ ] **Step 1: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: todo en verde, incluidas todas las pruebas previas y las agregadas en las Tasks
1-13.

- [ ] **Step 2: Verificar manualmente el flujo de contenido, en el VPS después de desplegar**

Por convención del proyecto, la verificación funcional siempre se hace en producción, no en
local (ver memoria `pruebas-en-produccion`). Una vez desplegado: en el panel, agregar
`video_bunny_id` y `contenido_texto` a una lección de un curso ya comprado en pruebas
(sub-proyecto 2), subir un material, marcar una lección como vista previa gratis. Visitar
`/cursos/{slug}` sin sesión y confirmar que el video de preview se ve. Iniciar sesión con un
comprador que pagó ese curso, visitar `/mis-cursos/{slug}`, entrar a una lección no-preview y
confirmar que el video/texto/material se ven. Cerrar sesión e intentar la misma URL de
lección directamente: debe redirigir a `/entrar`. Con otra cuenta de comprador que no pagó
ese curso, la misma URL debe redirigir a `/mis-cursos`.

- [ ] **Step 3: Reportar**

Reportar qué verificaciones manuales pasaron y cuáles se omitieron por falta de credenciales
de Bunny Stream configuradas (ver la nota operativa abajo — es una omisión esperada mientras
esas credenciales no existan, no un defecto del código).

## Pendiente operativo (no es código de este plan)

- [ ] Contratar Bunny Stream, crear la librería de video, y poner
      `BUNNY_LIBRARY_ID` / `BUNNY_STREAM_SECURITY_KEY` en el `.env` del VPS — Elkin.
- [ ] Aumentar `client_max_body_size` en el nginx del VPS para la ruta
      `/panel/cursos/lecciones/materiales/agregar` a 30M (el resto del panel se queda en el
      8M ya configurado para imágenes) — cambio de infraestructura, no de este repositorio.
- [ ] Documentar para Pedro el paso a paso de subir un video en el panel de Bunny y copiar
      el ID, una vez la librería exista.
