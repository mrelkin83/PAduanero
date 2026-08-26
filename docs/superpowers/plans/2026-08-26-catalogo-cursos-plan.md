# Catálogo público de cursos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the public course catalog (categories, courses, curriculum) with a panel-managed menu toggle and full CRUD in `/panel/cursos` — sub-project 1 of 3, no payment or buyer accounts yet.

**Architecture:** Four new tables behind an additive migration, a stateless public service (`App\Servicios\Cursos`, no page cache — unlike `Landing`/`Perfil` it serves N different pages, so the single-file `CachePagina` doesn't fit), a panel controller (`App\Panel\PanelCursosControlador`) following the exact shape of the existing `TarifasControlador`, and a menu toggle stored as one row in the existing generic `configuraciones` table.

**Tech Stack:** PHP 8.2+, MySQL 8, plain PDO (no ORM), PHPUnit, Tailwind CSS 4 (pre-built utility classes from `resources/css/app.css` / `resources/css/panel.css`), no JS framework.

**Spec:** `docs/superpowers/specs/2026-08-26-catalogo-cursos-design.md`

## Global Constraints

- Money is stored as whole COP pesos, never cents (ADR-010). Every price field/guard must reject `precio_cop >= 10_000_000` as a probable cents-typo, exactly like `TarifasControlador::guardar()`.
- Migrations are numbered, idempotent, and additive only (ADR-013) — this plan's migration is `db/migraciones/0028_cursos.sql`, next after `0027`.
- All escaped output in templates goes through `App\Soporte\Vista::e()` — never raw `echo`.
- Every panel write is permission-gated via `$ctx->permisos->exigir($ctx->usuario, '<clave>')` and logged via `AuditoriaRepo::registrar()`.
- CSRF is validated centrally by `App\Panel\Panel::despachar()` before any module runs — new panel POST routes don't add their own CSRF checks.
- No new front-end JavaScript or build tooling — plain PHP-rendered forms and pages, consistent with the rest of the project.
- **Deviation from the spec's wording, decided during codebase research:** §5 of the spec says the public course pages get "su propia `CachePagina`, mismo patrón que `Perfil`." That doesn't fit: `CachePagina` caches exactly one fixed HTML file per instance, and courses are N rows with N slugs (plus a filterable catalog) — reusing it would either serve the wrong course/filter from cache or require one `CachePagina` instance per course, which the class isn't built for. This plan skips page caching for `/cursos*` entirely; each request queries MySQL directly (cheap, indexed queries). Flagged here instead of silently diverging from the approved spec.
- **Also decided in research, not spec'd explicitly:** deep nested-resource panel routes (e.g. a lesson's ID in the URL under its module under its course) don't fit the app's router, which only supports up to 3 wildcard path segments under `/panel` (`/panel/{a}/{b}/{c}`, see `src/Core/Aplicacion.php`). All new panel routes stay at or under that depth; IDs that would otherwise need a 4th+ path segment travel as POST form fields instead (already the project's convention — see `TarifasControlador::guardar()` reading `id` from `$ctx->campo('id')`, not the URL).

---

### Task 1: Migration — schema, permissions, menu toggle

**Files:**
- Create: `db/migraciones/0028_cursos.sql`
- Modify: `tests/CasoBaseBd.php:184-193` (add new tables to the per-test `TRUNCATE` list)

**Interfaces:**
- Produces: tables `categorias_curso`, `cursos`, `curso_modulos`, `curso_lecciones` (columns per spec §4); permissions `cursos.ver` / `cursos.editar` granted to roles `abogado` and `super_admin`; a `configuraciones` row `clave='cursos_activo'` (`tipo='booleano'`, `grupo='cursos'`, default `false`).
- Consumes: existing tables `roles`, `permisos`, `roles_permisos`, `configuraciones` (schema from `db/migraciones/0002_panel.sql`).

- [ ] **Step 1: Write the migration file**

```sql
-- =====================================================================
-- 0028 — CURSOS — catálogo público (sub-proyecto 1 de 3)
--
-- Migración aditiva (ADR-013). Implementa
-- docs/superpowers/specs/2026-08-26-catalogo-cursos-design.md: tablas del
-- catálogo y el temario (títulos y duración, sin contenido protegido
-- todavía — eso es el sub-proyecto 3), permisos nuevos y el interruptor de
-- menú. No siembra categorías ni cursos de ejemplo (ver memoria del
-- proyecto sobre datos de prueba en `confianza`): Pedro los crea desde el
-- panel cuando tenga cursos reales.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categorias_curso (
  id         CHAR(36)     NOT NULL DEFAULT (UUID()),
  nombre     VARCHAR(80)  NOT NULL,
  slug       VARCHAR(80)  NOT NULL,
  orden      SMALLINT     NOT NULL DEFAULT 0,
  activa     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY ux_categorias_curso_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cursos (
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

CREATE TABLE IF NOT EXISTS curso_modulos (
  id        CHAR(36)     NOT NULL DEFAULT (UUID()),
  curso_id  CHAR(36)     NOT NULL,
  titulo    VARCHAR(150) NOT NULL,
  orden     SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_modulos_curso (curso_id, orden),
  CONSTRAINT fk_modulos_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS curso_lecciones (
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

-- ---------------------------------------------------------------------
-- PERMISOS
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, modulo) VALUES
 ('cursos.ver','cursos'),
 ('cursos.editar','cursos');

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
WHERE (r.clave = 'super_admin' AND p.clave IN ('cursos.ver','cursos.editar'))
   OR (r.clave = 'abogado'     AND p.clave IN ('cursos.ver','cursos.editar'));

-- ---------------------------------------------------------------------
-- INTERRUPTOR DE MENÚ
--
-- Apagado por defecto: se enciende desde /panel/configuracion cuando haya
-- cursos publicados que mostrar. No apaga las rutas públicas (spec §5),
-- solo el enlace del menú.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO configuraciones
  (clave, valor, tipo, grupo, etiqueta, ayuda, rol_minimo, minimo, maximo, opciones) VALUES
 ('cursos_activo','false','booleano','cursos','Mostrar "Cursos" en el menú',
  'Activa el enlace en la cabecera de la landing. Las páginas /cursos siguen respondiendo aunque esté apagado.',
  'abogado', NULL, NULL, NULL);
```

- [ ] **Step 2: Add the new tables to the test cleanup list**

In `tests/CasoBaseBd.php`, the `limpiar()` method `TRUNCATE`s a fixed list of non-seed tables between tests (seed tables like `configuraciones` are handled separately via `TABLAS_SEMILLA`/`restaurarSemillas()` and don't need to be here). `cursos`, `categorias_curso`, `curso_modulos`, and `curso_lecciones` are not seeded, so without this, rows inserted by one test would leak into the next.

Find:
```php
        foreach ([
            'pagos', 'consultas', 'caso_partes', 'casos', 'consentimientos',
            'conversacion_estado', 'contactos', 'eventos_outbox', 'auditoria',
            'credenciales', 'sesiones', 'usuarios', 'intentos_acceso',
            'configuraciones_historial', 'secuencias', 'eventos_landing',
            'kb_chunks', 'kb_documentos', 'consumo_ia', 'sincronizaciones_modelos',
            'prompts',
        ] as $tabla) {
```

Replace with:
```php
        foreach ([
            'pagos', 'consultas', 'caso_partes', 'casos', 'consentimientos',
            'conversacion_estado', 'contactos', 'eventos_outbox', 'auditoria',
            'credenciales', 'sesiones', 'usuarios', 'intentos_acceso',
            'configuraciones_historial', 'secuencias', 'eventos_landing',
            'kb_chunks', 'kb_documentos', 'consumo_ia', 'sincronizaciones_modelos',
            'prompts', 'curso_lecciones', 'curso_modulos', 'cursos', 'categorias_curso',
        ] as $tabla) {
```

- [ ] **Step 3: Verify the migration applies cleanly**

Run: `vendor/bin/phpunit tests/Integracion/MigracionesTest.php`

Expected: PASS. This test suite recreates the test database from every migration file in order (`tests/CasoBaseBd.php:159-171`), so a syntax error or a broken foreign key in `0028_cursos.sql` fails here first.

- [ ] **Step 4: Commit**

```bash
git add db/migraciones/0028_cursos.sql tests/CasoBaseBd.php
git commit -m "$(cat <<'EOF'
feat(cursos): esquema del catalogo de cursos, permisos e interruptor de menu

Migracion aditiva 0028: categorias_curso, cursos, curso_modulos,
curso_lecciones; permisos cursos.ver/cursos.editar para abogado y
super_admin; fila cursos_activo en configuraciones (apagada por
defecto). Sub-proyecto 1 de 3 (docs/superpowers/specs/2026-08-26-catalogo-cursos-design.md).

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `App\Servicios\Cursos` — public catalog query

**Files:**
- Create: `src/Servicios/Cursos.php`
- Test: `tests/Integracion/CursosTest.php`

**Interfaces:**
- Produces: `final class App\Servicios\Cursos` with constructor `(BD $bd, Config $config, string $urlBase)`; public method `catalogo(?string $categoriaSlug): Respuesta`.
- Consumes: `App\Core\BD::pdo()`, `App\Servicios\Config::get()`, `App\Core\Respuesta::vista()` — all existing.

This task covers only the catalog query/response; the template it renders (`cursos/catalogo`) doesn't exist yet, so the test drives the method directly against the query logic first with a minimal inline check, and Task 4 wires the real template. To keep the task independently testable without a template, `catalogo()` is built now but the test in this task only exercises the private query indirectly by asserting on the `Respuesta` — which requires the template to exist. **Reorder:** write the query methods in this task, but defer the first passing test to Task 4 once the template exists. This task's own passing check is a syntax/type check, not a runtime assertion.

- [ ] **Step 1: Write `Cursos.php` with the catalog query**

```php
<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Core\Respuesta;

/**
 * El catálogo público de cursos: `/cursos` y `/cursos/{slug}`.
 *
 * A diferencia de la landing y `/perfil`, no usa `CachePagina`: esa clase
 * cachea UNA página fija en UN archivo, y aquí hay tantas páginas como
 * cursos —y tantas variantes del catálogo como categorías de filtro—.
 * Cada visita consulta MySQL directamente: son consultas indexadas y
 * baratas, y cachear mal por slug es peor que no cachear.
 */
final class Cursos
{
    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
        private readonly string $urlBase,
    ) {
    }

    public function catalogo(?string $categoriaSlug): Respuesta
    {
        return Respuesta::vista('cursos/catalogo', [
            'cursos' => $this->publicados($categoriaSlug),
            'categorias' => $this->categoriasActivas(),
            'categoriaActual' => $categoriaSlug,
            'meta' => $this->meta(
                'Cursos',
                'Cursos de derecho aduanero y comercio exterior dictados por Pedro.',
                '/cursos',
            ),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function publicados(?string $categoriaSlug): array
    {
        $sql = "SELECT c.id, c.titulo, c.slug, c.resumen, c.nivel, c.precio_cop,
                       c.imagen_portada, cat.nombre AS categoria_nombre, cat.slug AS categoria_slug
                  FROM cursos c
                  JOIN categorias_curso cat ON cat.id = c.categoria_id
                 WHERE c.estado = 'publicado'";
        $parametros = [];

        if ($categoriaSlug !== null && $categoriaSlug !== '') {
            $sql .= ' AND cat.slug = ?';
            $parametros[] = $categoriaSlug;
        }

        $sql .= ' ORDER BY c.orden, c.titulo';

        $stmt = $this->bd->pdo()->prepare($sql);
        $stmt->execute($parametros);

        return $stmt->fetchAll();
    }

    /** @return list<array{id:string,nombre:string,slug:string}> */
    private function categoriasActivas(): array
    {
        return $this->bd->pdo()->query(
            'SELECT id, nombre, slug FROM categorias_curso WHERE activa = 1 ORDER BY orden, nombre'
        )->fetchAll();
    }

    /** @return array{titulo:string,descripcion:string,indexable:bool,url:string} */
    private function meta(string $titulo, string $descripcion, string $ruta): array
    {
        return [
            'titulo' => $titulo . ' · Pedro, abogado aduanero',
            'descripcion' => $descripcion,
            'indexable' => (bool) $this->config->get('landing_indexable', true),
            'url' => rtrim($this->urlBase, '/') . $ruta,
        ];
    }
}
```

- [ ] **Step 2: Confirm it parses**

Run: `php -l src/Servicios/Cursos.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Servicios/Cursos.php
git commit -m "$(cat <<'EOF'
feat(cursos): servicio publico Cursos, consulta del catalogo

Sin CachePagina a proposito (ver nota de desviacion en el plan): sirve
N cursos y N variantes de filtro, no una pagina fija. Falta la ficha
individual y la plantilla; llegan en las tareas siguientes.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `Cursos::ficha()` — course detail query

**Files:**
- Modify: `src/Servicios/Cursos.php`

**Interfaces:**
- Produces: public method `ficha(string $slug): Respuesta` on `App\Servicios\Cursos`.
- Consumes: same as Task 2.

- [ ] **Step 1: Add the ficha method and its private queries**

Add after `catalogo()`:

```php
    public function ficha(string $slug): Respuesta
    {
        $curso = $this->buscarPorSlug($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        return Respuesta::vista('cursos/ficha', [
            'curso' => $curso,
            'modulos' => $this->temario($curso['id']),
            'meta' => $this->meta($curso['titulo'], $curso['resumen'], '/cursos/' . $slug),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function buscarPorSlug(string $slug): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT c.*, cat.nombre AS categoria_nombre
               FROM cursos c JOIN categorias_curso cat ON cat.id = c.categoria_id
              WHERE c.slug = ?'
        );
        $stmt->execute([$slug]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            return null;
        }

        $fila['lo_que_aprendera'] = json_decode((string) $fila['lo_que_aprendera'], true) ?: [];

        return $fila;
    }

    /** @return list<array{titulo:string,lecciones:list<array<string,mixed>>}> */
    private function temario(string $cursoId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, titulo, orden FROM curso_modulos WHERE curso_id = ? ORDER BY orden'
        );
        $stmt->execute([$cursoId]);
        $modulos = $stmt->fetchAll();

        if ($modulos === []) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($modulos), '?'));
        $idsModulos = array_column($modulos, 'id');

        $stmt = $this->bd->pdo()->prepare(
            "SELECT modulo_id, titulo, duracion_min, orden, vista_previa_gratis
               FROM curso_lecciones WHERE modulo_id IN ({$marcas}) ORDER BY orden"
        );
        $stmt->execute($idsModulos);

        $leccionesPorModulo = [];
        foreach ($stmt->fetchAll() as $leccion) {
            $leccionesPorModulo[$leccion['modulo_id']][] = $leccion;
        }

        return array_map(
            static fn (array $modulo): array => [
                'titulo' => $modulo['titulo'],
                'lecciones' => $leccionesPorModulo[$modulo['id']] ?? [],
            ],
            $modulos,
        );
    }
```

- [ ] **Step 2: Confirm it parses**

Run: `php -l src/Servicios/Cursos.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Servicios/Cursos.php
git commit -m "$(cat <<'EOF'
feat(cursos): consulta de la ficha individual y su temario

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Public templates + routes + first passing tests

**Files:**
- Create: `plantillas/cursos/catalogo.php`
- Create: `plantillas/cursos/ficha.php`
- Modify: `src/Core/Aplicacion.php` (register `Cursos::class` in the container; register `GET /cursos` and `GET /cursos/{slug}`)
- Test: `tests/Integracion/CursosTest.php`

**Interfaces:**
- Consumes: `Cursos::catalogo()`/`Cursos::ficha()` from Tasks 2–3; `App\Soporte\Vista::e()`.
- Produces: working `GET /cursos` and `GET /cursos/{slug}` HTTP routes.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integracion/CursosTest.php`:

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\ConfigMysql;
use App\Servicios\Cursos;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CursosTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private Cursos $cursos;
    private ConfigMysql $config;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $this->config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-cursos-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-cursos-cfg-{$sufijo}.json",
        );
        $this->cursos = new Cursos($this->bd, $this->config, self::URL);
    }

    private function categoria(string $nombre = 'Aduanero'): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $slug = strtolower($nombre);

        $this->bd->pdo()->prepare(
            'INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)'
        )->execute([$id, $nombre, $slug]);

        return $id;
    }

    /** @param array<string,mixed> $overrides */
    private function curso(string $categoriaId, array $overrides = []): string
    {
        $datos = array_merge([
            'id' => (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn(),
            'titulo' => 'Clasificación arancelaria desde cero',
            'slug' => 'clasificacion-arancelaria-desde-cero',
            'resumen' => 'Aprenda a clasificar mercancías sin errores.',
            'descripcion' => 'Curso completo sobre el sistema armonizado.',
            'lo_que_aprendera' => json_encode(['Leer el arancel', 'Evitar errores comunes'], JSON_UNESCAPED_UNICODE),
            'nivel' => 'basico',
            'precio_cop' => 250000,
            'estado' => 'borrador',
        ], $overrides);

        $this->bd->pdo()->prepare(
            'INSERT INTO cursos
                (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, nivel, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $datos['id'], $categoriaId, $datos['titulo'], $datos['slug'], $datos['resumen'],
            $datos['descripcion'], $datos['lo_que_aprendera'], $datos['nivel'], $datos['precio_cop'], $datos['estado'],
        ]);

        return (string) $datos['id'];
    }

    #[Test]
    public function elCatalogoSoloListaCursosPublicados(): void
    {
        $cat = $this->categoria();
        $this->curso($cat, ['titulo' => 'Curso borrador', 'slug' => 'curso-borrador', 'estado' => 'borrador']);
        $this->curso($cat, ['titulo' => 'Curso publicado', 'slug' => 'curso-publicado', 'estado' => 'publicado']);

        $html = $this->cursos->catalogo(null)->cuerpo;

        self::assertStringContainsString('Curso publicado', $html);
        self::assertStringNotContainsString('Curso borrador', $html);
    }

    #[Test]
    public function elCatalogoSinCursosMuestraUnEstadoVacioHonesto(): void
    {
        $html = $this->cursos->catalogo(null)->cuerpo;

        self::assertStringContainsString('Todavía no hay cursos publicados', $html);
    }

    #[Test]
    public function elFiltroPorCategoriaSoloMuestraLaSuya(): void
    {
        $aduanero = $this->categoria('Aduanero');
        $otra = $this->categoria('Otra área');
        $this->curso($aduanero, ['titulo' => 'Curso aduanero', 'slug' => 'curso-aduanero', 'estado' => 'publicado']);
        $this->curso($otra, ['titulo' => 'Curso de otra área', 'slug' => 'curso-otra-area', 'estado' => 'publicado']);

        $html = $this->cursos->catalogo('aduanero')->cuerpo;

        self::assertStringContainsString('Curso aduanero', $html);
        self::assertStringNotContainsString('Curso de otra área', $html);
    }

    #[Test]
    public function elPrecioSeMuestraEnPesosNuncaEnCentavos(): void
    {
        $cat = $this->categoria();
        $this->curso($cat, ['slug' => 'con-precio', 'precio_cop' => 250000, 'estado' => 'publicado']);

        $html = $this->cursos->catalogo(null)->cuerpo;

        self::assertStringContainsString('250.000', $html);
        self::assertStringNotContainsString('25000000', $html);
    }

    #[Test]
    public function laFichaDeUnCursoBorradorEsVisibleParaQuienTieneElLinkDirecto(): void
    {
        $cat = $this->categoria();
        $this->curso($cat, ['slug' => 'aun-en-borrador', 'estado' => 'borrador']);

        $respuesta = $this->cursos->ficha('aun-en-borrador');

        self::assertSame(200, $respuesta->estado);
        self::assertStringContainsString('Borrador', $respuesta->cuerpo);
    }

    #[Test]
    public function unSlugInexistenteResponde404(): void
    {
        self::assertSame(404, $this->cursos->ficha('no-existe')->estado);
    }

    #[Test]
    public function laFichaPintaElTemarioCompletoEnOrden(): void
    {
        $cat = $this->categoria();
        $cursoId = $this->curso($cat, ['slug' => 'con-temario', 'estado' => 'publicado']);

        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$moduloId, $cursoId, 'Módulo 1: fundamentos', 1]);

        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, duracion_min, orden)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$leccionId, $moduloId, 'Lección 1: el arancel', 12, 1]);

        $html = $this->cursos->ficha('con-temario')->cuerpo;

        self::assertStringContainsString('Módulo 1: fundamentos', $html);
        self::assertStringContainsString('Lección 1: el arancel', $html);
    }
}
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit tests/Integracion/CursosTest.php`
Expected: FAIL — `RuntimeException: No existe la plantilla «cursos/catalogo».` (the templates don't exist yet)

- [ ] **Step 3: Write `plantillas/cursos/catalogo.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var list<array<string,mixed>> $cursos
 * @var list<array{id:string,nombre:string,slug:string}> $categorias
 * @var ?string $categoriaActual
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string} $meta
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($meta['titulo']) ?></title>
<meta name="description" content="<?= $e($meta['descripcion']) ?>">
<?php if (!$meta['indexable']): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="canonical" href="<?= $e($meta['url']) ?>">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/" class="flex shrink-0 items-center" aria-label="Pedro, abogado aduanero">
            <img src="/img/logo-pedro.png" alt="" width="40" height="40" class="h-10 w-10" decoding="async">
            <span class="sr-only">Pedro</span>
        </a>
        <h1 class="ml-auto text-lg font-semibold">Cursos</h1>
    </div>
</header>

<main class="mx-auto max-w-5xl px-5 py-12 md:px-7">
    <?php if ($categorias !== []): ?>
    <nav aria-label="Categorías" class="mb-8 flex flex-wrap gap-3">
        <a href="/cursos" class="menu-enlace<?= $categoriaActual === null ? ' nav-activo' : '' ?>">Todos</a>
        <?php foreach ($categorias as $cat): ?>
        <a href="/cursos?categoria=<?= $e((string) $cat['slug']) ?>"
           class="menu-enlace<?= $categoriaActual === $cat['slug'] ? ' nav-activo' : '' ?>">
            <?= $e((string) $cat['nombre']) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php if ($cursos === []): ?>
    <p class="text-acero">Todavía no hay cursos publicados.</p>
    <?php else: ?>
    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($cursos as $curso): ?>
        <a href="/cursos/<?= $e((string) $curso['slug']) ?>" class="doble-bisel block p-5">
            <p class="text-xs uppercase tracking-widest text-acero"><?= $e((string) $curso['categoria_nombre']) ?></p>
            <h2 class="mt-2 text-lg font-semibold"><?= $e((string) $curso['titulo']) ?></h2>
            <p class="mt-2 text-sm text-acero"><?= $e((string) $curso['resumen']) ?></p>
            <p class="mt-4 font-mono text-ambar">
                $<?= $e(number_format((int) $curso['precio_cop'], 0, ',', '.')) ?> COP
            </p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>
```

- [ ] **Step 4: Write `plantillas/cursos/ficha.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var list<array{titulo:string,lecciones:list<array<string,mixed>>}> $modulos
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string} $meta
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$precio = '$' . number_format((int) $curso['precio_cop'], 0, ',', '.') . ' COP';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($meta['titulo']) ?></title>
<meta name="description" content="<?= $e($meta['descripcion']) ?>">
<?php if (!$meta['indexable']): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="canonical" href="<?= $e($meta['url']) ?>">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/" class="flex shrink-0 items-center" aria-label="Pedro, abogado aduanero">
            <img src="/img/logo-pedro.png" alt="" width="40" height="40" class="h-10 w-10" decoding="async">
            <span class="sr-only">Pedro</span>
        </a>
        <a href="/cursos" class="ml-auto menu-enlace">Todos los cursos</a>
    </div>
</header>

<main class="mx-auto max-w-3xl px-5 py-12 md:px-7">
    <?php if ($curso['estado'] === 'borrador'): ?>
    <p class="mb-3 inline-block rounded px-2 py-1 text-xs font-semibold text-tinta bg-ambar">
        Borrador — vista previa
    </p>
    <?php endif; ?>

    <p class="text-xs uppercase tracking-widest text-acero"><?= $e((string) $curso['categoria_nombre']) ?></p>
    <h1 class="titular-seccion mt-2"><?= $e((string) $curso['titulo']) ?></h1>
    <p class="mt-4 text-acero"><?= $e((string) $curso['descripcion']) ?></p>

    <?php if ($curso['lo_que_aprendera'] !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Lo que aprenderá</h2>
        <ul class="mt-3 space-y-2">
            <?php foreach ($curso['lo_que_aprendera'] as $item): ?>
            <li class="flex gap-2"><span class="text-ambar">✓</span><span><?= $e((string) $item) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($modulos !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Temario</h2>
        <?php foreach ($modulos as $modulo): ?>
        <div class="doble-bisel mt-3 p-4">
            <h3 class="font-semibold"><?= $e((string) $modulo['titulo']) ?></h3>
            <ul class="mt-2 space-y-1 text-sm text-acero">
                <?php foreach ($modulo['lecciones'] as $leccion): ?>
                <li class="flex justify-between gap-4">
                    <span>
                        <?= $e((string) $leccion['titulo']) ?><?= (int) $leccion['vista_previa_gratis'] === 1 ? ' · vista previa gratis' : '' ?>
                    </span>
                    <?php if ($leccion['duracion_min'] !== null): ?>
                    <span class="font-mono"><?= $e((string) $leccion['duracion_min']) ?> min</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="doble-bisel mt-10 p-6 text-center">
        <p class="font-mono text-2xl text-ambar"><?= $e($precio) ?></p>
        <a href="/cursos/<?= $e((string) $curso['slug']) ?>/comprar" class="boton-diagnostico-global mt-4 inline-block">
            Comprar
        </a>
    </section>
</main>

</body>
</html>
```

- [ ] **Step 5: Run the tests again**

Run: `vendor/bin/phpunit tests/Integracion/CursosTest.php`
Expected: PASS (all 8 tests)

- [ ] **Step 6: Wire the public routes**

In `src/Core/Aplicacion.php`, register the service in the container. Find (around line 178, right after the `Perfil::class` registration block):

```php
        $this->contenedor->registrar(
            \App\Servicios\PaginaLegal::class,
```

Insert immediately before it:

```php
        $this->contenedor->registrar(
            \App\Servicios\Cursos::class,
            static fn (Contenedor $c): \App\Servicios\Cursos => new \App\Servicios\Cursos(
                $c->obtener(BD::class),
                $c->obtener(Config::class),
                $urlBase,
            ),
        );

        $this->contenedor->registrar(
            \App\Servicios\PaginaLegal::class,
```

Then register the routes. Find (in `registrarRutas()`, right after the `/perfil` route):

```php
        $this->router->get('/perfil', function (): Respuesta {
            return $this->contenedor->obtener(\App\Servicios\Perfil::class)->responder();
        });
```

Insert immediately after it:

```php
        $this->router->get('/cursos', function (Peticion $p): Respuesta {
            $categoria = $p->consulta['categoria'] ?? null;

            return $this->contenedor->obtener(\App\Servicios\Cursos::class)->catalogo(
                is_string($categoria) && $categoria !== '' ? $categoria : null,
            );
        });

        $this->router->get('/cursos/{slug}', function (Peticion $p): Respuesta {
            return $this->contenedor->obtener(\App\Servicios\Cursos::class)->ficha($p->parametros['slug']);
        });
```

- [ ] **Step 7: Confirm the app still boots**

Run: `vendor/bin/phpunit tests/Integracion/ArranqueTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add plantillas/cursos/catalogo.php plantillas/cursos/ficha.php src/Core/Aplicacion.php tests/Integracion/CursosTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): plantillas publicas y rutas GET /cursos y /cursos/{slug}

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: "Cursos" link in the landing menu

**Files:**
- Modify: `src/Servicios/Landing.php`
- Modify: `plantillas/landing/pagina.php`
- Modify: `tests/Integracion/LandingTest.php`

**Interfaces:**
- Consumes: `Config::get('cursos_activo', false)` (seeded in Task 1).
- Produces: `Landing::render()` passes a new `cursosActivo: bool` value to the `landing/pagina` template.

- [ ] **Step 1: Write the failing test**

In `tests/Integracion/LandingTest.php`, add (near the other `#[Test]` methods):

```php
    #[Test]
    public function elEnlaceDeCursosSoloApareceConElInterruptorEncendido(): void
    {
        self::assertStringNotContainsString('href="/cursos"', $this->landing->render());

        $this->config->set('cursos_activo', true, 'tester');

        self::assertStringContainsString('href="/cursos"', $this->landing->render());
    }
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit --filter elEnlaceDeCursosSoloApareceConElInterruptorEncendido tests/Integracion/LandingTest.php`
Expected: FAIL — `Undefined array key "cursosActivo"` (the template doesn't receive the variable yet)

- [ ] **Step 3: Pass `cursosActivo` from `Landing::render()`**

In `src/Servicios/Landing.php`, find:

```php
            'topeEventos' => (int) $this->config->get('landing_eventos_por_sesion', 60),
        ])->cuerpo;
```

Replace with:

```php
            'topeEventos' => (int) $this->config->get('landing_eventos_por_sesion', 60),
            // Solo controla el enlace del menú, no las rutas /cursos en sí
            // (spec §5) — igual que cualquier otro valor de `configuraciones`
            // que lee esta plantilla, el cambio tarda hasta
            // `landing_cache_segundos` en reflejarse (comportamiento ya
            // existente, no nuevo de este campo).
            'cursosActivo' => (bool) $this->config->get('cursos_activo', false),
        ])->cuerpo;
```

- [ ] **Step 4: Add the link in the template**

In `plantillas/landing/pagina.php`, find the `@var` block at the top:

```php
 * @var array<string,\App\Modelos\Bloque> $bloques
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string,jsonLd:array<string,mixed>} $meta
 * @var array{numero:string,mensaje:string} $whatsapp
 * @var array{token:string,url:string} $chatwoot
 * @var int $topeEventos
 */
```

Replace with:

```php
 * @var array<string,\App\Modelos\Bloque> $bloques
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string,jsonLd:array<string,mixed>} $meta
 * @var array{numero:string,mensaje:string} $whatsapp
 * @var array{token:string,url:string} $chatwoot
 * @var int $topeEventos
 * @var bool $cursosActivo
 */
```

Then find (the trailing header block with the Diagnóstico button):

```php
        <div class="<?= $menu === [] ? 'ml-auto' : 'ml-auto md:ml-0' ?> flex items-center gap-2 md:gap-4">
            <a href="/perfil" data-evento="perfil_inicio" class="boton-diagnostico-global">
                Diagnóstico <span class="hidden sm:inline">&nbsp;Gratuito</span>
            </a>
```

Replace with:

```php
        <div class="<?= $menu === [] ? 'ml-auto' : 'ml-auto md:ml-0' ?> flex items-center gap-2 md:gap-4">
            <?php /* Segundo enlace de salida, deliberado (spec §5 y §6): ya
                     no hay un solo objetivo de conversión, hay dos —
                     asesoría 1:1 y venta de cursos. */ ?>
            <?php if ($cursosActivo): ?>
            <a href="/cursos" data-evento="cursos_inicio" class="menu-enlace hidden sm:inline">Cursos</a>
            <?php endif; ?>
            <a href="/perfil" data-evento="perfil_inicio" class="boton-diagnostico-global">
                Diagnóstico <span class="hidden sm:inline">&nbsp;Gratuito</span>
            </a>
```

- [ ] **Step 5: Run the test again**

Run: `vendor/bin/phpunit --filter elEnlaceDeCursosSoloApareceConElInterruptorEncendido tests/Integracion/LandingTest.php`
Expected: PASS

- [ ] **Step 6: Run the whole `LandingTest` suite to check for regressions**

Run: `vendor/bin/phpunit tests/Integracion/LandingTest.php`
Expected: PASS (all tests)

- [ ] **Step 7: Commit**

```bash
git add src/Servicios/Landing.php plantillas/landing/pagina.php tests/Integracion/LandingTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): enlace de Cursos en el menu de la landing, tras el interruptor

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: `PanelCursosControlador` — list + create/update course

**Files:**
- Create: `src/Panel/PanelCursosControlador.php`
- Test: `tests/Integracion/PanelCursosTest.php`

**Interfaces:**
- Produces: `final class App\Panel\PanelCursosControlador extends ControladorBase`, constructor `(BD $bd, AuditoriaRepo $auditoria)`; methods `listar(Contexto $ctx): Respuesta`, `editar(Contexto $ctx): Respuesta`, `guardar(Contexto $ctx): Respuesta`; private helpers `rutaEdicion(string $id): string`, `bullets(string $texto): array`, `slugificar(string $texto): string`, `slugUnico(string $base, string $tabla): string`.
- Consumes: `Contexto` (`src/Panel/Contexto.php`), `AuditoriaRepo::registrar()`, permissions `cursos.ver`/`cursos.editar` from Task 1.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integracion/PanelCursosTest.php`:

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Csrf;
use App\Core\Peticion;
use App\Modelos\Usuario;
use App\Panel\Contexto;
use App\Panel\PanelCursosControlador;
use App\Repositorios\AuditoriaRepo;
use App\Servicios\Permisos;
use App\Servicios\SinPermisoException;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class PanelCursosTest extends CasoBaseBd
{
    private Permisos $permisos;
    private AuditoriaRepo $auditoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permisos = new Permisos($this->bd);
        $this->auditoria = new AuditoriaRepo($this->bd);
    }

    private function usuario(string $rol): Usuario
    {
        return new Usuario(
            id: '00000000-0000-0000-0000-00000000000' . (int) (strlen($rol) % 9),
            email: "{$rol}@ejemplo.com",
            nombre: ucfirst($rol) . ' de prueba',
            rol: $rol,
            rolId: 1,
            totpActivo: true,
            activo: true,
            intentosFallidos: 0,
            bloqueadoHasta: null,
        );
    }

    /** @param array<string,mixed> $formulario */
    private function ctx(string $rol, array $formulario = [], array $consulta = []): Contexto
    {
        return new Contexto(
            new Peticion(
                metodo: $formulario === [] ? 'GET' : 'POST',
                ruta: '/panel/cursos',
                consulta: $consulta,
                formulario: $formulario,
                ip: '190.85.1.1',
            ),
            $this->usuario($rol),
            $this->permisos,
            new Csrf(false),
        );
    }

    private function controlador(): PanelCursosControlador
    {
        return new PanelCursosControlador($this->bd, $this->auditoria);
    }

    private function categoriaId(string $nombre = 'Aduanero'): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)'
        )->execute([$id, $nombre, strtolower($nombre)]);

        return $id;
    }

    #[Test]
    public function elAsistenteNoVeCursosEnAbsoluto(): void
    {
        $this->expectException(SinPermisoException::class);
        $this->controlador()->listar($this->ctx('asistente'));
    }

    #[Test]
    public function crearUnCursoLoDejaEnBorrador(): void
    {
        $cat = $this->categoriaId();

        $r = $this->controlador()->guardar($this->ctx('abogado', [
            'id' => '',
            'categoria_id' => $cat,
            'titulo' => 'Clasificación arancelaria desde cero',
            'resumen' => 'Aprenda a clasificar mercancías sin errores.',
            'descripcion' => 'Curso completo sobre el sistema armonizado.',
            'lo_que_aprendera' => "Leer el arancel\nEvitar errores comunes",
            'nivel' => 'basico',
            'precio_cop' => '250000',
            'orden' => '1',
        ]));

        self::assertSame(302, $r->estado);

        $fila = $this->bd->pdo()->query(
            "SELECT * FROM cursos WHERE titulo = 'Clasificación arancelaria desde cero'"
        )->fetch();

        self::assertIsArray($fila);
        self::assertSame('borrador', $fila['estado']);
        self::assertSame('clasificacion-arancelaria-desde-cero', $fila['slug']);
        self::assertSame(
            ['Leer el arancel', 'Evitar errores comunes'],
            json_decode((string) $fila['lo_que_aprendera'], true),
        );
    }

    #[Test]
    public function dosCursosConElMismoTituloRecibenSlugsDistintos(): void
    {
        $cat = $this->categoriaId();

        $datos = [
            'id' => '', 'categoria_id' => $cat, 'titulo' => 'Introducción aduanera',
            'resumen' => 'r', 'descripcion' => 'd', 'lo_que_aprendera' => 'x',
            'nivel' => 'basico', 'precio_cop' => '100000', 'orden' => '0',
        ];

        $this->controlador()->guardar($this->ctx('abogado', $datos));
        $this->controlador()->guardar($this->ctx('abogado', $datos));

        $slugs = $this->bd->pdo()->query(
            "SELECT slug FROM cursos WHERE titulo = 'Introducción aduanera' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(2, array_unique($slugs));
    }

    #[Test]
    public function rechazaCentavosDondeVanPesos(): void
    {
        $cat = $this->categoriaId();

        $r = $this->controlador()->guardar($this->ctx('abogado', [
            'id' => '', 'categoria_id' => $cat, 'titulo' => 'Curso caro por error',
            'resumen' => 'r', 'descripcion' => 'd', 'lo_que_aprendera' => 'x',
            'nivel' => 'basico', 'precio_cop' => '40000000', 'orden' => '0',
        ]));

        self::assertStringContainsString('PESOS', urldecode($r->cabeceras['Location']));
        self::assertSame(
            0,
            (int) $this->bd->pdo()->query("SELECT COUNT(*) FROM cursos WHERE titulo = 'Curso caro por error'")->fetchColumn(),
        );
    }

    #[Test]
    public function laListaSoloLaVeQuienTieneCursosVer(): void
    {
        $html = $this->controlador()->listar($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('Cursos', $html);
    }
}
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: FAIL — `dosCursosConElMismoTituloRecibenSlugsDistintos` and friends fail with "Class App\Panel\PanelCursosControlador not found"

- [ ] **Step 3: Write `PanelCursosControlador.php` (list, edit form, save)**

```php
<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;

/**
 * Catálogo de cursos. Mismo patrón que `TarifasControlador`: permisos
 * explícitos por acción, guarda de precio en pesos (ADR-010), y cada
 * escritura queda en `auditoria`.
 */
final class PanelCursosControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }

    public function listar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.ver');

        $cursos = $this->bd->pdo()->query(
            "SELECT c.id, c.titulo, c.slug, c.precio_cop, c.estado, cat.nombre AS categoria_nombre
               FROM cursos c JOIN categorias_curso cat ON cat.id = c.categoria_id
              ORDER BY c.orden, c.titulo"
        )->fetchAll();

        return $this->vista('panel/cursos', [
            'ctx' => $ctx,
            'cursos' => $cursos,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function editar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.ver');

        $id = $ctx->peticion->consulta['id'] ?? null;
        $curso = null;
        $modulos = [];

        if (is_string($id) && $id !== '') {
            $stmt = $this->bd->pdo()->prepare('SELECT * FROM cursos WHERE id = ?');
            $stmt->execute([$id]);
            $curso = $stmt->fetch() ?: null;

            if ($curso !== null) {
                $curso['lo_que_aprendera'] = implode(
                    "\n",
                    json_decode((string) $curso['lo_que_aprendera'], true) ?: [],
                );
                $modulos = $this->modulosConLecciones($id);
            }
        }

        $categorias = $this->bd->pdo()->query(
            'SELECT id, nombre FROM categorias_curso WHERE activa = 1 ORDER BY orden, nombre'
        )->fetchAll();

        return $this->vista('panel/cursos_editar', [
            'ctx' => $ctx,
            'curso' => $curso,
            'modulos' => $modulos,
            'categorias' => $categorias,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $titulo = $ctx->campo('titulo');
        $categoriaId = $ctx->campo('categoria_id');
        $resumen = $ctx->campo('resumen');
        $descripcion = $ctx->campo('descripcion');
        $precio = $ctx->campo('precio_cop');
        $nivel = $ctx->campo('nivel', 'basico');
        $orden = $ctx->campo('orden', '0');
        $imagen = $ctx->campo('imagen_portada');
        $bullets = $this->bullets($ctx->campo('lo_que_aprendera'));

        if ($titulo === '' || $categoriaId === '' || $resumen === '') {
            return $this->redirigirCon($this->rutaEdicion($id), 'error', 'Título, categoría y resumen son obligatorios.');
        }

        if (preg_match('/^\d+$/', $precio) !== 1) {
            return $this->redirigirCon($this->rutaEdicion($id), 'error', 'El precio debe ser un número entero.');
        }

        $precio = (int) $precio;

        // Mismo guardia que TarifasControlador::guardar(): a $400.000 el
        // curso, un cero de más son cuatro millones. El error es fácil
        // porque la pasarela del sub-proyecto 2 SÍ cobrará en centavos.
        if ($precio >= 10_000_000) {
            return $this->redirigirCon(
                $this->rutaEdicion($id),
                'error',
                'El precio va en PESOS, no en centavos. ¿Seguro que son $'
                    . number_format($precio, 0, ',', '.') . '? '
                    . 'Para $400.000 se escribe 400000.',
            );
        }

        if (!in_array($nivel, ['basico', 'intermedio', 'avanzado'], true)) {
            $nivel = 'basico';
        }

        $stmtCategoria = $this->bd->pdo()->prepare('SELECT id FROM categorias_curso WHERE id = ?');
        $stmtCategoria->execute([$categoriaId]);
        if ($stmtCategoria->fetch() === false) {
            return $this->redirigirCon($this->rutaEdicion($id), 'error', 'Esa categoría no existe.');
        }

        $loQueAprendera = json_encode($bullets, JSON_UNESCAPED_UNICODE) ?: '[]';

        if ($id === '') {
            $slug = $this->slugUnico($this->slugificar($titulo), 'cursos');
            $nuevoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

            $this->bd->pdo()->prepare(
                'INSERT INTO cursos
                    (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera,
                     nivel, precio_cop, imagen_portada, orden)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $nuevoId, $categoriaId, $titulo, $slug, $resumen, $descripcion,
                $loQueAprendera, $nivel, $precio, $imagen !== '' ? $imagen : null, (int) $orden,
            ]);

            $this->auditoria->registrar('curso', $nuevoId, 'crear', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

            return $this->redirigirCon($this->rutaEdicion($nuevoId), 'ok', 'Curso creado.');
        }

        $this->bd->pdo()->prepare(
            'UPDATE cursos
                SET categoria_id = ?, titulo = ?, resumen = ?, descripcion = ?, lo_que_aprendera = ?,
                    nivel = ?, precio_cop = ?, imagen_portada = ?, orden = ?
              WHERE id = ?'
        )->execute([
            $categoriaId, $titulo, $resumen, $descripcion, $loQueAprendera,
            $nivel, $precio, $imagen !== '' ? $imagen : null, (int) $orden, $id,
        ]);

        $this->auditoria->registrar('curso', $id, 'actualizar', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($id), 'ok', 'Curso actualizado.');
    }

    private function rutaEdicion(string $id): string
    {
        return $id === '' ? '/panel/cursos/editar' : '/panel/cursos/editar?id=' . urlencode($id);
    }

    /** @return list<string> */
    private function bullets(string $texto): array
    {
        $lineas = preg_split('/\r\n|\r|\n/', $texto) ?: [];
        $lineas = array_map('trim', $lineas);

        return array_values(array_filter($lineas, static fn (string $l): bool => $l !== ''));
    }

    private function slugificar(string $texto): string
    {
        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
        $slug = strtolower($transliterado !== false ? $transliterado : $texto);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'curso';
    }

    private function slugUnico(string $base, string $tabla): string
    {
        $slug = $base;
        $sufijo = 2;
        $stmt = $this->bd->pdo()->prepare("SELECT COUNT(*) FROM {$tabla} WHERE slug = ?");

        while (true) {
            $stmt->execute([$slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $sufijo;
            $sufijo++;
        }
    }

    /** @return list<array{id:string,titulo:string,orden:int,lecciones:list<array<string,mixed>>}> */
    private function modulosConLecciones(string $cursoId): array
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

Note: `listar()` renders `panel/cursos`, which doesn't exist until Task 10 — the two "list" tests in this task's file (`elAsistenteNoVeCursosEnAbsoluto`, `laListaSoloLaVeQuienTieneCursosVer`) will still fail after this step for a *different* reason (missing template) than `SinPermisoException`. Expected in Step 4 below; Task 10 makes the template exist and Step 5 in this task re-runs everything.

- [ ] **Step 4: Run the tests again**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: `elAsistenteNoVeCursosEnAbsoluto` PASSES (permission check happens before the template renders). `crearUnCursoLoDejaEnBorrador`, `dosCursosConElMismoTituloReciben Slugs Distintos`, `rechazaCentavosDondeVanPesos` PASS (they call `guardar()`, which redirects and never renders a template). `laListaSoloLaVeQuienTieneCursosVer` FAILS — `RuntimeException: No existe la plantilla «panel/cursos».` This is expected; Task 10 resolves it.

- [ ] **Step 5: Commit**

```bash
git add src/Panel/PanelCursosControlador.php tests/Integracion/PanelCursosTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): PanelCursosControlador - listar, formulario y guardar

listar()/editar() renderizan plantillas que llegan en una tarea
posterior (panel/cursos, panel/cursos_editar); sus pruebas quedan
marcadas como pendientes hasta esa tarea.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Publish / unpublish a course

**Files:**
- Modify: `src/Panel/PanelCursosControlador.php`
- Modify: `tests/Integracion/PanelCursosTest.php`

**Interfaces:**
- Produces: `publicar(Contexto $ctx): Respuesta`, `despublicar(Contexto $ctx): Respuesta` on `PanelCursosControlador`.
- Consumes: `guardar()`'s `id`/`rutaEdicion()` helpers from Task 6.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integracion/PanelCursosTest.php`:

```php
    private function crearCursoCompleto(string $categoriaId, int $precio = 250000): string
    {
        $this->controlador()->guardar($this->ctx('abogado', [
            'id' => '', 'categoria_id' => $categoriaId, 'titulo' => 'Curso completo ' . bin2hex(random_bytes(3)),
            'resumen' => 'r', 'descripcion' => 'd', 'lo_que_aprendera' => 'x',
            'nivel' => 'basico', 'precio_cop' => (string) $precio, 'orden' => '0',
        ]));

        return (string) $this->bd->pdo()->query('SELECT id FROM cursos ORDER BY creado_en DESC LIMIT 1')->fetchColumn();
    }

    #[Test]
    public function publicarSinModulosFalla(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());

        $r = $this->controlador()->publicar($this->ctx('abogado', ['id' => $cursoId]));

        self::assertStringContainsString('modulo', strtolower(urldecode($r->cabeceras['Location'])));
        self::assertSame(
            'borrador',
            $this->bd->pdo()->query("SELECT estado FROM cursos WHERE id = '{$cursoId}'")->fetchColumn(),
        );
    }

    #[Test]
    public function publicarConTemarioFunciona(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());

        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$moduloId, $cursoId, 'Módulo único', 1]);

        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$leccionId, $moduloId, 'Lección única', 1]);

        $r = $this->controlador()->publicar($this->ctx('abogado', ['id' => $cursoId]));

        self::assertSame(302, $r->estado);
        self::assertSame(
            'publicado',
            $this->bd->pdo()->query("SELECT estado FROM cursos WHERE id = '{$cursoId}'")->fetchColumn(),
        );
    }

    #[Test]
    public function despublicarVuelveABorrador(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());
        $this->bd->pdo()->exec("UPDATE cursos SET estado = 'publicado' WHERE id = '{$cursoId}'");

        $this->controlador()->despublicar($this->ctx('abogado', ['id' => $cursoId]));

        self::assertSame(
            'borrador',
            $this->bd->pdo()->query("SELECT estado FROM cursos WHERE id = '{$cursoId}'")->fetchColumn(),
        );
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit --filter 'publicarSinModulosFalla|publicarConTemarioFunciona|despublicarVuelveABorrador' tests/Integracion/PanelCursosTest.php`
Expected: FAIL — `Call to undefined method App\Panel\PanelCursosControlador::publicar()`

- [ ] **Step 3: Add `publicar()`/`despublicar()`**

In `src/Panel/PanelCursosControlador.php`, add after `guardar()`:

```php
    public function publicar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM cursos WHERE id = ?');
        $stmt->execute([$id]);
        $curso = $stmt->fetch();

        if ($curso === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Ese curso no existe.');
        }

        if ((int) $curso['precio_cop'] <= 0) {
            return $this->redirigirCon($this->rutaEdicion($id), 'error', 'El curso necesita un precio mayor que cero para publicarse.');
        }

        $tieneLeccion = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cm.curso_id = ?'
        );
        $tieneLeccion->execute([$id]);

        if ((int) $tieneLeccion->fetchColumn() === 0) {
            return $this->redirigirCon(
                $this->rutaEdicion($id),
                'error',
                'El curso necesita al menos un módulo con una lección para publicarse.',
            );
        }

        $this->bd->pdo()->prepare(
            "UPDATE cursos SET estado = 'publicado', publicado_en = NOW() WHERE id = ?"
        )->execute([$id]);

        $this->auditoria->registrar('curso', $id, 'publicar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($id), 'ok', 'Curso publicado.');
    }

    public function despublicar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');

        $this->bd->pdo()->prepare("UPDATE cursos SET estado = 'borrador' WHERE id = ?")->execute([$id]);

        $this->auditoria->registrar('curso', $id, 'despublicar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($id), 'ok', 'Curso pasado a borrador.');
    }
```

- [ ] **Step 4: Run the tests again**

Run: `vendor/bin/phpunit --filter 'publicarSinModulosFalla|publicarConTemarioFunciona|despublicarVuelveABorrador' tests/Integracion/PanelCursosTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Panel/PanelCursosControlador.php tests/Integracion/PanelCursosTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): publicar/despublicar con validacion de precio y temario

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Categories CRUD

**Files:**
- Modify: `src/Panel/PanelCursosControlador.php`
- Modify: `tests/Integracion/PanelCursosTest.php`

**Interfaces:**
- Produces: `categorias(Contexto $ctx): Respuesta`, `guardarCategoria(Contexto $ctx): Respuesta`.
- Consumes: `slugificar()`/`slugUnico()` from Task 6.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integracion/PanelCursosTest.php`:

```php
    #[Test]
    public function crearUnaCategoriaLeAsignaUnSlug(): void
    {
        $r = $this->controlador()->guardarCategoria($this->ctx('abogado', [
            'id' => '', 'nombre' => 'Comercio Exterior', 'orden' => '0', 'activa' => '1',
        ]));

        self::assertSame(302, $r->estado);

        $fila = $this->bd->pdo()->query(
            "SELECT slug FROM categorias_curso WHERE nombre = 'Comercio Exterior'"
        )->fetch();

        self::assertSame('comercio-exterior', $fila['slug']);
    }

    #[Test]
    public function editarUnaCategoriaExistenteNoLeCambiaElSlug(): void
    {
        $id = $this->categoriaId('Aduanero');

        $this->controlador()->guardarCategoria($this->ctx('abogado', [
            'id' => $id, 'nombre' => 'Aduanero avanzado', 'orden' => '2', 'activa' => '1',
        ]));

        $fila = $this->bd->pdo()->query("SELECT nombre, slug FROM categorias_curso WHERE id = '{$id}'")->fetch();

        self::assertSame('Aduanero avanzado', $fila['nombre']);
        self::assertSame('aduanero', $fila['slug']);
    }

    #[Test]
    public function unaCategoriaSinNombreNoSeGuarda(): void
    {
        $r = $this->controlador()->guardarCategoria($this->ctx('abogado', [
            'id' => '', 'nombre' => '', 'orden' => '0', 'activa' => '1',
        ]));

        self::assertStringContainsString('obligatorio', urldecode($r->cabeceras['Location']));
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit --filter 'crearUnaCategoriaLeAsignaUnSlug|editarUnaCategoriaExistenteNoLeCambiaElSlug|unaCategoriaSinNombreNoSeGuarda' tests/Integracion/PanelCursosTest.php`
Expected: FAIL — `Call to undefined method App\Panel\PanelCursosControlador::guardarCategoria()`

- [ ] **Step 3: Add `categorias()`/`guardarCategoria()`**

Add after `despublicar()`:

```php
    public function categorias(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.ver');

        $categorias = $this->bd->pdo()->query(
            'SELECT * FROM categorias_curso ORDER BY orden, nombre'
        )->fetchAll();

        return $this->vista('panel/cursos_categorias', [
            'ctx' => $ctx,
            'categorias' => $categorias,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardarCategoria(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $nombre = $ctx->campo('nombre');
        $orden = $ctx->campo('orden', '0');
        $activa = (int) ($ctx->campo('activa') === '1');

        if ($nombre === '') {
            return $this->redirigirCon('/panel/cursos/categorias', 'error', 'El nombre es obligatorio.');
        }

        if ($id === '') {
            $slug = $this->slugUnico($this->slugificar($nombre), 'categorias_curso');
            $nuevoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

            $this->bd->pdo()->prepare(
                'INSERT INTO categorias_curso (id, nombre, slug, orden, activa) VALUES (?, ?, ?, ?, ?)'
            )->execute([$nuevoId, $nombre, $slug, (int) $orden, $activa]);

            $this->auditoria->registrar('categoria_curso', $nuevoId, 'crear', $ctx->actor(), ['nombre' => $nombre], $ctx->ip());
        } else {
            $this->bd->pdo()->prepare(
                'UPDATE categorias_curso SET nombre = ?, orden = ?, activa = ? WHERE id = ?'
            )->execute([$nombre, (int) $orden, $activa, $id]);

            $this->auditoria->registrar('categoria_curso', $id, 'actualizar', $ctx->actor(), ['nombre' => $nombre], $ctx->ip());
        }

        return $this->redirigirCon('/panel/cursos/categorias', 'ok', 'Categoría guardada.');
    }
```

Note: `categorias()`'s own test coverage (rendering) waits on the template from Task 10, same as `listar()` in Task 6.

- [ ] **Step 4: Run the tests again**

Run: `vendor/bin/phpunit --filter 'crearUnaCategoriaLeAsignaUnSlug|editarUnaCategoriaExistenteNoLeCambiaElSlug|unaCategoriaSinNombreNoSeGuarda' tests/Integracion/PanelCursosTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Panel/PanelCursosControlador.php tests/Integracion/PanelCursosTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CRUD de categorias (crear/editar, slug estable al editar)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Curriculum (modules and lessons)

**Files:**
- Modify: `src/Panel/PanelCursosControlador.php`
- Modify: `tests/Integracion/PanelCursosTest.php`

**Interfaces:**
- Produces: `agregarModulo`, `eliminarModulo`, `agregarLeccion`, `eliminarLeccion` (all `Contexto $ctx: Respuesta`) on `PanelCursosControlador`.
- Consumes: `crearCursoCompleto()` test helper from Task 7; `rutaEdicion()` from Task 6.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integracion/PanelCursosTest.php`:

```php
    #[Test]
    public function agregarUnModuloLoNumeraEnOrden(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());

        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo 1']));
        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo 2']));

        $ordenes = $this->bd->pdo()->query(
            "SELECT orden FROM curso_modulos WHERE curso_id = '{$cursoId}' ORDER BY orden"
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame([1, 2], array_map('intval', $ordenes));
    }

    #[Test]
    public function eliminarUnModuloBorraSusLecciones(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());
        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo']));
        $moduloId = (string) $this->bd->pdo()->query('SELECT id FROM curso_modulos LIMIT 1')->fetchColumn();

        $this->controlador()->agregarLeccion($this->ctx('abogado', [
            'modulo_id' => $moduloId, 'titulo' => 'Lección', 'duracion_min' => '10',
        ]));

        self::assertSame(1, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_lecciones')->fetchColumn());

        $this->controlador()->eliminarModulo($this->ctx('abogado', ['id' => $moduloId]));

        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_modulos')->fetchColumn());
        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_lecciones')->fetchColumn());
    }

    #[Test]
    public function agregarUnaLeccionSinTituloFalla(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());
        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo']));
        $moduloId = (string) $this->bd->pdo()->query('SELECT id FROM curso_modulos LIMIT 1')->fetchColumn();

        $r = $this->controlador()->agregarLeccion($this->ctx('abogado', ['modulo_id' => $moduloId, 'titulo' => '']));

        self::assertStringContainsString('titulo', strtolower(urldecode($r->cabeceras['Location'])));
        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_lecciones')->fetchColumn());
    }

    #[Test]
    public function eliminarUnaLeccion(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());
        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo']));
        $moduloId = (string) $this->bd->pdo()->query('SELECT id FROM curso_modulos LIMIT 1')->fetchColumn();
        $this->controlador()->agregarLeccion($this->ctx('abogado', ['modulo_id' => $moduloId, 'titulo' => 'Lección']));
        $leccionId = (string) $this->bd->pdo()->query('SELECT id FROM curso_lecciones LIMIT 1')->fetchColumn();

        $this->controlador()->eliminarLeccion($this->ctx('abogado', ['id' => $leccionId]));

        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_lecciones')->fetchColumn());
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `vendor/bin/phpunit --filter 'agregarUnModuloLoNumeraEnOrden|eliminarUnModuloBorraSusLecciones|agregarUnaLeccionSinTituloFalla|eliminarUnaLeccion' tests/Integracion/PanelCursosTest.php`
Expected: FAIL — undefined methods

- [ ] **Step 3: Add the curriculum methods**

Add after `guardarCategoria()`:

```php
    public function agregarModulo(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $cursoId = $ctx->campo('curso_id');
        $titulo = $ctx->campo('titulo');

        if ($cursoId === '' || $titulo === '') {
            return $this->redirigirCon($this->rutaEdicion($cursoId), 'error', 'El módulo necesita un título.');
        }

        $siguienteOrden = $this->bd->pdo()->prepare(
            'SELECT COALESCE(MAX(orden), 0) + 1 FROM curso_modulos WHERE curso_id = ?'
        );
        $siguienteOrden->execute([$cursoId]);
        $orden = (int) $siguienteOrden->fetchColumn();

        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$id, $cursoId, $titulo, $orden]);

        $this->auditoria->registrar('curso_modulo', $id, 'crear', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($cursoId), 'ok', 'Módulo agregado.');
    }

    public function eliminarModulo(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare('SELECT curso_id FROM curso_modulos WHERE id = ?');
        $stmt->execute([$id]);
        $cursoId = (string) $stmt->fetchColumn();

        // ON DELETE CASCADE en curso_lecciones se encarga de sus lecciones.
        $this->bd->pdo()->prepare('DELETE FROM curso_modulos WHERE id = ?')->execute([$id]);

        $this->auditoria->registrar('curso_modulo', $id, 'eliminar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion($cursoId), 'ok', 'Módulo eliminado.');
    }

    public function agregarLeccion(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $moduloId = $ctx->campo('modulo_id');
        $titulo = $ctx->campo('titulo');
        $duracion = $ctx->campo('duracion_min');
        $vistaPrevia = (int) ($ctx->campo('vista_previa_gratis') === '1');

        $stmt = $this->bd->pdo()->prepare('SELECT curso_id FROM curso_modulos WHERE id = ?');
        $stmt->execute([$moduloId]);
        $cursoId = $stmt->fetchColumn();

        if ($cursoId === false) {
            return $this->redirigirCon('/panel/cursos', 'error', 'Ese módulo no existe.');
        }

        if ($titulo === '') {
            return $this->redirigirCon($this->rutaEdicion((string) $cursoId), 'error', 'La lección necesita un título.');
        }

        $duracionMin = preg_match('/^\d+$/', $duracion) === 1 ? (int) $duracion : null;

        $siguienteOrden = $this->bd->pdo()->prepare(
            'SELECT COALESCE(MAX(orden), 0) + 1 FROM curso_lecciones WHERE modulo_id = ?'
        );
        $siguienteOrden->execute([$moduloId]);
        $orden = (int) $siguienteOrden->fetchColumn();

        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, duracion_min, orden, vista_previa_gratis)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $moduloId, $titulo, $duracionMin, $orden, $vistaPrevia]);

        $this->auditoria->registrar('curso_leccion', $id, 'crear', $ctx->actor(), ['titulo' => $titulo], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion((string) $cursoId), 'ok', 'Lección agregada.');
    }

    public function eliminarLeccion(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $stmt = $this->bd->pdo()->prepare(
            'SELECT cm.curso_id FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cl.id = ?'
        );
        $stmt->execute([$id]);
        $cursoId = $stmt->fetchColumn();

        $this->bd->pdo()->prepare('DELETE FROM curso_lecciones WHERE id = ?')->execute([$id]);

        $this->auditoria->registrar('curso_leccion', $id, 'eliminar', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon($this->rutaEdicion((string) $cursoId), 'ok', 'Lección eliminada.');
    }
```

- [ ] **Step 4: Run the tests again**

Run: `vendor/bin/phpunit --filter 'agregarUnModuloLoNumeraEnOrden|eliminarUnModuloBorraSusLecciones|agregarUnaLeccionSinTituloFalla|eliminarUnaLeccion' tests/Integracion/PanelCursosTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Panel/PanelCursosControlador.php tests/Integracion/PanelCursosTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): temario - agregar/eliminar modulos y lecciones

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Panel templates, routing, and menu entries

**Files:**
- Create: `plantillas/panel/cursos.php`
- Create: `plantillas/panel/cursos_editar.php`
- Create: `plantillas/panel/cursos_categorias.php`
- Modify: `src/Panel/Panel.php`
- Modify: `plantillas/panel/_disposicion.php`
- Modify: `src/Panel/ConfiguracionControlador.php`

**Interfaces:**
- Consumes: `PanelCursosControlador` (Tasks 6–9), `Vista::e()`.
- Produces: working `/panel/cursos*` routes and menu entries; every previously-deferred test in `PanelCursosTest` (from Tasks 6 and 8) now passes end to end.

- [ ] **Step 1: Write `plantillas/panel/cursos.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $cursos
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Cursos';

$contenido = static function () use ($e, $ctx, $cursos): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <div class="flex items-center justify-between">
        <h2 class="rotulo">Cursos</h2>
        <div class="flex gap-3">
            <a href="/panel/cursos/categorias" class="boton-secundario">Categorías</a>
            <?php if ($editable): ?>
            <a href="/panel/cursos/editar" class="boton">Nuevo curso</a>
            <?php endif; ?>
        </div>
    </div>

    <table class="tabla mt-4">
        <thead>
            <tr><th>Título</th><th>Categoría</th><th>Precio</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($cursos as $c): ?>
            <tr>
                <td><?= $e((string) $c['titulo']) ?></td>
                <td><?= $e((string) $c['categoria_nombre']) ?></td>
                <td class="font-mono">$<?= $e(number_format((int) $c['precio_cop'], 0, ',', '.')) ?></td>
                <td><?= $c['estado'] === 'publicado' ? 'Publicado' : 'Borrador' ?></td>
                <td>
                    <a href="/panel/cursos/editar?id=<?= $e((string) $c['id']) ?>" class="underline">Editar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($cursos === []): ?>
            <tr><td colspan="5" class="text-acero">Todavía no hay cursos.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php };

require __DIR__ . '/_disposicion.php';
```

- [ ] **Step 2: Write `plantillas/panel/cursos_editar.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var array<string,mixed>|null $curso
 * @var list<array{id:string,titulo:string,orden:int,lecciones:list<array<string,mixed>>}> $modulos
 * @var list<array{id:string,nombre:string}> $categorias
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = $curso === null ? 'Nuevo curso' : 'Editar curso';
$esNuevo = $curso === null;

$contenido = static function () use ($e, $ctx, $curso, $modulos, $categorias, $esNuevo): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo"><?= $esNuevo ? 'Nuevo curso' : 'Editar curso' ?></h2>

    <form method="post" action="/panel/cursos/guardar" class="tarjeta mt-4 p-4">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="id" value="<?= $e((string) ($curso['id'] ?? '')) ?>">

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="rotulo">Título</label>
                <input name="titulo" value="<?= $e((string) ($curso['titulo'] ?? '')) ?>"
                       class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div>
                <label class="rotulo">Categoría</label>
                <select name="categoria_id" class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= $e((string) $cat['id']) ?>"
                        <?= ($curso['categoria_id'] ?? '') === $cat['id'] ? 'selected' : '' ?>>
                        <?= $e((string) $cat['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="rotulo">Nivel</label>
                <select name="nivel" class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
                    <?php foreach (['basico', 'intermedio', 'avanzado'] as $opcion): ?>
                    <option value="<?= $e($opcion) ?>" <?= ($curso['nivel'] ?? 'basico') === $opcion ? 'selected' : '' ?>>
                        <?= $e(ucfirst($opcion)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Resumen (una línea, para la tarjeta del catálogo)</label>
                <input name="resumen" value="<?= $e((string) ($curso['resumen'] ?? '')) ?>"
                       class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Descripción</label>
                <textarea name="descripcion" rows="4" class="campo mt-1"
                          <?= $editable ? '' : 'disabled' ?>><?= $e((string) ($curso['descripcion'] ?? '')) ?></textarea>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Lo que aprenderá (una línea por punto)</label>
                <textarea name="lo_que_aprendera" rows="4" class="campo mt-1"
                          <?= $editable ? '' : 'disabled' ?>><?= $e((string) ($curso['lo_que_aprendera'] ?? '')) ?></textarea>
            </div>

            <div>
                <label class="rotulo">Precio (pesos)</label>
                <input name="precio_cop" type="number" min="0" step="1000"
                       value="<?= $e((string) ($curso['precio_cop'] ?? '')) ?>"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div>
                <label class="rotulo">Orden</label>
                <input name="orden" type="number" value="<?= $e((string) ($curso['orden'] ?? '0')) ?>"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Imagen de portada (nombre de archivo en public/img/cursos/)</label>
                <input name="imagen_portada" value="<?= $e((string) ($curso['imagen_portada'] ?? '')) ?>"
                       class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
            </div>
        </div>

        <?php if ($editable): ?>
        <button type="submit" class="boton mt-4">Guardar curso</button>
        <?php endif; ?>
    </form>

    <?php if (!$esNuevo && $editable): ?>
    <form method="post" action="/panel/cursos/<?= $curso['estado'] === 'publicado' ? 'despublicar' : 'publicar' ?>" class="mt-3">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="id" value="<?= $e((string) $curso['id']) ?>">
        <button type="submit" class="boton-secundario">
            <?= $curso['estado'] === 'publicado' ? 'Pasar a borrador' : 'Publicar' ?>
        </button>
    </form>
    <?php endif; ?>

    <?php if (!$esNuevo): ?>
    <section class="mt-8">
        <h2 class="rotulo">Temario</h2>

        <?php foreach ($modulos as $modulo): ?>
        <div class="tarjeta mt-3 p-4">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold"><?= $e((string) $modulo['titulo']) ?></h3>
                <?php if ($editable): ?>
                <form method="post" action="/panel/cursos/modulos/eliminar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $modulo['id']) ?>">
                    <button type="submit" class="text-sm underline">Eliminar módulo</button>
                </form>
                <?php endif; ?>
            </div>

            <ul class="mt-2 space-y-1 text-sm text-acero">
                <?php foreach ($modulo['lecciones'] as $leccion): ?>
                <li class="flex items-center justify-between gap-4">
                    <span>
                        <?= $e((string) $leccion['titulo']) ?>
                        <?= $leccion['duracion_min'] !== null ? ' · ' . $e((string) $leccion['duracion_min']) . ' min' : '' ?>
                        <?= (int) $leccion['vista_previa_gratis'] === 1 ? ' · vista previa gratis' : '' ?>
                    </span>
                    <?php if ($editable): ?>
                    <form method="post" action="/panel/cursos/lecciones/eliminar">
                        <?= $ctx->csrf->campoOculto() ?>
                        <input type="hidden" name="id" value="<?= $e((string) $leccion['id']) ?>">
                        <button type="submit" class="underline">Eliminar</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($editable): ?>
            <form method="post" action="/panel/cursos/lecciones/agregar" class="mt-3 flex flex-wrap items-end gap-2">
                <?= $ctx->csrf->campoOculto() ?>
                <input type="hidden" name="modulo_id" value="<?= $e((string) $modulo['id']) ?>">
                <div>
                    <label class="rotulo">Nueva lección</label>
                    <input name="titulo" class="campo mt-1" required>
                </div>
                <div>
                    <label class="rotulo">Minutos</label>
                    <input name="duracion_min" type="number" min="0" class="campo mt-1 font-mono">
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="vista_previa_gratis" value="1"> Vista previa gratis
                </label>
                <button type="submit" class="boton-secundario">Agregar lección</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($editable): ?>
        <form method="post" action="/panel/cursos/modulos/agregar" class="tarjeta mt-3 flex flex-wrap items-end gap-2 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <input type="hidden" name="curso_id" value="<?= $e((string) $curso['id']) ?>">
            <div>
                <label class="rotulo">Nuevo módulo</label>
                <input name="titulo" class="campo mt-1" required>
            </div>
            <button type="submit" class="boton-secundario">Agregar módulo</button>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>
<?php };

require __DIR__ . '/_disposicion.php';
```

- [ ] **Step 3: Write `plantillas/panel/cursos_categorias.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $categorias
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Categorías de cursos';

$contenido = static function () use ($e, $ctx, $categorias): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo">Categorías</h2>

    <table class="tabla mt-4">
        <thead><tr><th>Nombre</th><th>Slug</th><th>Orden</th><th>Activa</th></tr></thead>
        <tbody>
        <?php foreach ($categorias as $cat): ?>
        <tr>
            <td>
                <form method="post" action="/panel/cursos/categorias/guardar" class="flex flex-wrap items-center gap-2">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $cat['id']) ?>">
                    <input name="nombre" value="<?= $e((string) $cat['nombre']) ?>"
                           class="campo" <?= $editable ? '' : 'disabled' ?>>
            </td>
            <td class="font-mono"><?= $e((string) $cat['slug']) ?></td>
            <td>
                    <input name="orden" type="number" value="<?= $e((string) $cat['orden']) ?>"
                           class="campo w-20 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </td>
            <td>
                    <input type="checkbox" name="activa" value="1"
                           <?= (int) $cat['activa'] === 1 ? 'checked' : '' ?>
                           <?= $editable ? '' : 'disabled' ?>>
                    <?php if ($editable): ?>
                    <button type="submit" class="boton-secundario ml-2">Guardar</button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($editable): ?>
    <form method="post" action="/panel/cursos/categorias/guardar" class="tarjeta mt-6 flex flex-wrap items-end gap-2 p-4">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="activa" value="1">
        <div>
            <label class="rotulo">Nueva categoría</label>
            <input name="nombre" class="campo mt-1" required>
        </div>
        <div>
            <label class="rotulo">Orden</label>
            <input name="orden" type="number" value="0" class="campo mt-1 font-mono w-20">
        </div>
        <button type="submit" class="boton">Crear categoría</button>
    </form>
    <?php endif; ?>
<?php };

require __DIR__ . '/_disposicion.php';
```

- [ ] **Step 4: Register the routes and the controller in `Panel.php`**

In `src/Panel/Panel.php`, find:

```php
            'GET /whatsapp' => $modulos['whatsapp']->ver($ctx),
```

Insert immediately before it:

```php
            'GET /cursos' => $modulos['cursos']->listar($ctx),
            'GET /cursos/editar' => $modulos['cursos']->editar($ctx),
            'POST /cursos/guardar' => $modulos['cursos']->guardar($ctx),
            'POST /cursos/publicar' => $modulos['cursos']->publicar($ctx),
            'POST /cursos/despublicar' => $modulos['cursos']->despublicar($ctx),
            'POST /cursos/modulos/agregar' => $modulos['cursos']->agregarModulo($ctx),
            'POST /cursos/modulos/eliminar' => $modulos['cursos']->eliminarModulo($ctx),
            'POST /cursos/lecciones/agregar' => $modulos['cursos']->agregarLeccion($ctx),
            'POST /cursos/lecciones/eliminar' => $modulos['cursos']->eliminarLeccion($ctx),
            'GET /cursos/categorias' => $modulos['cursos']->categorias($ctx),
            'POST /cursos/categorias/guardar' => $modulos['cursos']->guardarCategoria($ctx),

            'GET /whatsapp' => $modulos['whatsapp']->ver($ctx),
```

Then find, in the `modulos()` method:

```php
            'tarifas' => new TarifasControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(AuditoriaRepo::class),
            ),
```

Insert immediately after it:

```php
            'cursos' => new PanelCursosControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(AuditoriaRepo::class),
            ),
```

No `use` import is needed: `Panel.php` is itself declared `namespace App\Panel;`, and `PanelCursosControlador` lives in that same namespace (like `TarifasControlador`, which `Panel.php` already references unqualified with no import for it). Referencing `PanelCursosControlador` unqualified in the `modulos()` method above resolves correctly as-is.

- [ ] **Step 5: Add menu entries in `_disposicion.php`**

In `plantillas/panel/_disposicion.php`, find:

```php
$menu = [
    ['/panel', 'Tablero', 'tablero.ver'],
    ['/panel/contenido', 'Contenido', 'contenido.editar'],
    ['/panel/tarifas', 'Tarifas', 'agenda.ver'],
```

Replace with:

```php
$menu = [
    ['/panel', 'Tablero', 'tablero.ver'],
    ['/panel/contenido', 'Contenido', 'contenido.editar'],
    ['/panel/tarifas', 'Tarifas', 'agenda.ver'],
    ['/panel/cursos', 'Cursos', 'cursos.ver'],
```

- [ ] **Step 6: Add the "Cursos" group to the general configuration screen**

In `src/Panel/ConfiguracionControlador.php`, find:

```php
    private const GRUPOS = [
        'motor' => 'Motor conversacional',
        'agenda' => 'Agenda',
        'pagos' => 'Pagos',
        'ia' => 'Inteligencia artificial',
        'legal' => 'Legal',
        'notificaciones' => 'Notificaciones',
        'landing' => 'Landing',
    ];
```

Replace with:

```php
    private const GRUPOS = [
        'motor' => 'Motor conversacional',
        'agenda' => 'Agenda',
        'pagos' => 'Pagos',
        'ia' => 'Inteligencia artificial',
        'legal' => 'Legal',
        'notificaciones' => 'Notificaciones',
        'landing' => 'Landing',
        'cursos' => 'Cursos',
    ];
```

This is what makes the `cursos_activo` row seeded in Task 1 actually show up on `/panel/configuracion`, since that screen only renders groups listed here.

- [ ] **Step 7: Run the full course-related test suite**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php tests/Integracion/CursosTest.php tests/Integracion/LandingTest.php`
Expected: PASS — including `elAsistenteNoVeCursosEnAbsoluto` and `laListaSoloLaVeQuienTieneCursosVer` from Task 6, and the three category tests from Task 8, which were deferred until this task's templates existed.

- [ ] **Step 8: Commit**

```bash
git add plantillas/panel/cursos.php plantillas/panel/cursos_editar.php plantillas/panel/cursos_categorias.php \
        src/Panel/Panel.php plantillas/panel/_disposicion.php src/Panel/ConfiguracionControlador.php
git commit -m "$(cat <<'EOF'
feat(cursos): plantillas del panel, rutas y entradas de menu

Cierra el sub-proyecto 1 (catalogo publico de cursos). Cobro (Wompi) y
cuentas de acceso quedan para los sub-proyectos 2 y 3, cada uno con su
propio spec.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Full suite and manual verification

**Files:** none (verification only)

- [ ] **Step 1: Run the entire PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: all tests green, including the pre-existing 200+ tests (no regressions).

- [ ] **Step 2: Start the dev server**

Run: `php -S 127.0.0.1:8000 bin/servidor-dev.php`

- [ ] **Step 3: Manually verify the public catalog with no courses**

Visit `http://127.0.0.1:8000/cursos` in a browser. Expected: "Todavía no hay cursos publicados." with no error.

- [ ] **Step 4: Manually verify the panel flow end to end**

Log into `/panel`, go to Cursos → Categorías, create a category, go back to Cursos → Nuevo curso, fill it in, save, add a module and a lesson, publish, then visit `/cursos` and `/cursos/{slug}` in the browser to confirm the course and its curriculum render with the price in pesos (not cents).

- [ ] **Step 5: Toggle the menu switch**

In `/panel/configuracion`, find the "Cursos" group, turn on "Mostrar Cursos en el menú", wait for `landing_cache_segundos` (or restart the dev server, which has no warm cache) and confirm the "Cursos" link appears in the landing header on desktop widths and is hidden on mobile widths (per the `hidden sm:inline` class), consistent with how the rest of the header collapses on mobile.

- [ ] **Step 6: Report status to the user**

No commit for this task — it's verification only. Report back whether all manual checks passed, and flag anything that didn't match expectations (in particular, visual polish against the full Lex Aeterna spec was explicitly out of scope for this plan — see the Global Constraints deviation note — so rough edges in styling are expected, not a bug).
