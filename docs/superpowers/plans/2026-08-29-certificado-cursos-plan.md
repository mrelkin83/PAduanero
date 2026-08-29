# Certificado de finalización Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar qué lecciones ve cada comprador, detectar cuándo completó un curso, y
entregarle un certificado en PDF descargable con un código público de verificación.

**Architecture:** Un servicio nuevo (`ProgresoCurso`) registra cada vista y, cuando detecta
que un comprador ya vio todas las lecciones de un curso, emite el certificado en el mismo
momento (sin cron ni cola). El PDF se genera al vuelo con `dompdf` en cada descarga —nunca
se guarda un archivo—, y una página pública sin sesión permite verificar cualquier
certificado por su código.

**Tech Stack:** PHP 8.2+, MySQL 8, `dompdf/dompdf` — primera dependencia de producción del
proyecto (decisión consciente del PO).

**Spec:** `docs/superpowers/specs/2026-08-29-certificado-cursos-design.md`

## Global Constraints

- Migración aditiva: `db/migraciones/0035_certificados.sql` (ADR-013). La última migración en
  disco antes de este plan es `0034_contenido_lecciones.sql`.
- «Completar un curso» = haber visto TODAS sus lecciones — automático, sin botón manual.
- El progreso solo se registra cuando el acceso viene de una compra real y pagada
  (`CompraCursoRepo::idDePagadaPorComprador()` devuelve un id) — nunca para una vista previa
  gratis vista sin haber comprado ese curso específico.
- Un certificado ya emitido NUNCA se revoca ni se regenera, aunque el curso gane lecciones
  nuevas después (`certificados.compra_id` es `UNIQUE` — una vez existe la fila, nada la
  vuelve a tocar).
- El número de documento del comprador (`compradores.numero_documento_cifrado`) se descifra
  ÚNICAMENTE en `CompradorRepo::numeroDocumento()`, usado ÚNICAMENTE por la generación del
  PDF — nunca en la página pública de verificación, nunca en ninguna otra plantilla.
- La página pública de verificación (`/certificados/verificar/{codigo}`) responde con el
  MISMO mensaje neutral para un código mal escrito y para uno que nunca existió — nunca se
  distingue entre los dos casos.
- El PDF se genera en memoria en cada descarga — nunca se escribe un archivo a disco.
- CSRF: las rutas de este plan son todas GET (descarga, formulario y resultado de
  verificación) — ninguna necesita validación de CSRF.

---

### Task 1: Migración — esquema de progreso y certificados

**Files:**
- Create: `db/migraciones/0035_certificados.sql`
- Modify: `tests/CasoBaseBd.php` (agregar `curso_progreso` y `certificados` a `limpiar()`)

**Interfaces:**
- Produces: tablas `curso_progreso` (`id, comprador_id, leccion_id, visto_en`, `UNIQUE (comprador_id, leccion_id)`)
  y `certificados` (`id, compra_id, codigo_verificacion, emitido_en`, `UNIQUE` en `compra_id`
  y en `codigo_verificacion`).

- [ ] **Step 1: Escribir la migración**

```sql
-- =====================================================================
-- 0035 — Certificado de finalización (sub-proyecto 4)
--
-- Migración aditiva (ADR-013). Implementa
-- docs/superpowers/specs/2026-08-29-certificado-cursos-design.md.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS curso_progreso (
  id            CHAR(36)   NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)   NOT NULL,
  leccion_id    CHAR(36)   NOT NULL,
  visto_en      TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ix_progreso_unico (comprador_id, leccion_id),
  CONSTRAINT fk_progreso_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id) ON DELETE CASCADE,
  CONSTRAINT fk_progreso_leccion FOREIGN KEY (leccion_id) REFERENCES curso_lecciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS certificados (
  id                    CHAR(36)      NOT NULL DEFAULT (UUID()),
  compra_id             CHAR(36)      NOT NULL,
  codigo_verificacion   VARCHAR(20)   NOT NULL,
  emitido_en            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ix_certificados_compra (compra_id),
  UNIQUE KEY ix_certificados_codigo (codigo_verificacion),
  CONSTRAINT fk_certificados_compra FOREIGN KEY (compra_id) REFERENCES compras_curso(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

- [ ] **Step 2: Agregar las dos tablas a `tests/CasoBaseBd.php`**

En el array de `limpiar()`, agregar `'certificados', 'curso_progreso'` — deben ir ANTES de
`'compras_curso'` y de `'curso_lecciones'`/`'compradores'` respectivamente (padre-antes-que-hijo,
mismo criterio ya establecido en el archivo):

```php
            'certificados', 'curso_progreso', 'curso_materiales', 'curso_lecciones', 'curso_modulos', 'cursos', 'categorias_curso',
            'compradores_enlaces', 'compradores_sesiones', 'compras_curso', 'compradores',
```

(reemplaza la línea `'curso_materiales', 'curso_lecciones', 'curso_modulos', 'cursos', 'categorias_curso',` y la que sigue, ya existentes, por estas dos)

- [ ] **Step 3: Confirmar que la migración corre sin romper nada**

Run: `vendor/bin/phpunit tests/Integracion/CursosTest.php`
Expected: PASS (la base de pruebas se recrea desde cero en la primera prueba de la corrida,
así que esto ya ejercita la migración nueva)

- [ ] **Step 4: Commit**

```bash
git add db/migraciones/0035_certificados.sql tests/CasoBaseBd.php
git commit -m "$(cat <<'EOF'
feat(cursos): esquema de progreso y certificados de finalizacion

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `CompraCursoRepo::idDePagadaPorComprador()`

**Files:**
- Modify: `src/Repositorios/CompraCursoRepo.php`
- Test: `tests/Integracion/CompraCursoRepoTest.php`

**Interfaces:**
- Produces: `CompraCursoRepo::idDePagadaPorComprador(string $compradorId, string $cursoId): ?string`.

- [ ] **Step 1: Escribir la prueba que falla**

El archivo ya tiene helpers `categoria(): string` y `curso(): string` (ver Task 2 del
sub-proyecto 3 para el patrón ya usado ahí de insertar un segundo curso a mano cuando hace
falta). Agregar:

```php
    #[Test]
    public function idDePagadaPorCompradorDevuelveElIdSoloSiEstaPagada(): void
    {
        $cursoId = $this->curso();
        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $compraId = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->vincularComprador($compraId, $compradorId);

        self::assertNull($this->repo->idDePagadaPorComprador($compradorId, $cursoId));

        $this->repo->marcarPagada($compraId);

        self::assertSame($compraId, $this->repo->idDePagadaPorComprador($compradorId, $cursoId));
    }

    #[Test]
    public function idDePagadaPorCompradorEsNullParaOtroComprador(): void
    {
        $cursoId = $this->curso();
        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $otroCompradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $compraId = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->marcarPagada($compraId);
        $this->repo->vincularComprador($compraId, $compradorId);

        self::assertNull($this->repo->idDePagadaPorComprador($otroCompradorId, $cursoId));
    }
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/CompraCursoRepoTest.php`
Expected: FAIL — `Call to undefined method App\Repositorios\CompraCursoRepo::idDePagadaPorComprador()`

- [ ] **Step 3: Implementar**

Agregar en `src/Repositorios/CompraCursoRepo.php`, después de `tienePagada()`:

```php
    public function idDePagadaPorComprador(string $compradorId, string $cursoId): ?string
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT id FROM compras_curso
              WHERE comprador_id = ? AND curso_id = ? AND estado = 'pagada'"
        );
        $stmt->execute([$compradorId, $cursoId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/CompraCursoRepoTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Repositorios/CompraCursoRepo.php tests/Integracion/CompraCursoRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CompraCursoRepo::idDePagadaPorComprador() para registrar progreso

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `CompradorRepo::numeroDocumento()`

**Files:**
- Modify: `src/Repositorios/CompradorRepo.php`
- Test: `tests/Integracion/CompradorRepoTest.php`

**Interfaces:**
- Produces: `CompradorRepo::numeroDocumento(string $compradorId): ?string` — el ÚNICO lugar
  del código, además de `crear()` (que lo escribe), que descifra esa columna.

- [ ] **Step 1: Escribir la prueba que falla**

Revisa el helper de creación de comprador ya existente en
`tests/Integracion/CompradorRepoTest.php` y reutilízalo. Agregar:

```php
    #[Test]
    public function numeroDocumentoDescifraElValorGuardado(): void
    {
        $id = $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-doc@ejemplo.com', 'clave123');

        self::assertSame('1010101010', $this->repo->numeroDocumento($id));
    }

    #[Test]
    public function numeroDocumentoEsNullParaUnCompradorQueNoExiste(): void
    {
        self::assertNull($this->repo->numeroDocumento((string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn()));
    }
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/CompradorRepoTest.php`
Expected: FAIL — `Call to undefined method App\Repositorios\CompradorRepo::numeroDocumento()`

- [ ] **Step 3: Implementar**

Agregar en `src/Repositorios/CompradorRepo.php`, después de `crear()`:

```php
    public function numeroDocumento(string $compradorId): ?string
    {
        $stmt = $this->bd->pdo()->prepare('SELECT numero_documento_cifrado FROM compradores WHERE id = ?');
        $stmt->execute([$compradorId]);
        $blob = $stmt->fetchColumn();

        return $blob === false ? null : $this->cifrado->descifrar((string) $blob);
    }
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/CompradorRepoTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Repositorios/CompradorRepo.php tests/Integracion/CompradorRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CompradorRepo::numeroDocumento() para el certificado en PDF

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `CertificadoRepo`

**Files:**
- Create: `src/Repositorios/CertificadoRepo.php`
- Test: `tests/Integracion/CertificadoRepoTest.php`

**Interfaces:**
- Produces: `CertificadoRepo::crear(string $compraId, string $codigo): void`,
  `CertificadoRepo::porCompra(string $compraId): ?array` (fila cruda de `certificados`),
  `CertificadoRepo::porCodigo(string $codigo): ?array` (con `nombres`, `apellidos`,
  `curso_titulo`, `codigo_verificacion`, `emitido_en` — para la página pública de
  verificación, ya con el `JOIN` resuelto).

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CertificadoRepoTest extends CasoBaseBd
{
    private CertificadoRepo $repo;
    private CompraCursoRepo $compras;
    private CompradorRepo $compradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CertificadoRepo($this->bd);
        $this->compras = new CompraCursoRepo($this->bd);
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
    }

    /** @return array{compraId:string,compradorId:string} */
    private function compraDePrueba(): array
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-cert']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso certificado', 'curso-certificado', 'r', 'd', '[]', 250000, 'publicado']);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-cert@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-cert@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        return ['compraId' => $compraId, 'compradorId' => $compradorId];
    }

    #[Test]
    public function crearYPorCompraDevuelvenLaMismaFila(): void
    {
        $datos = $this->compraDePrueba();

        $this->repo->crear($datos['compraId'], 'PA-ABCD1234');

        $fila = $this->repo->porCompra($datos['compraId']);
        self::assertNotNull($fila);
        self::assertSame('PA-ABCD1234', $fila['codigo_verificacion']);
    }

    #[Test]
    public function porCompraEsNullSiNoSeHaEmitido(): void
    {
        $datos = $this->compraDePrueba();

        self::assertNull($this->repo->porCompra($datos['compraId']));
    }

    #[Test]
    public function porCodigoTraeElNombreDelCompradorYElTituloDelCurso(): void
    {
        $datos = $this->compraDePrueba();
        $this->repo->crear($datos['compraId'], 'PA-XYZ98765');

        $fila = $this->repo->porCodigo('PA-XYZ98765');

        self::assertNotNull($fila);
        self::assertSame('Ana', $fila['nombres']);
        self::assertSame('Gómez', $fila['apellidos']);
        self::assertSame('Curso certificado', $fila['curso_titulo']);
    }

    #[Test]
    public function porCodigoEsNullParaUnCodigoQueNoExiste(): void
    {
        self::assertNull($this->repo->porCodigo('PA-NOEXISTE'));
    }
}
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/CertificadoRepoTest.php`
Expected: FAIL — `Class "App\Repositorios\CertificadoRepo" not found`

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

final class CertificadoRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    public function crear(string $compraId, string $codigo): void
    {
        $this->bd->pdo()->prepare(
            'INSERT INTO certificados (compra_id, codigo_verificacion) VALUES (?, ?)'
        )->execute([$compraId, $codigo]);
    }

    /** @return array<string,mixed>|null */
    public function porCompra(string $compraId): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM certificados WHERE compra_id = ?');
        $stmt->execute([$compraId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /** @return array<string,mixed>|null */
    public function porCodigo(string $codigo): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT cert.codigo_verificacion, cert.emitido_en,
                    comp.nombres, comp.apellidos, c.titulo AS curso_titulo
               FROM certificados cert
               JOIN compras_curso cc ON cc.id = cert.compra_id
               JOIN compradores comp ON comp.id = cc.comprador_id
               JOIN cursos c ON c.id = cc.curso_id
              WHERE cert.codigo_verificacion = ?'
        );
        $stmt->execute([$codigo]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }
}
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/CertificadoRepoTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Repositorios/CertificadoRepo.php tests/Integracion/CertificadoRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CertificadoRepo - CRUD de certificados emitidos

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `App\Cuenta\ProgresoCurso`

**Files:**
- Create: `src/Cuenta/ProgresoCurso.php`
- Test: `tests/Integracion/ProgresoCursoTest.php`

**Interfaces:**
- Consumes: `App\Repositorios\CertificadoRepo` (Task 4).
- Produces: `ProgresoCurso::__construct(BD $bd, CertificadoRepo $certificados)`,
  `ProgresoCurso::registrarVista(string $compradorId, string $leccionId, string $cursoId, string $compraId): void`,
  `ProgresoCurso::estaCompleto(string $compradorId, string $cursoId): bool`,
  `ProgresoCurso::conteo(string $compradorId, string $cursoId): array{vistas:int,total:int}`.

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cuenta\ProgresoCurso;
use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class ProgresoCursoTest extends CasoBaseBd
{
    private ProgresoCurso $progreso;
    private CertificadoRepo $certificados;
    private CompraCursoRepo $compras;
    private CompradorRepo $compradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->certificados = new CertificadoRepo($this->bd);
        $this->progreso = new ProgresoCurso($this->bd, $this->certificados);
        $this->compras = new CompraCursoRepo($this->bd);
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
    }

    /** @return array{compraId:string,compradorId:string,cursoId:string,leccionId1:string,leccionId2:string} */
    private function cursoDeDosLecciones(): array
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-progreso']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso progreso', 'curso-progreso', 'r', 'd', '[]', 250000, 'publicado']);
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId1 = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $leccionId2 = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId1, $moduloId, 'Lección 1', 0]);
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId2, $moduloId, 'Lección 2', 1]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-progreso@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-progreso@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        return ['compraId' => $compraId, 'compradorId' => $compradorId, 'cursoId' => $cursoId, 'leccionId1' => $leccionId1, 'leccionId2' => $leccionId2];
    }

    #[Test]
    public function registrarLaMismaVistaDosVecesNoDuplicaLaFila(): void
    {
        $d = $this->cursoDeDosLecciones();

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);
        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);

        $total = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_progreso')->fetchColumn();
        self::assertSame(1, $total);
    }

    #[Test]
    public function conteoReflejaLasLeccionesVistasYElTotalDelCurso(): void
    {
        $d = $this->cursoDeDosLecciones();

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);

        $conteo = $this->progreso->conteo($d['compradorId'], $d['cursoId']);
        self::assertSame(['vistas' => 1, 'total' => 2], $conteo);
    }

    #[Test]
    public function completarLaUltimaLeccionEmiteElCertificadoUnaVez(): void
    {
        $d = $this->cursoDeDosLecciones();

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);
        self::assertFalse($this->progreso->estaCompleto($d['compradorId'], $d['cursoId']));
        self::assertNull($this->certificados->porCompra($d['compraId']));

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId2'], $d['cursoId'], $d['compraId']);

        self::assertTrue($this->progreso->estaCompleto($d['compradorId'], $d['cursoId']));
        $certificado = $this->certificados->porCompra($d['compraId']);
        self::assertNotNull($certificado);
        self::assertMatchesRegularExpression('/^PA-[0-9A-F]{8}$/', $certificado['codigo_verificacion']);
    }

    #[Test]
    public function volverAVerUnaLeccionYaCompletadaNoCambiaElCertificado(): void
    {
        $d = $this->cursoDeDosLecciones();
        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);
        $this->progreso->registrarVista($d['compradorId'], $d['leccionId2'], $d['cursoId'], $d['compraId']);
        $codigoOriginal = $this->certificados->porCompra($d['compraId'])['codigo_verificacion'];

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);

        self::assertSame($codigoOriginal, $this->certificados->porCompra($d['compraId'])['codigo_verificacion']);
        $total = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM certificados')->fetchColumn();
        self::assertSame(1, $total);
    }
}
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/ProgresoCursoTest.php`
Expected: FAIL — `Class "App\Cuenta\ProgresoCurso" not found`

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\BD;
use App\Repositorios\CertificadoRepo;

/**
 * Registra qué lecciones ya vio cada comprador, y emite el certificado en el
 * mismo momento en que detecta que un curso quedó completo. No hay cron ni
 * cola: la emisión ocurre síncrona, dentro de la misma petición que registra
 * la última vista que faltaba.
 */
final class ProgresoCurso
{
    public function __construct(
        private readonly BD $bd,
        private readonly CertificadoRepo $certificados,
    ) {
    }

    public function registrarVista(string $compradorId, string $leccionId, string $cursoId, string $compraId): void
    {
        $this->bd->pdo()->prepare(
            'INSERT IGNORE INTO curso_progreso (comprador_id, leccion_id) VALUES (?, ?)'
        )->execute([$compradorId, $leccionId]);

        // Un certificado ya emitido nunca se toca de nuevo — ni siquiera se
        // vuelve a evaluar estaCompleto() si porCompra() ya encontró uno.
        if ($this->certificados->porCompra($compraId) !== null) {
            return;
        }

        if ($this->estaCompleto($compradorId, $cursoId)) {
            $this->certificados->crear($compraId, $this->codigoUnico());
        }
    }

    public function estaCompleto(string $compradorId, string $cursoId): bool
    {
        $conteo = $this->conteo($compradorId, $cursoId);

        return $conteo['total'] > 0 && $conteo['vistas'] >= $conteo['total'];
    }

    /** @return array{vistas:int,total:int} */
    public function conteo(string $compradorId, string $cursoId): array
    {
        $stmtTotal = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cm.curso_id = ?'
        );
        $stmtTotal->execute([$cursoId]);
        $total = (int) $stmtTotal->fetchColumn();

        $stmtVistas = $this->bd->pdo()->prepare(
            'SELECT COUNT(DISTINCT cp.leccion_id) FROM curso_progreso cp
               JOIN curso_lecciones cl ON cl.id = cp.leccion_id
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cp.comprador_id = ? AND cm.curso_id = ?'
        );
        $stmtVistas->execute([$compradorId, $cursoId]);
        $vistas = (int) $stmtVistas->fetchColumn();

        return ['vistas' => $vistas, 'total' => $total];
    }

    private function codigoUnico(): string
    {
        do {
            $codigo = 'PA-' . strtoupper(bin2hex(random_bytes(4)));
        } while ($this->certificados->porCodigo($codigo) !== null);

        return $codigo;
    }
}
```

- [ ] **Step 4: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/ProgresoCursoTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Cuenta/ProgresoCurso.php tests/Integracion/ProgresoCursoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): ProgresoCurso - registra vistas y emite el certificado al completar

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: `dompdf` + `App\Cuenta\CertificadoPdf`

**Files:**
- Modify: `composer.json` (nueva dependencia de producción)
- Create: `src/Cuenta/CertificadoPdf.php`
- Test: `tests/Unidad/CertificadoPdfTest.php`

**Interfaces:**
- Consumes: `App\Repositorios\CompradorRepo` (Tasks 2-3 del sub-proyecto 2 y la Task 3 de
  este plan), `App\Soporte\Vista::e()`.
- Produces: `CertificadoPdf::__construct(CompradorRepo $compradores)`,
  `CertificadoPdf::generar(string $compradorId, string $nombreCurso, string $codigo, string $emitidoEn): string`
  (devuelve los bytes crudos del PDF).

**Esta es la primera dependencia de producción del proyecto** — decisión consciente del PO,
documentada en el spec §1.

- [ ] **Step 1: Instalar la dependencia**

```bash
composer require dompdf/dompdf
```

Expected: `composer.json` gana `"dompdf/dompdf": "^3.0"` (o la versión estable que resuelva
Composer) dentro de `require` (no `require-dev`) junto a `php`/`ext-*`; `composer.lock` se
actualiza; `vendor/dompdf/` aparece.

- [ ] **Step 2: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Cuenta\CertificadoPdf;
use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CertificadoPdfTest extends CasoBaseBd
{
    #[Test]
    public function generarProduceUnPdfNoVacio(): void
    {
        $compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $compradorId = $compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-pdf@ejemplo.com', 'clave123');

        $pdf = new CertificadoPdf($compradores);
        $bytes = $pdf->generar($compradorId, 'Clasificación arancelaria', 'PA-ABCD1234', '2026-08-29');

        self::assertGreaterThan(1000, strlen($bytes));
        self::assertStringStartsWith('%PDF-', $bytes);
    }
}
```

(Esta prueba hereda de `CasoBaseBd` porque `CompradorRepo::crear()` necesita MySQL real —
aunque `CertificadoPdf` en sí no toca la base de datos directamente, vive en
`tests/Unidad/` porque prueba una sola clase aislada, no un flujo de integración completo;
es la misma convención ya usada en otras pruebas «unitarias» de este proyecto que igual
necesitan datos reales de apoyo.)

- [ ] **Step 3: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Unidad/CertificadoPdfTest.php`
Expected: FAIL — `Class "App\Cuenta\CertificadoPdf" not found`

- [ ] **Step 4: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Repositorios\CompradorRepo;
use App\Soporte\Vista;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Genera el PDF del certificado en memoria — nunca se guarda un archivo en
 * disco, se produce de nuevo en cada descarga.
 */
final class CertificadoPdf
{
    public function __construct(private readonly CompradorRepo $compradores)
    {
    }

    public function generar(string $compradorId, string $nombreCurso, string $codigo, string $emitidoEn): string
    {
        $comprador = $this->compradores->porId($compradorId);
        $numeroDocumento = $this->compradores->numeroDocumento($compradorId) ?? '';

        $html = $this->html(
            $comprador?->nombreCompleto() ?? '',
            $comprador?->tipoDocumento ?? '',
            $numeroDocumento,
            $nombreCurso,
            $codigo,
            $emitidoEn,
        );

        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function html(
        string $nombre,
        string $tipoDocumento,
        string $numeroDocumento,
        string $curso,
        string $codigo,
        string $emitidoEn,
    ): string {
        $e = Vista::e(...);

        return <<<HTML
        <!doctype html>
        <html><head><meta charset="utf-8"><style>
        body { font-family: sans-serif; text-align: center; padding: 60px; }
        h1 { font-size: 32px; margin-bottom: 40px; }
        .nombre { font-size: 28px; font-weight: bold; margin: 30px 0; }
        .curso { font-size: 20px; margin-bottom: 30px; }
        .pie { margin-top: 60px; font-size: 12px; color: #666; }
        </style></head><body>
        <h1>Certificado de finalización</h1>
        <p>Se certifica que</p>
        <p class="nombre">{$e($nombre)}</p>
        <p>completó satisfactoriamente el curso</p>
        <p class="curso">{$e($curso)}</p>
        <p class="pie">
            {$e($tipoDocumento)} {$e($numeroDocumento)}<br>
            Emitido el {$e($emitidoEn)}<br>
            Código de verificación: {$e($codigo)}
        </p>
        </body></html>
        HTML;
    }
}
```

- [ ] **Step 5: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Unidad/CertificadoPdfTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock src/Cuenta/CertificadoPdf.php tests/Unidad/CertificadoPdfTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CertificadoPdf - genera el PDF del certificado con dompdf

Primera dependencia de produccion del proyecto (dompdf/dompdf) - decision
consciente del PO, documentada en el spec de este sub-proyecto.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Integrar `ProgresoCurso` en `AulaControlador::leccion()`

**Files:**
- Modify: `src/Cuenta/AulaControlador.php` (constructor + `leccion()`)
- Modify: `src/Core/Aplicacion.php` (las 3 rutas que construyen `AulaControlador`)
- Modify: `tests/Integracion/AulaControladorTest.php` (constructor)
- Test: `tests/Integracion/AulaControladorTest.php` (pruebas nuevas)

**Interfaces:**
- Consumes: `App\Cuenta\ProgresoCurso::registrarVista()` (Task 5),
  `CompraCursoRepo::idDePagadaPorComprador()` (Task 2).
- Produces: `AulaControlador::__construct()` gana un 8º argumento `ProgresoCurso $progreso`
  al final — Tasks 8, 10 y 11 de este plan lo reutilizan sin volver a cambiar el constructor.

- [ ] **Step 1: Escribir las pruebas que fallan**

Agregar a `tests/Integracion/AulaControladorTest.php`. Primero actualizar `setUp()` (línea
53-61) agregando el 8º argumento:

```php
        $this->controlador = new AulaControlador(
            $this->auth,
            $cursos,
            $this->compras,
            new AccesoLeccion($this->compras),
            $this->bd,
            new BunnyStream('', ''),
            new CursoMaterialRepo($this->bd),
            new \App\Cuenta\ProgresoCurso($this->bd, new \App\Repositorios\CertificadoRepo($this->bd)),
        );
```

Luego, las pruebas nuevas (reutiliza el helper `curso(string $slug): string` y el patrón de
sesión ya establecidos en este archivo):

```php
    #[Test]
    public function verUnaLeccionRegistraElProgresoDelCompradorQuePago(): void
    {
        $cursoId = $this->curso('curso-progreso-leccion');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId, $moduloId, 'Lección', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-vp@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-vp@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $this->controlador->leccion($this->peticion(), 'curso-progreso-leccion', $leccionId);

        $total = (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM curso_progreso WHERE comprador_id = '{$compradorId}' AND leccion_id = '{$leccionId}'"
        )->fetchColumn();
        self::assertSame(1, $total);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function verUnaLeccionDePreviewSinHaberCompradoNoRegistraProgreso(): void
    {
        $cursoId = $this->curso('curso-preview-sin-progreso');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden, vista_previa_gratis) VALUES (?, ?, ?, ?, 1)'
        )->execute([$leccionId, $moduloId, 'Lección preview', 0]);

        $this->controlador->leccion($this->peticion(), 'curso-preview-sin-progreso', $leccionId);

        $total = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_progreso')->fetchColumn();
        self::assertSame(0, $total);
    }
```

(El archivo ya tiene `peticion(): Peticion` en la línea 78 — reutilízalo tal cual, no lo
reescribas.)

- [ ] **Step 2: Confirmar que fallan**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: FAIL — `Too few arguments to function App\Cuenta\AulaControlador::__construct()`

- [ ] **Step 3: Actualizar el constructor y `leccion()`**

En `src/Cuenta/AulaControlador.php`:

```php
    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly Cursos $cursos,
        private readonly CompraCursoRepo $compras,
        private readonly AccesoLeccion $acceso,
        private readonly BD $bd,
        private readonly \App\Soporte\BunnyStream $bunny,
        private readonly \App\Repositorios\CursoMaterialRepo $materiales,
        private readonly ProgresoCurso $progreso,
    ) {
    }
```

En `leccion()`, insertar justo antes del `return Respuesta::vista(...)` final:

```php
        if ($comprador !== null) {
            $compraId = $this->compras->idDePagadaPorComprador($comprador->id, $curso['id']);
            if ($compraId !== null) {
                $this->progreso->registrarVista($comprador->id, $leccionId, $curso['id'], $compraId);
            }
        }

        return Respuesta::vista('cuenta/leccion', [
```

(el resto del array pasado a `Respuesta::vista()` queda exactamente igual — no lo repitas,
solo inserta el bloque nuevo antes del `return` existente)

- [ ] **Step 4: Actualizar las 3 rutas de `src/Core/Aplicacion.php`**

Busca las 3 construcciones `new \App\Cuenta\AulaControlador(` (rutas `/mis-cursos/{slug}`,
`/mis-cursos/{slug}/leccion/{leccionId}` y `/mis-cursos/{slug}/leccion/{leccionId}/material/{materialId}`)
y agrégales el 8º argumento al final de cada una:

```php
                new \App\Cuenta\ProgresoCurso(
                    $this->contenedor->obtener(BD::class),
                    $this->contenedor->obtener(\App\Repositorios\CertificadoRepo::class),
                ),
```

`CertificadoRepo::class` aún no está registrado en el contenedor — agrégalo en
`registrarServicios()`, justo después del registro de `CursoMaterialRepo::class`:

```php
        $this->contenedor->registrar(
            \App\Repositorios\CertificadoRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CertificadoRepo => new \App\Repositorios\CertificadoRepo(
                $c->obtener(BD::class),
            ),
        );
```

- [ ] **Step 5: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: PASS (todas, incluidas las 2 nuevas)

- [ ] **Step 6: Commit**

```bash
git add src/Cuenta/AulaControlador.php src/Core/Aplicacion.php tests/Integracion/AulaControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): registrar progreso al ver una leccion de un curso pagado

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Descarga del certificado (`/mis-cursos/{slug}/certificado`)

**Files:**
- Create: `src/Cuenta/CertificadoControlador.php`
- Modify: `src/Core/Aplicacion.php` (una ruta nueva)
- Test: `tests/Integracion/CertificadoControladorTest.php`

**Interfaces:**
- Consumes: `App\Repositorios\CertificadoRepo` (Task 4), `App\Cuenta\CertificadoPdf` (Task 6),
  `CompraCursoRepo::idDePagadaPorComprador()` (Task 2).
- Produces: `CertificadoControlador::__construct(AutenticacionComprador, Cursos, CompraCursoRepo, CertificadoRepo, CertificadoPdf)`,
  `CertificadoControlador::descargar(Peticion $peticion, string $slug): Respuesta`. Las
  Tasks 9 le agrega más métodos a esta misma clase (la verificación pública) — el
  constructor no vuelve a cambiar.

- [ ] **Step 1: Escribir la prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\AccesoControlador;
use App\Cuenta\CertificadoControlador;
use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Servicios\ConfigMysql;
use App\Servicios\Cursos;
use App\Soporte\BunnyStream;
use App\Soporte\Cifrado;
use App\Cuenta\CertificadoPdf;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CertificadoControladorTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private CertificadoControlador $controlador;
    private CompradorRepo $compradores;
    private CompraCursoRepo $compras;
    private CertificadoRepo $certificados;
    private AutenticacionComprador $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->compras = new CompraCursoRepo($this->bd);
        $this->certificados = new CertificadoRepo($this->bd);
        $sesiones = new CompradorSesionRepo($this->bd);
        $this->auth = new AutenticacionComprador($this->compradores, $sesiones, new IntentoAccesoRepo($this->bd));

        $sufijo = bin2hex(random_bytes(4));
        $config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-cert-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-cert-cfg-{$sufijo}.json",
        );
        $cursos = new Cursos($this->bd, $config, self::URL, new BunnyStream('', ''));

        $this->controlador = new CertificadoControlador(
            $this->auth,
            $cursos,
            $this->compras,
            $this->certificados,
            new CertificadoPdf($this->compradores),
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
        )->execute([$id, $catId, 'Curso certificado descarga', $slug, 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    private function peticion(): Peticion
    {
        return new Peticion(metodo: 'GET', ruta: '/mis-cursos/x/certificado');
    }

    #[Test]
    public function sinSesionRedirigeAEntrar(): void
    {
        $r = $this->controlador->descargar($this->peticion(), 'cualquier-curso');

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function conSesionPeroSinCertificadoRedirigeAlAula(): void
    {
        $cursoId = $this->curso('curso-sin-certificado');
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-desc@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-desc@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->descargar($this->peticion(), 'curso-sin-certificado');

        self::assertSame(302, $r->estado);
        self::assertSame('/mis-cursos/curso-sin-certificado', $r->cabeceras['Location']);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function conCertificadoDevuelveElPdf(): void
    {
        $cursoId = $this->curso('curso-con-certificado');
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-desc2@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-desc2@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);
        $this->certificados->crear($compraId, 'PA-TESTCERT');

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->descargar($this->peticion(), 'curso-con-certificado');

        self::assertSame(200, $r->estado);
        self::assertSame('application/pdf', $r->cabeceras['Content-Type']);
        self::assertStringStartsWith('%PDF-', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }
}
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/CertificadoControladorTest.php`
Expected: FAIL — `Class "App\Cuenta\CertificadoControlador" not found`

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\AutenticacionComprador;
use App\Servicios\Cursos;

final class CertificadoControlador
{
    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly Cursos $cursos,
        private readonly CompraCursoRepo $compras,
        private readonly CertificadoRepo $certificados,
        private readonly CertificadoPdf $pdf,
    ) {
    }

    public function descargar(Peticion $peticion, string $slug): Respuesta
    {
        $comprador = $this->compradorActual();
        if ($comprador === null) {
            return new Respuesta('', 302, ['Location' => '/entrar']);
        }

        $curso = $this->cursos->porSlug($slug);
        $compraId = $curso !== null ? $this->compras->idDePagadaPorComprador($comprador->id, $curso['id']) : null;
        if ($compraId === null) {
            return new Respuesta('', 302, ['Location' => '/mis-cursos']);
        }

        $certificado = $this->certificados->porCompra($compraId);
        if ($certificado === null) {
            return new Respuesta('', 302, ['Location' => '/mis-cursos/' . $slug]);
        }

        $bytes = $this->pdf->generar(
            $comprador->id,
            (string) $curso['titulo'],
            (string) $certificado['codigo_verificacion'],
            substr((string) $certificado['emitido_en'], 0, 10),
        );

        return Respuesta::archivo($bytes, 'certificado-' . $slug . '.pdf', 'application/pdf');
    }

    /** @return \App\Modelos\Comprador|null */
    private function compradorActual(): ?\App\Modelos\Comprador
    {
        $token = $_COOKIE[AccesoControlador::COOKIE] ?? null;

        return (is_string($token) && $token !== '') ? $this->auth->compradorDeSesion($token) : null;
    }
}
```

- [ ] **Step 4: Cablear la ruta en `src/Core/Aplicacion.php`**

Insertar inmediatamente después de las rutas de `AulaControlador` ya existentes en
`registrarRutas()`:

```php
        $this->router->get('/mis-cursos/{slug}/certificado', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\CertificadoControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $this->contenedor->obtener(\App\Repositorios\CertificadoRepo::class),
                new \App\Cuenta\CertificadoPdf($this->contenedor->obtener(\App\Repositorios\CompradorRepo::class)),
            ))->descargar($p, (string) $p->parametros['slug']);
        });
```

- [ ] **Step 5: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/CertificadoControladorTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Cuenta/CertificadoControlador.php src/Core/Aplicacion.php tests/Integracion/CertificadoControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): descarga del certificado en /mis-cursos/{slug}/certificado

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Verificación pública del certificado

**Files:**
- Modify: `src/Cuenta/CertificadoControlador.php` (dos métodos nuevos)
- Create: `plantillas/cuenta/certificado_verificar.php`
- Create: `plantillas/cuenta/certificado_resultado.php`
- Modify: `src/Core/Aplicacion.php` (dos rutas nuevas)
- Test: `tests/Integracion/CertificadoControladorTest.php`

**Interfaces:**
- Produces: `CertificadoControlador::verificarMostrar(Peticion $peticion): Respuesta`,
  `CertificadoControlador::verificarBuscar(Peticion $peticion, string $codigo): Respuesta`.

- [ ] **Step 1: Escribir las pruebas que fallan**

Agregar a `tests/Integracion/CertificadoControladorTest.php`:

```php
    #[Test]
    public function verificarConUnCodigoRealMuestraLosDatos(): void
    {
        $cursoId = $this->curso('curso-verificar-real');
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-verif@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-verif@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);
        $this->certificados->crear($compraId, 'PA-VERIFICA1');

        $r = $this->controlador->verificarBuscar(new Peticion(metodo: 'GET', ruta: '/certificados/verificar/PA-VERIFICA1'), 'PA-VERIFICA1');

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('Ana', $r->cuerpo);
        self::assertStringContainsString('Gómez', $r->cuerpo);
        self::assertStringContainsString('Curso certificado descarga', $r->cuerpo);
        self::assertStringNotContainsString('1010101010', $r->cuerpo);
    }

    #[Test]
    public function verificarConUnCodigoInventadoDaElMismoMensajeNeutral(): void
    {
        $r1 = $this->controlador->verificarBuscar(new Peticion(metodo: 'GET', ruta: '/certificados/verificar/PA-NOEXISTE'), 'PA-NOEXISTE');
        $r2 = $this->controlador->verificarBuscar(new Peticion(metodo: 'GET', ruta: '/certificados/verificar/PA-OTROMAS'), 'PA-OTROMAS');

        self::assertSame($r1->estado, $r2->estado);
        self::assertSame($r1->cuerpo, $r2->cuerpo);
    }
```

- [ ] **Step 2: Confirmar que fallan**

Run: `vendor/bin/phpunit tests/Integracion/CertificadoControladorTest.php`
Expected: FAIL — `Call to undefined method App\Cuenta\CertificadoControlador::verificarBuscar()`

- [ ] **Step 3: Implementar los métodos**

Agregar en `src/Cuenta/CertificadoControlador.php`, después de `descargar()`:

```php
    public function verificarMostrar(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('cuenta/certificado_verificar', []);
    }

    public function verificarBuscar(Peticion $peticion, string $codigo): Respuesta
    {
        return Respuesta::vista('cuenta/certificado_resultado', [
            'certificado' => $this->certificados->porCodigo($codigo),
        ]);
    }
```

- [ ] **Step 4: Escribir las plantillas nuevas**

`plantillas/cuenta/certificado_verificar.php`:

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificar certificado</title>
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-24 md:px-7">
    <h1 class="titular-seccion">Verificar un certificado</h1>
    <p class="mt-4 text-acero">Escriba el código de verificación impreso en el certificado.</p>

    <form method="get" action="" class="mt-6" onsubmit="event.preventDefault(); window.location = '/certificados/verificar/' + encodeURIComponent(this.codigo.value.trim());">
        <input name="codigo" placeholder="PA-XXXXXXXX" class="campo" required>
        <button type="submit" class="boton-diagnostico-global mt-3">Verificar</button>
    </form>
</main>

</body>
</html>
```

`plantillas/cuenta/certificado_resultado.php`:

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/** @var array<string,mixed>|null $certificado */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificación de certificado</title>
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-24 md:px-7 text-center">
    <?php if ($certificado === null): ?>
    <h1 class="titular-seccion">No encontrado</h1>
    <p class="mt-4 text-acero">Ese código no corresponde a ningún certificado.</p>
    <?php else: ?>
    <h1 class="titular-seccion">Certificado válido</h1>
    <p class="mt-6 text-lg font-semibold"><?= $e((string) $certificado['nombres']) ?> <?= $e((string) $certificado['apellidos']) ?></p>
    <p class="mt-2 text-acero">completó el curso</p>
    <p class="mt-2 text-lg"><?= $e((string) $certificado['curso_titulo']) ?></p>
    <p class="mt-6 text-sm text-acero">Emitido el <?= $e(substr((string) $certificado['emitido_en'], 0, 10)) ?></p>
    <?php endif; ?>

    <a href="/certificados/verificar" class="menu-enlace mt-8 inline-block">Verificar otro código</a>
</main>

</body>
</html>
```

- [ ] **Step 5: Cablear las rutas en `src/Core/Aplicacion.php`**

Insertar inmediatamente después de la ruta `/mis-cursos/{slug}/certificado` de la Task 8:

```php
        $this->router->get('/certificados/verificar', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\CertificadoControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $this->contenedor->obtener(\App\Repositorios\CertificadoRepo::class),
                new \App\Cuenta\CertificadoPdf($this->contenedor->obtener(\App\Repositorios\CompradorRepo::class)),
            ))->verificarMostrar($p);
        });

        $this->router->get('/certificados/verificar/{codigo}', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\CertificadoControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $this->contenedor->obtener(\App\Repositorios\CertificadoRepo::class),
                new \App\Cuenta\CertificadoPdf($this->contenedor->obtener(\App\Repositorios\CompradorRepo::class)),
            ))->verificarBuscar($p, (string) $p->parametros['codigo']);
        });
```

(`GET /certificados/verificar` y `GET /certificados/verificar/{codigo}` tienen distinta
cantidad de segmentos — 2 contra 3 — así que no hay riesgo de que una capture a la otra, a
diferencia del caso de `/mis-cursos/completar` documentado en el sub-proyecto 3.)

- [ ] **Step 6: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/CertificadoControladorTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Commit**

```bash
git add src/Cuenta/CertificadoControlador.php plantillas/cuenta/certificado_verificar.php \
        plantillas/cuenta/certificado_resultado.php src/Core/Aplicacion.php \
        tests/Integracion/CertificadoControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): verificacion publica de certificados por codigo

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Progreso y descarga en el aula (`/mis-cursos/{slug}`)

**Files:**
- Modify: `src/Cuenta/AulaControlador.php` (método `aula()`)
- Modify: `plantillas/cuenta/aula.php`
- Test: `tests/Integracion/AulaControladorTest.php`

**Interfaces:**
- Consumes: `ProgresoCurso::conteo()` y `ProgresoCurso::estaCompleto()` (Task 5, ya inyectado
  en el constructor desde la Task 7).

- [ ] **Step 1: Escribir la prueba que falla**

```php
    #[Test]
    public function elAulaMuestraElConteoDeProgreso(): void
    {
        $cursoId = $this->curso('curso-aula-progreso');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (UUID(), ?, ?, ?)')
            ->execute([$moduloId, 'Lección', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-aula-prog@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-aula-prog@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $r = $this->controlador->aula($this->peticion(), 'curso-aula-progreso');

        self::assertStringContainsString('0 de 1', $r->cuerpo);
        self::assertStringNotContainsString('Descargar certificado', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }

    #[Test]
    public function elAulaMuestraElEnlaceDeDescargaCuandoYaEstaCompleto(): void
    {
        $cursoId = $this->curso('curso-aula-completo');
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId, $moduloId, 'Lección', 0]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-aula-comp@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-aula-comp@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        $comprador = $this->compradores->porId($compradorId);
        $_COOKIE[AccesoControlador::COOKIE] = $this->auth->abrirSesion($comprador, null, null);

        $this->controlador->leccion($this->peticion(), 'curso-aula-completo', $leccionId);

        $r = $this->controlador->aula($this->peticion(), 'curso-aula-completo');

        self::assertStringContainsString('Descargar certificado', $r->cuerpo);
        self::assertStringContainsString('/mis-cursos/curso-aula-completo/certificado', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }
```

- [ ] **Step 2: Confirmar que fallan**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: FAIL (el texto «0 de 1» y «Descargar certificado» todavía no existen en la
plantilla)

- [ ] **Step 3: Actualizar `aula()`**

En `src/Cuenta/AulaControlador.php`, reemplazar el `return Respuesta::vista('cuenta/aula', ...)`
de `aula()`:

```php
        $conteo = $this->progreso->conteo($comprador->id, $curso['id']);

        return Respuesta::vista('cuenta/aula', [
            'curso' => $curso,
            'modulos' => $this->temario($curso['id']),
            'progreso' => $conteo,
            'completo' => $this->progreso->estaCompleto($comprador->id, $curso['id']),
        ]);
```

- [ ] **Step 4: Actualizar `plantillas/cuenta/aula.php`**

Agregar el docblock `@var` y el bloque de progreso justo después del `<h1>` del título del
curso:

```php
/**
 * @var array<string,mixed> $curso
 * @var list<array{id:string,titulo:string,lecciones:list<array<string,mixed>>}> $modulos
 * @var array{vistas:int,total:int} $progreso
 * @var bool $completo
 */
```

(reemplaza el docblock `@var` existente, agregando las dos líneas nuevas)

```php
    <h1 class="titular-seccion"><?= $e((string) $curso['titulo']) ?></h1>

    <?php if ($completo): ?>
    <a href="/mis-cursos/<?= $e((string) $curso['slug']) ?>/certificado" class="boton-diagnostico-global mt-4 inline-block">
        Descargar certificado
    </a>
    <?php else: ?>
    <p class="mt-2 text-sm text-acero"><?= $e((string) $progreso['vistas']) ?> de <?= $e((string) $progreso['total']) ?> lecciones vistas</p>
    <?php endif; ?>
```

(inserta este bloque inmediatamente después del `<h1>` ya existente)

- [ ] **Step 5: Confirmar que pasan**

Run: `vendor/bin/phpunit tests/Integracion/AulaControladorTest.php`
Expected: PASS (todas)

- [ ] **Step 6: Commit**

```bash
git add src/Cuenta/AulaControlador.php plantillas/cuenta/aula.php tests/Integracion/AulaControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): progreso y descarga del certificado en el aula

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Panel — columna «Certificado» en compras

**Files:**
- Modify: `src/Panel/PanelCursosControlador.php` (método `compras()`)
- Modify: `plantillas/panel/cursos_compras.php`
- Test: `tests/Integracion/PanelCursosTest.php`

**Interfaces:**
- Consumes: nada nuevo del resto del plan — solo la tabla `certificados` (Task 1).

- [ ] **Step 1: Escribir la prueba que falla**

El archivo ya tiene un helper `compraDePruebaPara(string $slug): string` — revísalo y
reutilízalo. Agregar:

```php
    #[Test]
    public function laListaDeComprasMuestraElCodigoDeCertificadoSiExiste(): void
    {
        $compraId = $this->compraDePruebaPara('curso-panel-certificado');
        $this->bd->pdo()->prepare(
            "INSERT INTO certificados (compra_id, codigo_verificacion) VALUES (?, 'PA-PANELTEST')"
        )->execute([$compraId]);

        $html = $this->controlador()->compras($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('PA-PANELTEST', $html);
    }
```

- [ ] **Step 2: Confirmar que falla**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: FAIL — el código `PA-PANELTEST` no aparece todavía en el HTML

- [ ] **Step 3: Implementar**

En `src/Panel/PanelCursosControlador.php`, reemplazar la consulta de `compras()`:

```php
        $filas = $this->bd->pdo()->query(
            "SELECT cc.*, c.titulo, cert.codigo_verificacion
               FROM compras_curso cc
               JOIN cursos c ON c.id = cc.curso_id
               LEFT JOIN certificados cert ON cert.compra_id = cc.id
              ORDER BY cc.creado_en DESC"
        )->fetchAll();
```

(reemplaza la consulta `SELECT cc.*, c.titulo FROM compras_curso cc JOIN cursos c ON c.id = cc.curso_id ORDER BY cc.creado_en DESC` ya existente — el único cambio real es el `LEFT JOIN` nuevo y la columna `cert.codigo_verificacion` agregada al `SELECT`)

- [ ] **Step 4: Actualizar la plantilla**

En `plantillas/panel/cursos_compras.php`, agregar una columna al `<thead>`:

```php
        <thead><tr><th>Curso</th><th>Comprador</th><th>Correo</th><th>Precio</th><th>Estado</th><th>Certificado</th><th></th></tr></thead>
```

(reemplaza el `<thead>` existente, que tenía 6 columnas — ahora son 7)

Y agregar la celda correspondiente en el `<tr>` del `foreach`, justo después de la celda de
«Estado»:

```php
            <td><?= (string) $c['estado'] ?></td>
            <td class="font-mono text-xs"><?= $e((string) ($c['codigo_verificacion'] ?? '—')) ?></td>
```

(reemplaza la línea `<td><?= $e((string) $c['estado']) ?></td>` ya existente, insertando la
celda nueva justo después)

También actualizar el `colspan` del mensaje «Todavía no hay compras»:

```php
        <tr><td colspan="7" class="text-acero">Todavía no hay compras.</td></tr>
```

(era `colspan="6"`)

- [ ] **Step 5: Confirmar que pasa**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: PASS (todas)

- [ ] **Step 6: Commit**

```bash
git add src/Panel/PanelCursosControlador.php plantillas/panel/cursos_compras.php \
        tests/Integracion/PanelCursosTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): columna de certificado en el panel de compras

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Suite completa y verificación manual

**Files:** none (verification only)

- [ ] **Step 1: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: todo en verde, incluidas todas las pruebas previas y las agregadas en las Tasks
1-11. Si se corta abruptamente con un código de salida raro y sin el resumen final de
PHPUnit, es ruido conocido del entorno compartido (ya documentado en los sub-proyectos
2 y 3 de este mismo módulo) — reinténtalo, no lo reportes como hallazgo salvo que se repita
más de 3 veces seguidas.

- [ ] **Step 2: Verificar manualmente, en el VPS después de desplegar**

Por convención del proyecto, la verificación funcional siempre se hace en producción (ver
memoria `pruebas-en-produccion`). Una vez desplegado: como comprador de pruebas que ya pagó
un curso con una sola lección, entrar a esa lección y confirmar que el aula pasa de mostrar
«0 de 1 lecciones vistas» a mostrar el botón «Descargar certificado». Descargar el PDF y
confirmar que abre y muestra el nombre, el curso, el número de documento y el código.
Copiar el código, ir a `/certificados/verificar`, pegarlo, y confirmar que la página de
resultado muestra los mismos datos SIN el número de documento. Probar un código inventado y
confirmar el mensaje neutral. En el panel, confirmar que `/panel/cursos/compras` muestra el
código de ese certificado en la columna nueva.

- [ ] **Step 3: Reportar**

Reportar qué verificaciones manuales pasaron. A diferencia de los sub-proyectos anteriores,
esta verificación NO depende de ninguna credencial externa pendiente (Bunny, SMTP) — solo de
tener un comprador y un curso de una sola lección para probar el ciclo completo rápido.

## Pendiente operativo (no es código de este plan)

- [ ] Confirmar que el VPS tiene las extensiones PHP que `dompdf` necesita (revisar
      `composer require` — si falla localmente por una extensión faltante, es la misma que
      falta en el VPS: instalarla ahí antes de desplegar).
- [ ] Diseño visual del certificado (logo, tipografía, borde) más allá de lo funcional — es
      un ajuste de la plantilla HTML dentro de `CertificadoPdf::html()`, no bloquea nada.
