# Cobro con Wompi y cuenta de comprador — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build course checkout with Wompi and a buyer-account system (register/login/reset password) so a purchase automatically confirms and gives the buyer a place to log in — sub-projects 2a+2b of the courses module.

**Architecture:** Reuse two standalone, already-hardened classes from the vendorized WhatsApp engine (`WompiAdapter` for the Wompi API, `EvolutionClient` for outbound WhatsApp) via a small new bridge class — no changes to the conversational engine itself except one additive branch in the existing payment webhook. Buyer accounts are a complete parallel authentication system (`compradores`/`compradores_sesiones`) with zero overlap with the panel's `usuarios`/`sesiones`.

**Tech Stack:** PHP 8.2+, MySQL 8, plain PDO, PHPUnit, `ElkinLinan\WhatsappAiEngine\Payments\WompiAdapter` (existing, autoloaded via the main `composer.json`), the project's own `App\Soporte\Smtp`.

**Spec:** `docs/superpowers/specs/2026-08-26-cobro-y-cuenta-comprador-design.md`

## Global Constraints

- Money is stored as whole COP pesos everywhere except the one Wompi API boundary (`WompiAdapter::aCentavos()`, already handles the conversion) — never introduce a second `*100` anywhere in this plan's own code.
- Wompi credentials live in the single row `wa_config` (id=1) — reused as-is, never duplicated into a second config table.
- The payment webhook stays singular: `POST /api/wa/pago/{token}`, `App\Wa\WebhookControlador::pago()`. No second webhook route is created.
- `WompiAdapter::crearCobro()` gains a 5th optional parameter (`?string $redirectUrl = null`) — must not change behavior for any existing caller that omits it (the appointment-payment flow).
- Buyer accounts (`compradores`) are completely separate from staff accounts (`usuarios`): own password hashing call site, own session table, own cookie name, no 2FA, no roles/permissions.
- Passwords: `password_hash($password, PASSWORD_ARGON2ID)`, verified with the same timing-safe dummy-hash pattern `UsuarioRepo::verificarPassword()` already uses (never let response time reveal whether an email exists).
- All one-time tokens (buyer sessions, registration/reset links) are 256-bit random (`bin2hex(random_bytes(32))`), stored only as their SHA-256 hash — the plaintext token exists only in the cookie or the emailed URL, never in the database.
- `numero_documento` is encrypted at rest via `App\Soporte\Cifrado::cifrar()` (ADR-011 format) — never selected into any template, never logged, never returned from any repo method other than the one that will eventually feed the certificate generator (out of this plan's scope).
- CSRF: every new public POST route validates via `App\Core\Csrf` exactly like the panel's login form already does (cookie-based double-submit) — these routes have no central dispatcher validating it for them, unlike `/panel/*`.
- Escaped output in every new template goes through `App\Soporte\Vista::e()`.
- Migration is `db/migraciones/0030_cobro_y_cuentas_comprador.sql` (next after `0029`), additive only (ADR-013).
- `MotorWa::conectar($bd, $cifrado, $logger, $raiz)` is idempotent (safe to call from multiple places) and is the only way to get a working `$db` for `WaConfig::cargar()` — plain `App\Core\BD` cannot be passed to `WaConfig` directly.

---

### Task 1: Migration — schema + `WompiAdapter` redirect_url parameter

**Files:**
- Create: `db/migraciones/0030_cobro_y_cuentas_comprador.sql`
- Modify: `packages/whatsapp-engine/src/Payments/WompiAdapter.php`
- Modify: `tests/CasoBaseBd.php` (add new tables to the per-test cleanup list)

**Interfaces:**
- Produces: tables `compradores`, `compras_curso`, `compradores_sesiones`, `compradores_enlaces`; `WompiAdapter::crearCobro(float $monto, string $referencia, string $descripcion, array $cliente = [], ?string $redirectUrl = null): array` (5th param optional, default preserves current behavior).

- [ ] **Step 1: Write the migration**

```sql
-- =====================================================================
-- 0030 — Cobro de cursos con Wompi y cuenta de comprador (sub-proyectos 2a+2b)
--
-- Migración aditiva (ADR-013). Implementa
-- docs/superpowers/specs/2026-08-26-cobro-y-cuenta-comprador-design.md.
-- Orden de creación importa por las llaves foráneas: compradores antes de
-- compras_curso (que la referencia), compras_curso antes de
-- compradores_enlaces (que referencia ambas).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS compradores (
  id                        CHAR(36)     NOT NULL DEFAULT (UUID()),
  nombres                   VARCHAR(150) NOT NULL,
  apellidos                 VARCHAR(150) NOT NULL,
  tipo_documento            ENUM('CC','CE','PASAPORTE','NIT') NOT NULL,
  numero_documento_cifrado  VARBINARY(255) NOT NULL,
  celular                   VARCHAR(20)  NOT NULL,
  correo                    VARCHAR(180) NOT NULL,
  password_hash             VARCHAR(255) NOT NULL,
  creado_en                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_compradores_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS compras_curso (
  id                CHAR(36)     NOT NULL DEFAULT (UUID()),
  curso_id          CHAR(36)     NOT NULL,
  comprador_id      CHAR(36)     NULL,
  nombre            VARCHAR(150) NOT NULL,
  correo            VARCHAR(180) NOT NULL,
  precio_cop        BIGINT       NOT NULL,
  referencia_wompi  VARCHAR(120) NULL,
  externo_id        VARCHAR(120) NULL,
  estado            ENUM('pendiente','pagada','fallida') NOT NULL DEFAULT 'pendiente',
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagado_en         DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_compras_curso (curso_id, estado),
  KEY ix_compras_referencia (referencia_wompi),
  CONSTRAINT fk_compras_curso FOREIGN KEY (curso_id) REFERENCES cursos(id),
  CONSTRAINT fk_compras_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS compradores_sesiones (
  id            CHAR(36)     NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)     NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  ip            VARCHAR(45)  NULL,
  user_agent    VARCHAR(500) NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME     NOT NULL,
  revocada_en   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_sesionescomp_token (token_hash),
  CONSTRAINT fk_sesionescomp_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS compradores_enlaces (
  id            CHAR(36)     NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)     NULL,
  compra_id     CHAR(36)     NULL,
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

- [ ] **Step 2: Add the `redirect_url` parameter to `WompiAdapter::crearCobro()`**

In `packages/whatsapp-engine/src/Payments/WompiAdapter.php`, find:

```php
    public function crearCobro(float $monto, string $referencia, string $descripcion, array $cliente = []): array
    {
        $out = ['ok' => false, 'enlace' => '', 'referencia' => $referencia,
                'estado' => 'PAYMENT_PENDING', 'error' => ''];
```

Replace with:

```php
    public function crearCobro(float $monto, string $referencia, string $descripcion, array $cliente = [], ?string $redirectUrl = null): array
    {
        $out = ['ok' => false, 'enlace' => '', 'referencia' => $referencia,
                'estado' => 'PAYMENT_PENDING', 'error' => ''];
```

Then find:

```php
        $cuerpo = [
            'name'                    => mb_substr($descripcion, 0, 60),
            'description'             => mb_substr($descripcion, 0, 200),
            'single_use'              => true,
            'collect_shipping'        => false,
            'currency'                => 'COP',
            'amount_in_cents'         => $centavos,
            'reference'               => $referencia,
        ];
```

Replace with:

```php
        $cuerpo = [
            'name'                    => mb_substr($descripcion, 0, 60),
            'description'             => mb_substr($descripcion, 0, 200),
            'single_use'              => true,
            'collect_shipping'        => false,
            'currency'                => 'COP',
            'amount_in_cents'         => $centavos,
            'reference'               => $referencia,
        ];
        if ($redirectUrl !== null && $redirectUrl !== '') {
            $cuerpo['redirect_url'] = $redirectUrl;
        }
```

No other call site passes a 5th argument today, so the appointment-payment flow's behavior is unchanged.

- [ ] **Step 3: Add the new tables to the test cleanup list**

In `tests/CasoBaseBd.php`, find the `limpiar()` method's truncate array (already ends with the courses-module tables from a previous plan):

```php
            'prompts', 'curso_lecciones', 'curso_modulos', 'cursos', 'categorias_curso',
        ] as $tabla) {
```

Replace with:

```php
            'prompts', 'curso_lecciones', 'curso_modulos', 'cursos', 'categorias_curso',
            'compradores_enlaces', 'compradores_sesiones', 'compras_curso', 'compradores',
        ] as $tabla) {
```

- [ ] **Step 4: Verify the migration applies cleanly**

Run: `vendor/bin/phpunit tests/Integracion/MigracionesTest.php`
Expected: PASS

- [ ] **Step 5: Verify `WompiAdapter` still parses and its existing behavior is untouched**

Run: `php -l packages/whatsapp-engine/src/Payments/WompiAdapter.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add db/migraciones/0030_cobro_y_cuentas_comprador.sql \
        packages/whatsapp-engine/src/Payments/WompiAdapter.php \
        tests/CasoBaseBd.php
git commit -m "$(cat <<'EOF'
feat(cursos): esquema de cobro y cuentas de comprador, WompiAdapter admite redirect_url

Migracion aditiva 0030: compradores, compras_curso, compradores_sesiones,
compradores_enlaces. WompiAdapter::crearCobro() gana un 5o parametro
opcional (redirect_url) que no cambia el comportamiento del cobro de
citas, que no lo pasa.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `Comprador` model + `CompradorRepo`

**Files:**
- Create: `src/Modelos/Comprador.php`
- Create: `src/Repositorios/CompradorRepo.php`
- Test: `tests/Integracion/CompradorRepoTest.php`

**Interfaces:**
- Produces: `final readonly class App\Modelos\Comprador` (`id, nombres, apellidos, tipoDocumento, celular, correo`, plus `nombreCompleto(): string`); `final class App\Repositorios\CompradorRepo` with `__construct(BD $bd, Cifrado $cifrado)`, `porCorreo(string $correo): ?Comprador`, `porId(string $id): ?Comprador`, `existeCorreo(string $correo): bool`, `crear(string $nombres, string $apellidos, string $tipoDocumento, string $numeroDocumento, string $celular, string $correo, string $password): string`, `verificarPassword(string $correo, string $password): bool`, `cambiarPassword(string $compradorId, string $password): void`.
- Consumes: `App\Core\BD::pdo()`, `App\Soporte\Cifrado::cifrar(string): string` (existing, ADR-011 format, already used for `usuarios.totp_secret_cifrado`).

The `Comprador` model deliberately has no `numeroDocumento` property — the encrypted value is never decrypted into any object this plan touches. Decryption is out of scope until a future certificate-generation task needs it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CompradorRepoTest extends CasoBaseBd
{
    private CompradorRepo $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
    }

    #[Test]
    public function crearYBuscarPorCorreo(): void
    {
        $id = $this->repo->crear(
            'Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana@ejemplo.com', 'claveSegura123',
        );

        $comprador = $this->repo->porCorreo('ana@ejemplo.com');

        self::assertNotNull($comprador);
        self::assertSame($id, $comprador->id);
        self::assertSame('Ana Gómez', $comprador->nombreCompleto());
    }

    #[Test]
    public function elDocumentoQuedaCifradoEnLaBaseNuncaEnClaro(): void
    {
        $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana2@ejemplo.com', 'claveSegura123');

        $crudo = (string) $this->bd->pdo()
            ->query("SELECT numero_documento_cifrado FROM compradores WHERE correo = 'ana2@ejemplo.com'")
            ->fetchColumn();

        self::assertStringNotContainsString('1010101010', $crudo);
    }

    #[Test]
    public function verificarPasswordFuncionaYRechazaClaveIncorrecta(): void
    {
        $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana3@ejemplo.com', 'claveSegura123');

        self::assertTrue($this->repo->verificarPassword('ana3@ejemplo.com', 'claveSegura123'));
        self::assertFalse($this->repo->verificarPassword('ana3@ejemplo.com', 'claveMala'));
        self::assertFalse($this->repo->verificarPassword('no-existe@ejemplo.com', 'cualquiera'));
    }

    #[Test]
    public function cambiarPasswordActualizaElHash(): void
    {
        $id = $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana4@ejemplo.com', 'claveVieja');

        $this->repo->cambiarPassword($id, 'claveNueva');

        self::assertFalse($this->repo->verificarPassword('ana4@ejemplo.com', 'claveVieja'));
        self::assertTrue($this->repo->verificarPassword('ana4@ejemplo.com', 'claveNueva'));
    }

    #[Test]
    public function existeCorreoDistingueRegistradoDeNoRegistrado(): void
    {
        $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana5@ejemplo.com', 'clave123');

        self::assertTrue($this->repo->existeCorreo('ana5@ejemplo.com'));
        self::assertFalse($this->repo->existeCorreo('nadie@ejemplo.com'));
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/CompradorRepoTest.php`
Expected: FAIL — `Class "App\Repositorios\CompradorRepo" not found`

- [ ] **Step 3: Write `src/Modelos/Comprador.php`**

```php
<?php

declare(strict_types=1);

namespace App\Modelos;

final readonly class Comprador
{
    public function __construct(
        public string $id,
        public string $nombres,
        public string $apellidos,
        public string $tipoDocumento,
        public string $celular,
        public string $correo,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (string) $fila['id'],
            nombres: (string) $fila['nombres'],
            apellidos: (string) $fila['apellidos'],
            tipoDocumento: (string) $fila['tipo_documento'],
            celular: (string) $fila['celular'],
            correo: (string) $fila['correo'],
        );
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres . ' ' . $this->apellidos);
    }
}
```

- [ ] **Step 4: Write `src/Repositorios/CompradorRepo.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;
use App\Modelos\Comprador;
use App\Soporte\Cifrado;

/**
 * Todo el SQL de `compradores` vive aquí y solo aquí, mismo criterio que
 * `UsuarioRepo` para `usuarios` — pero esta es una tabla completamente
 * separada: un comprador no es staff, no tiene rol ni 2FA.
 */
final class CompradorRepo
{
    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
    ) {
    }

    public function porCorreo(string $correo): ?Comprador
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM compradores WHERE correo = ?');
        $stmt->execute([$correo]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Comprador::desdeFila($fila);
    }

    public function porId(string $id): ?Comprador
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM compradores WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Comprador::desdeFila($fila);
    }

    public function existeCorreo(string $correo): bool
    {
        $stmt = $this->bd->pdo()->prepare('SELECT 1 FROM compradores WHERE correo = ?');
        $stmt->execute([$correo]);

        return $stmt->fetch() !== false;
    }

    public function crear(
        string $nombres,
        string $apellidos,
        string $tipoDocumento,
        string $numeroDocumento,
        string $celular,
        string $correo,
        string $password,
    ): string {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        if ($hash === false) {
            throw new \RuntimeException('No se pudo generar el hash Argon2id.');
        }

        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO compradores
                (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $nombres, $apellidos, $tipoDocumento,
            $this->cifrado->cifrar($numeroDocumento), $celular, $correo, $hash,
        ]);

        return $id;
    }

    /**
     * Mismo cuidado contra enumeración que `UsuarioRepo::verificarPassword()`:
     * cuando el correo no existe, se gasta igual el tiempo de un
     * `password_verify` contra un hash de descarte.
     */
    public function verificarPassword(string $correo, string $password): bool
    {
        $stmt = $this->bd->pdo()->prepare('SELECT password_hash FROM compradores WHERE correo = ?');
        $stmt->execute([$correo]);
        $hash = $stmt->fetchColumn();

        if ($hash === false) {
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$'
                . 'ZGVzY2FydGVkZXNjYXJ0ZQ$0000000000000000000000000000000000000000000');

            return false;
        }

        return password_verify($password, (string) $hash);
    }

    public function cambiarPassword(string $compradorId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        if ($hash === false) {
            throw new \RuntimeException('No se pudo generar el hash Argon2id.');
        }

        $this->bd->pdo()->prepare('UPDATE compradores SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $compradorId]);
    }
}
```

- [ ] **Step 5: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/CompradorRepoTest.php`
Expected: PASS (5/5)

- [ ] **Step 6: Commit**

```bash
git add src/Modelos/Comprador.php src/Repositorios/CompradorRepo.php tests/Integracion/CompradorRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): modelo Comprador y CompradorRepo, documento cifrado ADR-011

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `CompradorSesionRepo`

**Files:**
- Create: `src/Repositorios/CompradorSesionRepo.php`
- Test: `tests/Integracion/CompradorSesionRepoTest.php`

**Interfaces:**
- Produces: `final class App\Repositorios\CompradorSesionRepo` with `__construct(BD $bd)`, `crear(string $compradorId, int $duracionMinutos, ?string $ip, ?string $userAgent): string` (returns the plaintext token), `vigente(string $token): ?array` (`{id:string, comprador_id:string}`), `revocar(string $token): void`, `revocarTodas(string $compradorId): int`.
- Consumes: nothing beyond `App\Core\BD`.

This is a line-for-line mirror of `src/Repositorios/SesionRepo.php`, retargeted at `compradores_sesiones`/`comprador_id`. Read that file if anything here is unclear — the hashing and expiry logic is identical, just a different table.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CompradorSesionRepoTest extends CasoBaseBd
{
    private CompradorSesionRepo $sesiones;
    private CompradorRepo $compradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sesiones = new CompradorSesionRepo($this->bd);
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
    }

    private function comprador(): string
    {
        return $this->compradores->crear(
            'Ana', 'Gómez', 'CC', '1010101010', '3001234567',
            'sesion' . bin2hex(random_bytes(4)) . '@ejemplo.com', 'clave123',
        );
    }

    #[Test]
    public function crearYConsultarUnaSesionVigente(): void
    {
        $compradorId = $this->comprador();
        $token = $this->sesiones->crear($compradorId, 60, '190.85.1.1', 'PHPUnit');

        $vigente = $this->sesiones->vigente($token);

        self::assertNotNull($vigente);
        self::assertSame($compradorId, $vigente['comprador_id']);
    }

    #[Test]
    public function unTokenQueNoExisteNoEstaVigente(): void
    {
        self::assertNull($this->sesiones->vigente(bin2hex(random_bytes(32))));
    }

    #[Test]
    public function revocarInvalidaElToken(): void
    {
        $compradorId = $this->comprador();
        $token = $this->sesiones->crear($compradorId, 60, null, null);

        $this->sesiones->revocar($token);

        self::assertNull($this->sesiones->vigente($token));
    }

    #[Test]
    public function revocarTodasInvalidaTodasLasSesionesDelComprador(): void
    {
        $compradorId = $this->comprador();
        $t1 = $this->sesiones->crear($compradorId, 60, null, null);
        $t2 = $this->sesiones->crear($compradorId, 60, null, null);

        $revocadas = $this->sesiones->revocarTodas($compradorId);

        self::assertSame(2, $revocadas);
        self::assertNull($this->sesiones->vigente($t1));
        self::assertNull($this->sesiones->vigente($t2));
    }

    #[Test]
    public function elTokenEnClaroNuncaQuedaGuardadoEnLaBase(): void
    {
        $compradorId = $this->comprador();
        $token = $this->sesiones->crear($compradorId, 60, null, null);

        $filas = $this->bd->pdo()->query('SELECT token_hash FROM compradores_sesiones')->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($filas as $hash) {
            self::assertNotSame($token, $hash);
        }
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/CompradorSesionRepoTest.php`
Expected: FAIL — `Class "App\Repositorios\CompradorSesionRepo" not found`

- [ ] **Step 3: Write `src/Repositorios/CompradorSesionRepo.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

/**
 * Sesiones de comprador, con hash del token — mismo criterio que
 * `SesionRepo` para el panel, tabla y cookie completamente separadas.
 */
final class CompradorSesionRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    /** @return string el token EN CLARO, que solo se ve aquí y en la cookie */
    public function crear(string $compradorId, int $duracionMinutos, ?string $ip, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));

        $this->bd->pdo()->prepare(
            'INSERT INTO compradores_sesiones (comprador_id, token_hash, ip, user_agent, expira_en)
             VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))'
        )->execute([
            $compradorId,
            hash('sha256', $token),
            $ip,
            $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            $duracionMinutos,
        ]);

        return $token;
    }

    /** @return array{id:string,comprador_id:string}|null */
    public function vigente(string $token): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, comprador_id FROM compradores_sesiones
              WHERE token_hash = ? AND revocada_en IS NULL AND expira_en > UTC_TIMESTAMP()'
        );
        $stmt->execute([hash('sha256', $token)]);
        $fila = $stmt->fetch();

        return $fila === false ? null : ['id' => (string) $fila['id'], 'comprador_id' => (string) $fila['comprador_id']];
    }

    public function revocar(string $token): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE compradores_sesiones SET revocada_en = UTC_TIMESTAMP() WHERE token_hash = ? AND revocada_en IS NULL'
        )->execute([hash('sha256', $token)]);
    }

    public function revocarTodas(string $compradorId): int
    {
        $stmt = $this->bd->pdo()->prepare(
            'UPDATE compradores_sesiones SET revocada_en = UTC_TIMESTAMP()
              WHERE comprador_id = ? AND revocada_en IS NULL'
        );
        $stmt->execute([$compradorId]);

        return $stmt->rowCount();
    }
}
```

- [ ] **Step 4: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/CompradorSesionRepoTest.php`
Expected: PASS (5/5)

- [ ] **Step 5: Commit**

```bash
git add src/Repositorios/CompradorSesionRepo.php tests/Integracion/CompradorSesionRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CompradorSesionRepo, sesiones de comprador separadas del panel

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `CompradorEnlaceRepo` — enlaces de un solo uso

**Files:**
- Create: `src/Repositorios/CompradorEnlaceRepo.php`
- Test: `tests/Integracion/CompradorEnlaceRepoTest.php`

**Interfaces:**
- Produces: `final class App\Repositorios\CompradorEnlaceRepo` with `__construct(BD $bd)`, `crear(string $tipo, ?string $compradorId, ?string $compraId, int $minutosVigencia): string` (returns plaintext token; `$tipo` is `'completar_registro'` or `'reset_password'`), `vigente(string $token, string $tipo): ?array` (`{id:string, comprador_id:?string, compra_id:?string}`, only returns unused+unexpired rows), `marcarUsado(string $id): void`.
- Consumes: nothing beyond `App\Core\BD`.

Both enlace types (post-payment registration link, password-reset link) share this one table and repo — same lifecycle (created, used once, expires), only the `tipo` and which of `comprador_id`/`compra_id` is populated differ.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompradorEnlaceRepo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CompradorEnlaceRepoTest extends CasoBaseBd
{
    private CompradorEnlaceRepo $enlaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enlaces = new CompradorEnlaceRepo($this->bd);
    }

    #[Test]
    public function crearYConsultarUnEnlaceVigente(): void
    {
        $token = $this->enlaces->crear('completar_registro', null, null, 60);

        $fila = $this->enlaces->vigente($token, 'completar_registro');

        self::assertNotNull($fila);
    }

    #[Test]
    public function unEnlaceDelTipoEquivocadoNoSirve(): void
    {
        $token = $this->enlaces->crear('completar_registro', null, null, 60);

        self::assertNull($this->enlaces->vigente($token, 'reset_password'));
    }

    #[Test]
    public function unEnlaceUsadoNoVuelveAServir(): void
    {
        $token = $this->enlaces->crear('reset_password', null, null, 60);
        $fila = $this->enlaces->vigente($token, 'reset_password');

        $this->enlaces->marcarUsado($fila['id']);

        self::assertNull($this->enlaces->vigente($token, 'reset_password'));
    }

    #[Test]
    public function unEnlaceVencidoNoSirve(): void
    {
        $token = $this->enlaces->crear('reset_password', null, null, -1);

        self::assertNull($this->enlaces->vigente($token, 'reset_password'));
    }

    #[Test]
    public function unTokenInventadoNoSirve(): void
    {
        self::assertNull($this->enlaces->vigente(bin2hex(random_bytes(32)), 'completar_registro'));
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/CompradorEnlaceRepoTest.php`
Expected: FAIL — `Class "App\Repositorios\CompradorEnlaceRepo" not found`

- [ ] **Step 3: Write `src/Repositorios/CompradorEnlaceRepo.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

/**
 * Enlaces de un solo uso para compradores: completar registro tras pagar,
 * y recuperar contraseña. Una sola tabla porque el ciclo de vida es
 * idéntico — se crean, se usan una vez, expiran.
 */
final class CompradorEnlaceRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    /** @return string el token EN CLARO, que solo se ve aquí y en el correo */
    public function crear(string $tipo, ?string $compradorId, ?string $compraId, int $minutosVigencia): string
    {
        $token = bin2hex(random_bytes(32));

        $this->bd->pdo()->prepare(
            'INSERT INTO compradores_enlaces (comprador_id, compra_id, tipo, token_hash, expira_en)
             VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))'
        )->execute([$compradorId, $compraId, $tipo, hash('sha256', $token), $minutosVigencia]);

        return $token;
    }

    /** @return array{id:string,comprador_id:?string,compra_id:?string}|null */
    public function vigente(string $token, string $tipo): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, comprador_id, compra_id FROM compradores_enlaces
              WHERE token_hash = ? AND tipo = ? AND usado = 0 AND expira_en > UTC_TIMESTAMP()'
        );
        $stmt->execute([hash('sha256', $token), $tipo]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            return null;
        }

        return [
            'id' => (string) $fila['id'],
            'comprador_id' => $fila['comprador_id'] !== null ? (string) $fila['comprador_id'] : null,
            'compra_id' => $fila['compra_id'] !== null ? (string) $fila['compra_id'] : null,
        ];
    }

    public function marcarUsado(string $id): void
    {
        $this->bd->pdo()->prepare('UPDATE compradores_enlaces SET usado = 1 WHERE id = ?')->execute([$id]);
    }
}
```

- [ ] **Step 4: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/CompradorEnlaceRepoTest.php`
Expected: PASS (5/5)

- [ ] **Step 5: Commit**

```bash
git add src/Repositorios/CompradorEnlaceRepo.php tests/Integracion/CompradorEnlaceRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CompradorEnlaceRepo - enlaces de un solo uso para registro y reset

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `AutenticacionComprador` service

**Files:**
- Create: `src/Servicios/AutenticacionComprador.php`
- Test: `tests/Integracion/AutenticacionCompradorTest.php`

**Interfaces:**
- Produces: `final class App\Servicios\AutenticacionComprador` with `__construct(CompradorRepo $compradores, CompradorSesionRepo $sesiones, IntentoAccesoRepo $intentos, int $duracionMinutos = 43200)`, `verificarCredenciales(string $correo, string $password, ?string $ip): array{ok:bool, motivo?:string, comprador?:Comprador}`, `abrirSesion(Comprador $comprador, ?string $ip, ?string $userAgent): string`, `cerrarSesion(string $token): void`, `compradorDeSesion(string $token): ?Comprador`.
- Consumes: `CompradorRepo` (Task 2), `CompradorSesionRepo` (Task 3), `App\Repositorios\IntentoAccesoRepo` (existing — reused as-is, passing `'login_comprador'` as its generic `$accion` string instead of `'login'`).

`43200` minutes = 30 days: a buyer session can be long-lived because it carries no privilege beyond "see your own purchases" — unlike the panel, which needs 2FA and a 2-hour session because a compromised account there can touch client case data and credentials.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class AutenticacionCompradorTest extends CasoBaseBd
{
    private AutenticacionComprador $auth;
    private CompradorRepo $compradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->auth = new AutenticacionComprador(
            $this->compradores,
            new CompradorSesionRepo($this->bd),
            new IntentoAccesoRepo($this->bd),
        );
    }

    private function crearComprador(string $correo, string $password): void
    {
        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', $correo, $password);
    }

    #[Test]
    public function credencialesCorrectasDevuelvenElComprador(): void
    {
        $this->crearComprador('ok@ejemplo.com', 'claveSegura123');

        $r = $this->auth->verificarCredenciales('ok@ejemplo.com', 'claveSegura123', '190.85.1.1');

        self::assertTrue($r['ok']);
        self::assertSame('ok@ejemplo.com', $r['comprador']->correo);
    }

    #[Test]
    public function claveIncorrectaFallaConMensajeGenerico(): void
    {
        $this->crearComprador('mal@ejemplo.com', 'claveSegura123');

        $r = $this->auth->verificarCredenciales('mal@ejemplo.com', 'claveEquivocada', '190.85.1.1');

        self::assertFalse($r['ok']);
        self::assertSame('Credenciales incorrectas.', $r['motivo']);
    }

    #[Test]
    public function unCorreoQueNoExisteFallaConElMismoMensajeGenerico(): void
    {
        // Mismo mensaje que clave incorrecta: no se puede distinguir "no
        // existe" de "existe con clave mala" desde afuera.
        $r = $this->auth->verificarCredenciales('nadie@ejemplo.com', 'cualquiera', '190.85.1.1');

        self::assertFalse($r['ok']);
        self::assertSame('Credenciales incorrectas.', $r['motivo']);
    }

    #[Test]
    public function abrirYConsultarUnaSesion(): void
    {
        $this->crearComprador('sesion@ejemplo.com', 'clave123');
        $comprador = $this->compradores->porCorreo('sesion@ejemplo.com');

        $token = $this->auth->abrirSesion($comprador, '190.85.1.1', 'PHPUnit');

        $recuperado = $this->auth->compradorDeSesion($token);
        self::assertNotNull($recuperado);
        self::assertSame($comprador->id, $recuperado->id);
    }

    #[Test]
    public function cerrarSesionInvalidaElToken(): void
    {
        $this->crearComprador('salir@ejemplo.com', 'clave123');
        $comprador = $this->compradores->porCorreo('salir@ejemplo.com');
        $token = $this->auth->abrirSesion($comprador, null, null);

        $this->auth->cerrarSesion($token);

        self::assertNull($this->auth->compradorDeSesion($token));
    }

    #[Test]
    public function demasiadosIntentosDesdeUnaIpBloqueaTemporalmente(): void
    {
        $this->crearComprador('rate@ejemplo.com', 'claveBuena');

        for ($i = 0; $i < 20; $i++) {
            $this->auth->verificarCredenciales('rate@ejemplo.com', 'claveMala', '190.85.9.9');
        }

        $r = $this->auth->verificarCredenciales('rate@ejemplo.com', 'claveBuena', '190.85.9.9');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Demasiados intentos', $r['motivo']);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/AutenticacionCompradorTest.php`
Expected: FAIL — `Class "App\Servicios\AutenticacionComprador" not found`

- [ ] **Step 3: Write `src/Servicios/AutenticacionComprador.php`**

```php
<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\Comprador;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;

/**
 * Entrada de comprador — hermana de `Autenticacion` (el panel), pero sin
 * TOTP ni roles: un comprador solo necesita probar que es dueño de su
 * correo y su clave. Mismo cuidado contra fuerza bruta y enumeración.
 */
final class AutenticacionComprador
{
    /** Fallos por IP en 15 minutos antes de cortar. */
    private const TOPE_POR_IP = 20;

    public function __construct(
        private readonly CompradorRepo $compradores,
        private readonly CompradorSesionRepo $sesiones,
        private readonly IntentoAccesoRepo $intentos,
        private readonly int $duracionMinutos = 43_200,
    ) {
    }

    /** @return array{ok:bool,motivo?:string,comprador?:Comprador} */
    public function verificarCredenciales(string $correo, string $password, ?string $ip): array
    {
        $correo = mb_strtolower(trim($correo));

        if ($this->intentos->fallosRecientes('login_comprador', $ip) >= self::TOPE_POR_IP) {
            $this->intentos->registrar('login_comprador', $ip, false, $correo);

            return ['ok' => false, 'motivo' => 'Demasiados intentos desde esta conexión. Espere unos minutos.'];
        }

        if (!$this->compradores->verificarPassword($correo, $password)) {
            $this->intentos->registrar('login_comprador', $ip, false, $correo);

            return ['ok' => false, 'motivo' => 'Credenciales incorrectas.'];
        }

        $comprador = $this->compradores->porCorreo($correo);

        if ($comprador === null) {
            return ['ok' => false, 'motivo' => 'Credenciales incorrectas.'];
        }

        $this->intentos->registrar('login_comprador', $ip, true, $correo);

        return ['ok' => true, 'comprador' => $comprador];
    }

    /** @return string token de sesión en claro, para la cookie */
    public function abrirSesion(Comprador $comprador, ?string $ip, ?string $userAgent): string
    {
        return $this->sesiones->crear($comprador->id, $this->duracionMinutos, $ip, $userAgent);
    }

    public function cerrarSesion(string $token): void
    {
        $this->sesiones->revocar($token);
    }

    public function compradorDeSesion(string $token): ?Comprador
    {
        $sesion = $this->sesiones->vigente($token);

        if ($sesion === null) {
            return null;
        }

        return $this->compradores->porId($sesion['comprador_id']);
    }
}
```

- [ ] **Step 4: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/AutenticacionCompradorTest.php`
Expected: PASS (7/7)

- [ ] **Step 5: Commit**

```bash
git add src/Servicios/AutenticacionComprador.php tests/Integracion/AutenticacionCompradorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): AutenticacionComprador - login/sesion sin TOTP ni roles

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: `App\Wa\ConexionCompartida` — puente hacia Wompi y el aviso de WhatsApp

**Files:**
- Create: `src/Wa/ConexionCompartida.php`
- Test: `tests/Integracion/ConexionCompartidaTest.php`

**Interfaces:**
- Produces: `final class App\Wa\ConexionCompartida` with `__construct(BD $bd, Cifrado $cifrado, Logger $log, string $raiz)`, `wompi(): ?\ElkinLinan\WhatsappAiEngine\Payments\WompiAdapter`, `avisarPedro(string $mensaje): void`.
- Consumes: `App\Wa\MotorWa::conectar()` (existing), `ElkinLinan\WhatsappAiEngine\Core\WaConfig::cargar()`/`secreto()` (existing), `ElkinLinan\WhatsappAiEngine\Payments\WompiAdapter::desdeConfig()` (existing), `ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient` (existing).

This class exists so the checkout flow (Task 8) and the webhook extension (Task 9) share one place that knows how to reach into `wa_config` — instead of each duplicating the `MotorWa::conectar()` + `WaConfig::cargar()` dance. `MotorWa::conectar()` is idempotent (a static flag guards `Engine::arrancar()`), so calling it from multiple request paths is safe.

`avisarPedro()` degrades silently (logs a warning, does nothing else) when `wa_config` has no `handoff_numero` or `evolution_url` — same posture as `App\Soporte\Smtp::desdeEntorno()` returning `null` when SMTP isn't configured: a missing notification channel is not a fatal error for the buyer's checkout.

Note on testing: this codebase has no existing tests exercising `WompiAdapter` or `EvolutionClient` directly (they make real HTTP calls) — this task follows that same precedent. It tests `wompi()`'s two branches (present/absent credentials) without ever calling a network method on the returned adapter, and tests `avisarPedro()`'s graceful-skip path. It does not test an actual successful WhatsApp send, which would require a live Evolution instance.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Soporte\Cifrado;
use App\Soporte\Logger;
use App\Wa\ConexionCompartida;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class ConexionCompartidaTest extends CasoBaseBd
{
    private ConexionCompartida $conexion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conexion = new ConexionCompartida(
            $this->bd,
            Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pa-conexion.log', 'error'),
            dirname(__DIR__, 2),
        );
    }

    #[Test]
    public function sinCredencialesDeWompiWompiDevuelveNull(): void
    {
        // La semilla de 0016 deja wa_config sin wompi_public_key.
        self::assertNull($this->conexion->wompi());
    }

    #[Test]
    public function conLlavePublicaConfiguradaWompiDevuelveUnAdaptador(): void
    {
        $this->bd->pdo()->exec("UPDATE wa_config SET wompi_public_key = 'pub_test_ejemplo' WHERE id = 1");

        self::assertNotNull($this->conexion->wompi());
    }

    #[Test]
    public function avisarPedroNoTruenaSinConfiguracionDeEvolution(): void
    {
        // La semilla de 0016 deja evolution_url en NULL. No debe lanzar
        // excepción ni intentar una petición HTTP real.
        $this->conexion->avisarPedro('Prueba de aviso');

        self::assertTrue(true);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/ConexionCompartidaTest.php`
Expected: FAIL — `Class "App\Wa\ConexionCompartida" not found`

- [ ] **Step 3: Write `src/Wa/ConexionCompartida.php`**

```php
<?php

declare(strict_types=1);

namespace App\Wa;

use App\Core\BD;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use ElkinLinan\WhatsappAiEngine\Payments\WompiAdapter;

/**
 * Puente hacia lo que ya existe en `wa_config` para el motor de WhatsApp:
 * el cliente de Wompi y el envío de un WhatsApp suelto. Ni cursos ni su
 * checkout dependen de la conversación del motor — solo de estas dos
 * piezas, que ya son independientes de ella (ver el spec, §1).
 */
final class ConexionCompartida
{
    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
        private readonly Logger $log,
        private readonly string $raiz,
    ) {
    }

    public function wompi(): ?WompiAdapter
    {
        $db = MotorWa::conectar($this->bd, $this->cifrado, $this->log, $this->raiz);

        return WompiAdapter::desdeConfig(WaConfig::cargar($db));
    }

    public function avisarPedro(string $mensaje): void
    {
        $db = MotorWa::conectar($this->bd, $this->cifrado, $this->log, $this->raiz);
        $cfg = WaConfig::cargar($db);

        if ($cfg === null || empty($cfg['handoff_numero']) || empty($cfg['evolution_url'])) {
            $this->log->warn('cursos.aviso_no_enviado', ['razon' => 'wa_config sin numero de guardia o sin Evolution configurado']);

            return;
        }

        $evo = new EvolutionClient(
            (string) $cfg['evolution_url'],
            (string) ($cfg['evolution_instancia'] ?? ''),
            WaConfig::secreto($cfg, 'evolution_apikey'),
        );

        $evo->enviarTexto((string) $cfg['handoff_numero'], $mensaje);
    }
}
```

- [ ] **Step 4: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/ConexionCompartidaTest.php`
Expected: PASS (3/3)

- [ ] **Step 5: Commit**

```bash
git add src/Wa/ConexionCompartida.php tests/Integracion/ConexionCompartidaTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): ConexionCompartida - puente hacia WompiAdapter y el aviso de WhatsApp

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: `CompraCursoRepo`

**Files:**
- Create: `src/Repositorios/CompraCursoRepo.php`
- Test: `tests/Integracion/CompraCursoRepoTest.php`

**Interfaces:**
- Produces: `final class App\Repositorios\CompraCursoRepo` with `__construct(BD $bd)`, `crear(string $cursoId, string $nombre, string $correo, int $precioCop): string`, `guardarReferencia(string $id, string $referenciaWompi, ?string $externoId): void`, `marcarFallida(string $id): void`, `marcarPagada(string $id): void`, `porId(string $id): ?array`, `pendientePorReferencia(string $referencia, string $paymentLinkId = ''): ?array`, `vincularComprador(string $id, string $compradorId): void`, `pagadasDeComprador(string $compradorId): array`.
- Consumes: nothing beyond `App\Core\BD`.

**Corrected after reading `WompiAdapter::verificarWebhook()` in full (Task 9's research):** that method already returns `payment_link_id` straight from the webhook payload — the exact stable id, not something to reconstruct by parsing the reference string. `pendientePorReferencia()` takes it as a second, optional parameter and matches it against `compras_curso.externo_id` (which Task 8's checkout already stores from `crearCobro()`'s own `externo_id` — the same payment_link id) when the exact reference doesn't match. This is the real defense against Wompi's documented reference-rotation trap (spec §1) — simpler and more reliable than parsing a prefix out of the reference string.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompraCursoRepo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CompraCursoRepoTest extends CasoBaseBd
{
    private CompraCursoRepo $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CompraCursoRepo($this->bd);
    }

    private function categoria(): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$id, 'Aduanero', 'aduanero']);

        return $id;
    }

    private function curso(): string
    {
        $catId = $this->categoria();
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, 'Curso de prueba', 'curso-de-prueba', 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    #[Test]
    public function crearYConsultarPorId(): void
    {
        $cursoId = $this->curso();

        $id = $this->repo->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
        $fila = $this->repo->porId($id);

        self::assertNotNull($fila);
        self::assertSame('pendiente', $fila['estado']);
        self::assertSame(250000, (int) $fila['precio_cop']);
    }

    #[Test]
    public function pendientePorReferenciaEncuentraPorReferenciaExacta(): void
    {
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->guardarReferencia($id, 'test_ABC123_1787000000_xyz', 'test_ABC123');

        $fila = $this->repo->pendientePorReferencia('test_ABC123_1787000000_xyz');

        self::assertNotNull($fila);
        self::assertSame($id, $fila['id']);
    }

    #[Test]
    public function pendientePorReferenciaCaeAlPaymentLinkIdSiLaReferenciaRoto(): void
    {
        // La trampa documentada en WompiAdapter: la referencia cambia en
        // cada sesión de checkout, pero el payment_link_id que trae el
        // webhook es estable y coincide con el externo_id que guardó el
        // checkout (Task 8).
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->guardarReferencia($id, 'test_ABC123_1787000000_xyz', 'test_ABC123');

        $fila = $this->repo->pendientePorReferencia('test_ABC123_9999999999_otraRandom', 'test_ABC123');

        self::assertNotNull($fila);
        self::assertSame($id, $fila['id']);
    }

    #[Test]
    public function sinPaymentLinkIdUnaReferenciaQueNoCoincideNoEncuentraNada(): void
    {
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->guardarReferencia($id, 'test_ABC123_1787000000_xyz', 'test_ABC123');

        self::assertNull($this->repo->pendientePorReferencia('otra-referencia-cualquiera'));
    }

    #[Test]
    public function unaCompraYaPagadaNoApareceComoPendiente(): void
    {
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->guardarReferencia($id, 'test_XYZ_1787000000_abc', 'test_XYZ');
        $this->repo->marcarPagada($id);

        self::assertNull($this->repo->pendientePorReferencia('test_XYZ_1787000000_abc'));
    }

    #[Test]
    public function vincularCompradorYListarSusComprasPagadas(): void
    {
        $cursoId = $this->curso();
        $id = $this->repo->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->repo->marcarPagada($id);

        $compradorId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO compradores (id, nombres, apellidos, tipo_documento, numero_documento_cifrado, celular, correo, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$compradorId, 'Ana', 'Gómez', 'CC', 'x', '300', 'ana@ejemplo.com', 'hash']);

        $this->repo->vincularComprador($id, $compradorId);

        $compras = $this->repo->pagadasDeComprador($compradorId);

        self::assertCount(1, $compras);
        self::assertSame('Curso de prueba', $compras[0]['titulo']);
    }

    #[Test]
    public function marcarFallidaCambiaElEstado(): void
    {
        $id = $this->repo->crear($this->curso(), 'Ana', 'ana@ejemplo.com', 250000);

        $this->repo->marcarFallida($id);

        self::assertSame('fallida', $this->repo->porId($id)['estado']);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/CompraCursoRepoTest.php`
Expected: FAIL — `Class "App\Repositorios\CompraCursoRepo" not found`

- [ ] **Step 3: Write `src/Repositorios/CompraCursoRepo.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

final class CompraCursoRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    public function crear(string $cursoId, string $nombre, string $correo, int $precioCop): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO compras_curso (id, curso_id, nombre, correo, precio_cop) VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $cursoId, $nombre, $correo, $precioCop]);

        return $id;
    }

    public function guardarReferencia(string $id, string $referenciaWompi, ?string $externoId): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE compras_curso SET referencia_wompi = ?, externo_id = ? WHERE id = ?'
        )->execute([$referenciaWompi, $externoId, $id]);
    }

    public function marcarFallida(string $id): void
    {
        $this->bd->pdo()->prepare("UPDATE compras_curso SET estado = 'fallida' WHERE id = ?")->execute([$id]);
    }

    public function marcarPagada(string $id): void
    {
        $this->bd->pdo()->prepare(
            "UPDATE compras_curso SET estado = 'pagada', pagado_en = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$id]);
    }

    /** @return array<string,mixed>|null */
    public function porId(string $id): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM compras_curso WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Busca una compra pendiente por referencia exacta, y si no aparece —
     * la referencia rota en cada sesión de checkout, trampa documentada en
     * `WompiAdapter` — por `payment_link_id` (que `verificarWebhook()` ya
     * entrega directo del payload) contra `externo_id`, que el checkout
     * (Task 8) guardó con ese mismo valor.
     *
     * @return array<string,mixed>|null
     */
    public function pendientePorReferencia(string $referencia, string $paymentLinkId = ''): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT * FROM compras_curso WHERE referencia_wompi = ? AND estado = 'pendiente'"
        );
        $stmt->execute([$referencia]);
        $fila = $stmt->fetch();

        if ($fila !== false) {
            return $fila;
        }

        if ($paymentLinkId === '') {
            return null;
        }

        $stmt = $this->bd->pdo()->prepare(
            "SELECT * FROM compras_curso WHERE externo_id = ? AND estado = 'pendiente'"
        );
        $stmt->execute([$paymentLinkId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    public function vincularComprador(string $id, string $compradorId): void
    {
        $this->bd->pdo()->prepare('UPDATE compras_curso SET comprador_id = ? WHERE id = ?')
            ->execute([$compradorId, $id]);
    }

    /** @return list<array<string,mixed>> compras pagadas de un comprador, con datos del curso */
    public function pagadasDeComprador(string $compradorId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT cc.*, c.titulo, c.slug FROM compras_curso cc
               JOIN cursos c ON c.id = cc.curso_id
              WHERE cc.comprador_id = ? AND cc.estado = 'pagada'
              ORDER BY cc.pagado_en DESC"
        );
        $stmt->execute([$compradorId]);

        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 4: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/CompraCursoRepoTest.php`
Expected: PASS (7/7)

- [ ] **Step 5: Commit**

```bash
git add src/Repositorios/CompraCursoRepo.php tests/Integracion/CompraCursoRepoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): CompraCursoRepo con la defensa contra la referencia rotada de Wompi

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Checkout público — `App\Cuenta\ComprasControlador`

**Files:**
- Create: `tests/Dobles/PaymentAdapterFalso.php` (shared test double, reused by this task and Task 9)
- Create: `src/Cuenta/ComprasControlador.php`
- Create: `plantillas/cuenta/comprar.php`
- Create: `plantillas/cuenta/gracias.php`
- Modify: `src/Servicios/Cursos.php` (expose a public curso-by-slug lookup)
- Modify: `src/Core/Aplicacion.php` (register routes + container entries)
- Test: `tests/Integracion/ComprasControladorTest.php`

**Interfaces:**
- Produces: `final class App\Cuenta\ComprasControlador` with `__construct(Cursos $cursos, CompraCursoRepo $compras, ?PaymentAdapterInterface $wompi, string $urlBase)`, `formulario(Peticion $peticion, string $slug): Respuesta`, `procesar(Peticion $peticion, string $slug): Respuesta`, `gracias(Peticion $peticion, string $slug): Respuesta`. Also `Cursos::porSlug(string $slug): ?array` (new public method).
- Consumes: `Cursos` (existing, extended here), `CompraCursoRepo` (Task 7), `ElkinLinan\WhatsappAiEngine\Payments\PaymentAdapterInterface` (existing — the controller depends on the interface, not `ConexionCompartida` directly, exactly so tests can inject a fake with no network calls).

**Key design point:** the controller takes `?PaymentAdapterInterface $wompi` — already resolved — instead of `ConexionCompartida`. The route closures in `Aplicacion.php` call `ConexionCompartida::wompi()` once per request and pass the result in. This is what makes the controller trivially testable: construct it directly with `PaymentAdapterFalso`, no `MotorWa::conectar()`, no real HTTP, ever.

Only `estado = 'publicado'` courses are purchasable — a draft course reachable by direct preview link (spec of the catalog sub-project) is not for sale yet.

- [ ] **Step 1: Write the shared test double**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Dobles;

use ElkinLinan\WhatsappAiEngine\Payments\PaymentAdapterInterface;

/**
 * Doble de PaymentAdapterInterface para pruebas: nunca hace peticiones HTTP.
 * Configurar las propiedades públicas antes de usar; leer `llamadasCrearCobro`
 * para verificar qué se le pidió.
 */
final class PaymentAdapterFalso implements PaymentAdapterInterface
{
    /** @var array{ok:bool,enlace:string,referencia:string,estado:string,externo_id?:string,error:string} */
    public array $respuestaCrearCobro = [
        'ok' => true, 'enlace' => 'https://checkout.wompi.co/l/falso123',
        'referencia' => 'wompi_ref_falsa', 'estado' => 'PAYMENT_INITIATED',
        'externo_id' => 'falso123', 'error' => '',
    ];

    /** @var array{ok:bool,estado:string,monto:float,transaccion_id:string,metodo:string,error:string} */
    public array $respuestaConsultar = [
        'ok' => true, 'estado' => 'PAYMENT_INITIATED', 'monto' => 0.0,
        'transaccion_id' => '', 'metodo' => '', 'error' => '',
    ];

    /** @var array{ok:bool,referencia:string,estado:string,monto:float,transaccion_id:string,evento_id:string,payment_link_id:string,error:string} */
    public array $respuestaVerificarWebhook = [
        'ok' => true, 'referencia' => '', 'estado' => 'PAYMENT_VERIFIED', 'monto' => 0.0,
        'transaccion_id' => 'txn_falso', 'evento_id' => 'evt_falso', 'payment_link_id' => '', 'error' => '',
    ];

    /** @var list<array{monto:float,referencia:string,descripcion:string,cliente:array,redirectUrl:?string}> */
    public array $llamadasCrearCobro = [];

    public function nombre(): string
    {
        return 'Falso';
    }

    public function requisitosFaltantes(): array
    {
        return [];
    }

    public function crearCobro(float $monto, string $referencia, string $descripcion, array $cliente = [], ?string $redirectUrl = null): array
    {
        $this->llamadasCrearCobro[] = [
            'monto' => $monto, 'referencia' => $referencia, 'descripcion' => $descripcion,
            'cliente' => $cliente, 'redirectUrl' => $redirectUrl,
        ];

        return $this->respuestaCrearCobro;
    }

    public function consultar(string $referencia): array
    {
        return $this->respuestaConsultar;
    }

    public function verificarWebhook(string $cuerpoCrudo, array $cabeceras): array
    {
        return $this->respuestaVerificarWebhook;
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\ComprasControlador;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\ConfigMysql;
use App\Servicios\Cursos;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;
use Pruebas\Dobles\PaymentAdapterFalso;

final class ComprasControladorTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private Cursos $cursosServicio;
    private CompraCursoRepo $compras;
    private PaymentAdapterFalso $wompi;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-compras-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-compras-cfg-{$sufijo}.json",
        );
        $this->cursosServicio = new Cursos($this->bd, $config, self::URL);
        $this->compras = new CompraCursoRepo($this->bd);
        $this->wompi = new PaymentAdapterFalso();
    }

    private function controlador(?\ElkinLinan\WhatsappAiEngine\Payments\PaymentAdapterInterface $wompi = null): ComprasControlador
    {
        return new ComprasControlador($this->cursosServicio, $this->compras, $wompi ?? $this->wompi, self::URL);
    }

    private function crearCursoPublicado(int $precio = 250000): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);

        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, 'Curso comprable', 'curso-comprable', 'r', 'd', '[]', $precio, 'publicado']);

        return $id;
    }

    private function peticion(string $ruta, array $formulario = [], array $consulta = []): Peticion
    {
        return new Peticion(
            metodo: $formulario === [] ? 'GET' : 'POST',
            ruta: $ruta,
            consulta: $consulta,
            formulario: $formulario,
        );
    }

    #[Test]
    public function unCursoQueNoExisteResponde404EnElFormulario(): void
    {
        $r = $this->controlador()->formulario($this->peticion('/cursos/no-existe/comprar'), 'no-existe');

        self::assertSame(404, $r->estado);
    }

    #[Test]
    public function procesarSinNombreNiCorreoRedirigeConError(): void
    {
        $this->crearCursoPublicado();

        $r = $this->controlador()->procesar(
            $this->peticion('/cursos/curso-comprable/comprar', ['nombre' => '', 'correo' => 'no-es-correo']),
            'curso-comprable',
        );

        self::assertSame(302, $r->estado);
        self::assertStringContainsString('/cursos/curso-comprable/comprar', $r->cabeceras['Location']);
        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM compras_curso')->fetchColumn());
    }

    #[Test]
    public function procesarConDatosValidosCreaLaCompraYRedirigeAWompi(): void
    {
        $this->crearCursoPublicado(250000);

        $r = $this->controlador()->procesar(
            $this->peticion('/cursos/curso-comprable/comprar', ['nombre' => 'Ana Gómez', 'correo' => 'ana@ejemplo.com']),
            'curso-comprable',
        );

        self::assertSame(302, $r->estado);
        self::assertSame('https://checkout.wompi.co/l/falso123', $r->cabeceras['Location']);

        $compra = $this->bd->pdo()->query('SELECT * FROM compras_curso')->fetch();
        self::assertSame('pendiente', $compra['estado']);
        self::assertSame(250000, (int) $compra['precio_cop']);
        self::assertSame('wompi_ref_falsa', $compra['referencia_wompi']);

        // El precio se manda en PESOS, nunca en centavos (ADR-010) — la
        // conversión a centavos vive solo dentro de WompiAdapter.
        self::assertSame(250000.0, $this->wompi->llamadasCrearCobro[0]['monto']);
        self::assertStringContainsString('/cursos/curso-comprable/gracias', $this->wompi->llamadasCrearCobro[0]['redirectUrl']);
    }

    #[Test]
    public function siWompiNoEstaConfiguradoLaCompraQuedaFallidaConError(): void
    {
        $this->crearCursoPublicado();

        $r = $this->controlador(null)->procesar(
            $this->peticion('/cursos/curso-comprable/comprar', ['nombre' => 'Ana', 'correo' => 'ana@ejemplo.com']),
            'curso-comprable',
        );

        self::assertSame(302, $r->estado);
        self::assertSame('fallida', $this->bd->pdo()->query('SELECT estado FROM compras_curso')->fetchColumn());
    }

    #[Test]
    public function siWompiRechazaCrearElCobroLaCompraQuedaFallida(): void
    {
        $this->crearCursoPublicado();
        $this->wompi->respuestaCrearCobro = ['ok' => false, 'enlace' => '', 'referencia' => '', 'estado' => 'ERROR', 'error' => 'boom'];

        $this->controlador()->procesar(
            $this->peticion('/cursos/curso-comprable/comprar', ['nombre' => 'Ana', 'correo' => 'ana@ejemplo.com']),
            'curso-comprable',
        );

        self::assertSame('fallida', $this->bd->pdo()->query('SELECT estado FROM compras_curso')->fetchColumn());
    }

    #[Test]
    public function unCursoEnBorradorNoSePuedeComprar(): void
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$catId, 'Sin publicar', 'sin-publicar', 'r', 'd', '[]', 100000, 'borrador']);

        $r = $this->controlador()->formulario($this->peticion('/cursos/sin-publicar/comprar'), 'sin-publicar');

        self::assertSame(404, $r->estado);
    }

    #[Test]
    public function graciasConsultaElEstadoDeUnaCompraPendienteSoloParaMostrarlo(): void
    {
        $cursoId = $this->crearCursoPublicado();
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->compras->guardarReferencia($compraId, 'ref-1', 'ext-1');
        $this->wompi->respuestaConsultar = ['ok' => true, 'estado' => 'PAYMENT_INITIATED', 'monto' => 0.0, 'transaccion_id' => '', 'metodo' => '', 'error' => ''];

        $r = $this->controlador()->gracias(
            $this->peticion('/cursos/curso-comprable/gracias', [], ['compra' => $compraId]),
            'curso-comprable',
        );

        self::assertSame(200, $r->estado);
        // El estado que se ve viene de consultar(), pero la fila en base
        // sigue en 'pendiente' — la consulta es solo informativa, nunca
        // cambia el estado guardado.
        self::assertSame('pendiente', $this->compras->porId($compraId)['estado']);
    }
}
```

- [ ] **Step 3: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/ComprasControladorTest.php`
Expected: FAIL — `Class "App\Cuenta\ComprasControlador" not found`

- [ ] **Step 4: Expose `Cursos::porSlug()`**

In `src/Servicios/Cursos.php`, find the `catalogo()` method's opening and add a new public method right after it (before the existing `ficha()` method):

```php
    /** @return array<string,mixed>|null datos crudos del curso, sin decodificar lo_que_aprendera */
    public function porSlug(string $slug): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM cursos WHERE slug = ?');
        $stmt->execute([$slug]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }
```

This does not touch `buscarPorSlug()` (private, used by `ficha()`, which additionally decodes `lo_que_aprendera` for rendering) — `porSlug()` is a separate, simpler lookup for callers that just need the raw row (id, precio_cop, estado, titulo).

- [ ] **Step 5: Write `src/Cuenta/ComprasControlador.php`**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\Cursos;
use ElkinLinan\WhatsappAiEngine\Payments\PaymentAdapterInterface;

/**
 * Checkout público de un curso. Depende de `PaymentAdapterInterface`, no de
 * `ConexionCompartida` en concreto — así una prueba inyecta un adaptador
 * falso sin tocar `wa_config` ni la red.
 */
final class ComprasControlador
{
    public function __construct(
        private readonly Cursos $cursos,
        private readonly CompraCursoRepo $compras,
        private readonly ?PaymentAdapterInterface $wompi,
        private readonly string $urlBase,
    ) {
    }

    public function formulario(Peticion $peticion, string $slug): Respuesta
    {
        $curso = $this->cursoComprable($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        return Respuesta::vista('cuenta/comprar', [
            'curso' => $curso,
            'error' => $peticion->consulta['error'] ?? null,
        ]);
    }

    public function procesar(Peticion $peticion, string $slug): Respuesta
    {
        $curso = $this->cursoComprable($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        $nombre = trim((string) ($peticion->formulario['nombre'] ?? ''));
        $correo = trim((string) ($peticion->formulario['correo'] ?? ''));

        if ($nombre === '' || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirigirAlFormulario($slug, 'Escriba su nombre y un correo válido.');
        }

        $compraId = $this->compras->crear($curso['id'], $nombre, $correo, (int) $curso['precio_cop']);

        if ($this->wompi === null) {
            $this->compras->marcarFallida($compraId);

            return $this->redirigirAlFormulario($slug, 'El cobro no está disponible en este momento. Intente más tarde.');
        }

        $redirectUrl = rtrim($this->urlBase, '/') . "/cursos/{$slug}/gracias?compra={$compraId}";

        $resultado = $this->wompi->crearCobro(
            (float) $curso['precio_cop'],
            $compraId,
            'Curso: ' . (string) $curso['titulo'],
            ['nombre' => $nombre],
            $redirectUrl,
        );

        if (!$resultado['ok']) {
            $this->compras->marcarFallida($compraId);

            return $this->redirigirAlFormulario($slug, 'No se pudo generar el cobro. Intente de nuevo.');
        }

        $this->compras->guardarReferencia($compraId, $resultado['referencia'], $resultado['externo_id'] ?? null);

        return new Respuesta('', 302, ['Location' => $resultado['enlace']]);
    }

    public function gracias(Peticion $peticion, string $slug): Respuesta
    {
        $curso = $this->cursos->porSlug($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        $compraId = (string) ($peticion->consulta['compra'] ?? '');
        $compra = $compraId !== '' ? $this->compras->porId($compraId) : null;

        $estadoMostrado = 'desconocido';

        if ($compra !== null) {
            $estadoMostrado = (string) $compra['estado'];

            // Solo informativo: nunca escribe nada. La única fuente de
            // verdad de que un pago ocurrió es el webhook (Task 9).
            if ($estadoMostrado === 'pendiente' && $this->wompi !== null && $compra['referencia_wompi'] !== null) {
                $consulta = $this->wompi->consultar((string) $compra['referencia_wompi']);
                if ($consulta['ok']) {
                    $estadoMostrado = $consulta['estado'];
                }
            }
        }

        return Respuesta::vista('cuenta/gracias', [
            'curso' => $curso,
            'estadoMostrado' => $estadoMostrado,
        ]);
    }

    /** @return array<string,mixed>|null solo cursos publicados son comprables */
    private function cursoComprable(string $slug): ?array
    {
        $curso = $this->cursos->porSlug($slug);

        return ($curso !== null && $curso['estado'] === 'publicado') ? $curso : null;
    }

    private function redirigirAlFormulario(string $slug, string $error): Respuesta
    {
        return new Respuesta('', 302, [
            'Location' => "/cursos/{$slug}/comprar?" . http_build_query(['error' => $error]),
        ]);
    }
}
```

- [ ] **Step 6: Write `plantillas/cuenta/comprar.php`**

```php
<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var ?string $error
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$csrf = new Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
$precio = '$' . number_format((int) $curso['precio_cop'], 0, ',', '.') . ' COP';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprar: <?= $e((string) $curso['titulo']) ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <p class="text-xs uppercase tracking-widest text-acero">Comprar curso</p>
    <h1 class="titular-seccion mt-2"><?= $e((string) $curso['titulo']) ?></h1>
    <p class="mt-4 font-mono text-2xl text-oro"><?= $e($precio) ?></p>

    <?php if ($error !== null): ?>
    <p class="mt-4 rounded border border-alerta/40 bg-alerta/10 p-3 text-sm text-alerta"><?= $e((string) $error) ?></p>
    <?php endif; ?>

    <form method="post" action="/cursos/<?= $e((string) $curso['slug']) ?>/comprar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>

        <label class="text-xs uppercase tracking-widest text-acero">Nombre completo</label>
        <input name="nombre" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Correo</label>
        <input name="correo" type="email" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">
            Pagar <?= $e($precio) ?> con Wompi
        </button>
    </form>
</main>

</body>
</html>
```

- [ ] **Step 7: Write `plantillas/cuenta/gracias.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var array<string,mixed> $curso
 * @var string $estadoMostrado
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';

$mensaje = match ($estadoMostrado) {
    'pagada', 'APPROVED' => 'Pago recibido. En unos minutos le llegará un correo para crear su acceso.',
    'fallida', 'DECLINED', 'ERROR' => 'El pago no se completó. Puede intentarlo de nuevo desde la ficha del curso.',
    default => 'Estamos confirmando su pago. Si ya pagó, en unos minutos le llegará un correo con los siguientes pasos.',
};
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gracias — <?= $e((string) $curso['titulo']) ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-24 text-center md:px-7">
    <h1 class="titular-seccion"><?= $e((string) $curso['titulo']) ?></h1>
    <p class="mt-6 text-acero"><?= $e($mensaje) ?></p>
    <a href="/cursos" class="menu-enlace mt-8 inline-block">Ver más cursos</a>
</main>

</body>
</html>
```

- [ ] **Step 8: Wire routes and container entries in `src/Core/Aplicacion.php`**

Register the new services in the constructor, right after the `Cursos::class` registration added by the previous plan. `Cifrado::class` is already registered (search for `Cifrado::class, static fn` — it's already imported and bound), so reuse it directly, do not register it a second time:

```php
        $this->contenedor->registrar(
            \App\Wa\ConexionCompartida::class,
            static fn (Contenedor $c): \App\Wa\ConexionCompartida => new \App\Wa\ConexionCompartida(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
                $c->obtener(Logger::class),
                $raiz,
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CompraCursoRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CompraCursoRepo => new \App\Repositorios\CompraCursoRepo(
                $c->obtener(BD::class),
            ),
        );
```

Then register the routes, right after the `/cursos/{slug}` route added by the previous plan:

```php
        $this->router->get('/cursos/{slug}/comprar', function (Peticion $p): Respuesta {
            $conexion = $this->contenedor->obtener(\App\Wa\ConexionCompartida::class);

            return (new \App\Cuenta\ComprasControlador(
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $conexion->wompi(),
                $urlBase,
            ))->formulario($p, (string) $p->parametros['slug']);
        });

        $this->router->post('/cursos/{slug}/comprar', function (Peticion $p): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            $conexion = $this->contenedor->obtener(\App\Wa\ConexionCompartida::class);

            return (new \App\Cuenta\ComprasControlador(
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $conexion->wompi(),
                $urlBase,
            ))->procesar($p, (string) $p->parametros['slug']);
        });

        $this->router->get('/cursos/{slug}/gracias', function (Peticion $p): Respuesta {
            $conexion = $this->contenedor->obtener(\App\Wa\ConexionCompartida::class);

            return (new \App\Cuenta\ComprasControlador(
                $this->contenedor->obtener(\App\Servicios\Cursos::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
                $conexion->wompi(),
                $urlBase,
            ))->gracias($p, (string) $p->parametros['slug']);
        });
```

`Entorno` is already imported in `Aplicacion.php` (`use App\Soporte\Entorno;`) and used unqualified elsewhere in the file — the closures above call it the same way. `Csrf` is referenced fully-qualified (`\App\Core\Csrf`) instead of adding a new `use` line, matching how this file already fully-qualifies less-central classes (e.g. `\App\Servicios\Cursos::class` in the routes added by the previous plan).

- [ ] **Step 9: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/ComprasControladorTest.php`
Expected: PASS (7/7)

- [ ] **Step 10: Confirm the app still boots and existing course routes still work**

Run: `vendor/bin/phpunit tests/Integracion/ArranqueTest.php tests/Integracion/CursosTest.php`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add tests/Dobles/PaymentAdapterFalso.php src/Cuenta/ComprasControlador.php \
        plantillas/cuenta/comprar.php plantillas/cuenta/gracias.php \
        src/Servicios/Cursos.php src/Core/Aplicacion.php \
        tests/Integracion/ComprasControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): checkout publico con Wompi, testeable via PaymentAdapterInterface

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Confirmación de compra + extensión del webhook de pago

**Files:**
- Create: `src/Cuenta/ConfirmadorCompra.php`
- Test: `tests/Integracion/ConfirmadorCompraTest.php`
- Modify: `src/Wa/WebhookControlador.php`
- Test: `tests/Integracion/WebhookPagoCursoTest.php`

**Interfaces:**
- Produces: `final class App\Cuenta\ConfirmadorCompra` with `__construct(CompraCursoRepo $compras, CompradorEnlaceRepo $enlaces, ConexionCompartida $conexion, BD $bd, ?Smtp $smtp, string $urlBase)`, `confirmar(string $compraId): void`.
- Consumes: `CompraCursoRepo` (Task 7), `CompradorEnlaceRepo` (Task 4), `ConexionCompartida` (Task 6), `App\Soporte\Smtp` (existing, nullable per `Smtp::desdeEntorno()`).

`ConfirmadorCompra::confirmar()` is the one place that: marks a purchase paid, notifies Pedro by WhatsApp, and emails the buyer a one-time registration/login link. It's called from two places — the webhook (this task) and, later, the panel's manual-approve action — which is exactly why it's its own class instead of inlined in the webhook.

**Idempotency is the whole point of the first two lines:** if `confirmar()` runs twice for the same purchase (a retried webhook, or a manual approve after the webhook already fired), the second call must do nothing — no second WhatsApp, no second email, no second registration link.

- [ ] **Step 1: Write the failing test for `ConfirmadorCompra`**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cuenta\ConfirmadorCompra;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorEnlaceRepo;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use App\Wa\ConexionCompartida;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class ConfirmadorCompraTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private ConfirmadorCompra $confirmador;
    private CompraCursoRepo $compras;
    private CompradorEnlaceRepo $enlaces;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compras = new CompraCursoRepo($this->bd);
        $this->enlaces = new CompradorEnlaceRepo($this->bd);

        $this->confirmador = new ConfirmadorCompra(
            $this->compras,
            $this->enlaces,
            new ConexionCompartida($this->bd, Cifrado::desdeEntorno(), new Logger(sys_get_temp_dir() . '/pa-confirmador.log', 'error'), dirname(__DIR__, 2)),
            $this->bd,
            null, // Smtp: sin SMTP configurado en pruebas, debe degradar sin tronar
            self::URL,
        );
    }

    private function compraPendiente(): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso confirmable', 'curso-confirmable', 'r', 'd', '[]', 250000, 'publicado']);

        return $this->compras->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
    }

    #[Test]
    public function confirmarMarcaLaCompraComoPagada(): void
    {
        $compraId = $this->compraPendiente();

        $this->confirmador->confirmar($compraId);

        self::assertSame('pagada', $this->compras->porId($compraId)['estado']);
    }

    #[Test]
    public function confirmarCreaUnEnlaceDeRegistroLigadoALaCompra(): void
    {
        $compraId = $this->compraPendiente();

        $this->confirmador->confirmar($compraId);

        $fila = $this->bd->pdo()->prepare(
            "SELECT COUNT(*) FROM compradores_enlaces WHERE compra_id = ? AND tipo = 'completar_registro'"
        );
        $fila->execute([$compraId]);
        self::assertSame(1, (int) $fila->fetchColumn());
    }

    #[Test]
    public function confirmarDosVecesNoDuplicaElEnlaceDeRegistro(): void
    {
        $compraId = $this->compraPendiente();

        $this->confirmador->confirmar($compraId);
        $this->confirmador->confirmar($compraId);

        $fila = $this->bd->pdo()->prepare('SELECT COUNT(*) FROM compradores_enlaces WHERE compra_id = ?');
        $fila->execute([$compraId]);
        self::assertSame(1, (int) $fila->fetchColumn());
    }

    #[Test]
    public function confirmarUnaCompraQueNoExisteNoTruena(): void
    {
        $this->confirmador->confirmar((string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn());

        self::assertTrue(true);
    }

    #[Test]
    public function confirmarSinSmtpConfiguradoNoTruena(): void
    {
        // El constructor de este test ya pasa null como $smtp — llegar aquí
        // sin excepción es la prueba.
        $this->confirmador->confirmar($this->compraPendiente());

        self::assertTrue(true);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/ConfirmadorCompraTest.php`
Expected: FAIL — `Class "App\Cuenta\ConfirmadorCompra" not found`

- [ ] **Step 3: Write `src/Cuenta/ConfirmadorCompra.php`**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\BD;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorEnlaceRepo;
use App\Soporte\Smtp;
use App\Wa\ConexionCompartida;

/**
 * Confirma una compra pagada: marca el estado, avisa a Pedro por WhatsApp,
 * y le manda al comprador un enlace de un solo uso para completar su
 * registro (o iniciar sesión, si ya tiene cuenta — eso lo decide la
 * plantilla de `/mis-cursos/completar`, no esta clase).
 *
 * Llamada desde dos sitios: el webhook de Wompi (automático) y el "aprobar
 * a mano" del panel (respaldo si el webhook nunca llega) — por eso es su
 * propia clase y no vive dentro del webhook.
 */
final class ConfirmadorCompra
{
    private const MINUTOS_VIGENCIA_ENLACE = 60 * 48; // 48 horas

    public function __construct(
        private readonly CompraCursoRepo $compras,
        private readonly CompradorEnlaceRepo $enlaces,
        private readonly ConexionCompartida $conexion,
        private readonly BD $bd,
        private readonly ?Smtp $smtp,
        private readonly string $urlBase,
    ) {
    }

    public function confirmar(string $compraId): void
    {
        $compra = $this->compras->porId($compraId);

        // Ya confirmada (webhook duplicado, o aprobación manual después de
        // que el webhook ya llegó): no repetir el aviso ni el enlace.
        if ($compra === null || $compra['estado'] === 'pagada') {
            return;
        }

        $this->compras->marcarPagada($compraId);

        $stmt = $this->bd->pdo()->prepare('SELECT titulo FROM cursos WHERE id = ?');
        $stmt->execute([$compra['curso_id']]);
        $tituloCurso = (string) $stmt->fetchColumn();

        $this->conexion->avisarPedro(sprintf(
            'Nuevo pago de curso: %s (%s) compró "%s".',
            $compra['nombre'],
            $compra['correo'],
            $tituloCurso,
        ));

        $token = $this->enlaces->crear('completar_registro', null, $compraId, self::MINUTOS_VIGENCIA_ENLACE);
        $enlaceUrl = rtrim($this->urlBase, '/') . '/mis-cursos/completar?token=' . $token;

        if ($this->smtp !== null) {
            $this->smtp->enviar(
                (string) $compra['correo'],
                'Su acceso al curso: ' . $tituloCurso,
                "Hola {$compra['nombre']},\n\n"
                    . "Su pago del curso \"{$tituloCurso}\" fue confirmado.\n\n"
                    . "Complete su registro (o inicie sesión si ya tiene cuenta) en este enlace:\n{$enlaceUrl}\n\n"
                    . "Este enlace es válido por 48 horas.\n",
            );
        }
    }
}
```

- [ ] **Step 4: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/ConfirmadorCompraTest.php`
Expected: PASS (5/5)

- [ ] **Step 5: Write the failing webhook-extension test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use App\Wa\WebhookControlador;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class WebhookPagoCursoTest extends CasoBaseBd
{
    private Cifrado $cifrado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cifrado = Cifrado::desdeEntorno();
    }

    /** Configura wa_config con credenciales de Wompi utilizables y un token de webhook conocido. */
    private function configurarWompi(string $eventsSecret): string
    {
        $tokenClaro = bin2hex(random_bytes(32));

        $this->bd->pdo()->prepare(
            "UPDATE wa_config SET
                wompi_public_key = 'pub_test_ejemplo',
                wompi_private_key = ?,
                wompi_events_secret = ?,
                wompi_ambiente = 'sandbox',
                webhook_token_hash = ?
              WHERE id = 1"
        )->execute([
            $this->cifrado->cifrar('prv_test_ejemplo'),
            $this->cifrado->cifrar($eventsSecret),
            hash('sha256', $tokenClaro),
        ]);

        return $tokenClaro;
    }

    private function curso(): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, 'Curso webhook', 'curso-webhook', 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    /**
     * Construye un cuerpo de webhook de Wompi válido, firmado exactamente
     * como `WompiAdapter::verificarWebhook()` lo verifica: SHA-256 de los
     * valores de `signature.properties` en orden + timestamp + secreto,
     * en mayúsculas.
     */
    private function payloadFirmado(string $eventsSecret, string $referencia, string $paymentLinkId, string $estadoWompi): string
    {
        $timestamp = (string) time();
        $transaction = [
            'id' => 'txn-' . bin2hex(random_bytes(4)),
            'status' => $estadoWompi,
            'amount_in_cents' => 25_000_000,
            'reference' => $referencia,
            'payment_link_id' => $paymentLinkId,
            'payment_method_type' => 'CARD',
        ];
        $props = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];

        $concat = $transaction['id'] . $transaction['status'] . $transaction['amount_in_cents'];
        $checksum = strtoupper(hash('sha256', $concat . $timestamp . $eventsSecret));

        return json_encode([
            'event' => 'transaction.updated',
            'data' => ['transaction' => $transaction],
            'signature' => ['properties' => $props, 'checksum' => $checksum],
            'timestamp' => $timestamp,
        ], JSON_UNESCAPED_UNICODE) ?: '';
    }

    #[Test]
    public function unPagoDeCursoAprobadoConfirmaLaCompraSinTocarElCaminoDeCitas(): void
    {
        $eventsSecret = 'secreto-de-prueba';
        $token = $this->configurarWompi($eventsSecret);

        $cursoId = $this->curso();
        $repoCompras = new \App\Repositorios\CompraCursoRepo($this->bd);
        $compraId = $repoCompras->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
        $repoCompras->guardarReferencia($compraId, 'ref-checkout-original', 'link-estable-123');

        $cuerpo = $this->payloadFirmado($eventsSecret, 'ref-que-rota-9999', 'link-estable-123', 'APPROVED');

        $controlador = new WebhookControlador($this->bd, $this->cifrado, new Logger(sys_get_temp_dir() . '/pa-webhook.log', 'error'), dirname(__DIR__, 2));

        $peticion = new Peticion(
            metodo: 'POST',
            ruta: "/api/wa/pago/{$token}",
            cuerpoCrudo: $cuerpo,
            parametros: ['token' => $token],
        );

        $r = $controlador->pago($peticion);

        self::assertSame(200, $r->estado);
        self::assertSame('pagada', $repoCompras->porId($compraId)['estado']);
    }

    #[Test]
    public function unPagoRechazadoDeCursoMarcaLaCompraComoFallida(): void
    {
        $eventsSecret = 'secreto-de-prueba-2';
        $token = $this->configurarWompi($eventsSecret);

        $cursoId = $this->curso();
        $repoCompras = new \App\Repositorios\CompraCursoRepo($this->bd);
        $compraId = $repoCompras->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
        $repoCompras->guardarReferencia($compraId, 'ref-2', 'link-456');

        $cuerpo = $this->payloadFirmado($eventsSecret, 'ref-2', 'link-456', 'DECLINED');

        $controlador = new WebhookControlador($this->bd, $this->cifrado, new Logger(sys_get_temp_dir() . '/pa-webhook.log', 'error'), dirname(__DIR__, 2));

        $controlador->pago(new Peticion(
            metodo: 'POST',
            ruta: "/api/wa/pago/{$token}",
            cuerpoCrudo: $cuerpo,
            parametros: ['token' => $token],
        ));

        self::assertSame('fallida', $repoCompras->porId($compraId)['estado']);
    }

    #[Test]
    public function unaReferenciaQueNoEsDeNingunaCompraDeCursoSigueElCaminoDeCitasSinTronar(): void
    {
        $eventsSecret = 'secreto-de-prueba-3';
        $token = $this->configurarWompi($eventsSecret);

        // Sin ninguna compras_curso pendiente que coincida — el evento debe
        // caer en el camino de citas existente (que aquí no encuentra
        // pedido y responde 200 sin más, comportamiento ya existente y sin
        // tocar).
        $cuerpo = $this->payloadFirmado($eventsSecret, 'referencia-de-una-cita', 'link-de-una-cita', 'APPROVED');

        $controlador = new WebhookControlador($this->bd, $this->cifrado, new Logger(sys_get_temp_dir() . '/pa-webhook.log', 'error'), dirname(__DIR__, 2));

        $r = $controlador->pago(new Peticion(
            metodo: 'POST',
            ruta: "/api/wa/pago/{$token}",
            cuerpoCrudo: $cuerpo,
            parametros: ['token' => $token],
        ));

        self::assertSame(200, $r->estado);
    }
}
```

- [ ] **Step 6: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/WebhookPagoCursoTest.php`
Expected: FAIL — the first two tests fail because `compras_curso` is never checked yet (the webhook falls through to the citas path and the compra stays `pendiente`); the third passes already (nothing to break yet). Confirm the failure message shows the compra's `estado` staying `pendiente`, not an unrelated error — if it's a different failure (signature rejected, `sin pasarela`), stop and re-check the `configurarWompi()` setup before touching `WebhookControlador.php`.

- [ ] **Step 7: Extend `WebhookControlador::pago()`**

In `src/Wa/WebhookControlador.php`, find:

```php
        $this->responder200Ya();

        try {
            $adapter = Engine::dominio();
            $pm = new PaymentManager($db, $log, $adapter);
```

Replace with:

```php
        $this->responder200Ya();

        $repoCompras = new \App\Repositorios\CompraCursoRepo($this->bd);
        $compraCurso = $repoCompras->pendientePorReferencia((string) $v['referencia'], (string) ($v['payment_link_id'] ?? ''));

        if ($compraCurso !== null) {
            if ($v['estado'] === 'PAYMENT_VERIFIED') {
                $confirmador = new \App\Cuenta\ConfirmadorCompra(
                    $repoCompras,
                    new \App\Repositorios\CompradorEnlaceRepo($this->bd),
                    new \App\Wa\ConexionCompartida($this->bd, $this->cifrado, $this->logApp, $this->raiz),
                    $this->bd,
                    \App\Soporte\Smtp::desdeEntorno(),
                    (string) (\App\Soporte\Entorno::obtener('APP_URL', '') ?? ''),
                );
                $confirmador->confirmar($compraCurso['id']);
            } else {
                $repoCompras->marcarFallida($compraCurso['id']);
            }

            return new Respuesta('', 200);
        }

        try {
            $adapter = Engine::dominio();
            $pm = new PaymentManager($db, $log, $adapter);
```

This is the only change to this file. Everything below the new `if ($compraCurso !== null) { ... return ...; }` block — the `try`/`PaymentManager`/citas notification logic — is untouched, and only runs when the payment isn't for a course.

Check the top of `WebhookControlador.php` for how `App\Soporte\Entorno` and `App\Soporte\Smtp` are referenced elsewhere in the file — if either is already imported with a `use` statement, use the unqualified class name instead of the fully-qualified one to match the file's existing style.

- [ ] **Step 8: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/WebhookPagoCursoTest.php`
Expected: PASS (3/3)

- [ ] **Step 9: Confirm the existing appointment-payment tests still pass**

Run: `vendor/bin/phpunit tests/Unidad/NotaDeVozDePagoTest.php`
Expected: PASS — this only checks a string constant, unaffected by this change, but it's the one existing test file that imports `WebhookControlador` and is worth a quick regression check.

- [ ] **Step 10: Commit**

```bash
git add src/Cuenta/ConfirmadorCompra.php tests/Integracion/ConfirmadorCompraTest.php \
        src/Wa/WebhookControlador.php tests/Integracion/WebhookPagoCursoTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): ConfirmadorCompra y extension aditiva del webhook de pago

El webhook de Wompi ahora reconoce compras_curso por referencia o por
payment_link_id (estable frente a la referencia que rota en cada
sesion de checkout) antes de entregar el evento al PaymentManager de
citas. Confirmar una compra es idempotente: un webhook duplicado no
repite el aviso a Pedro ni genera un segundo enlace de registro.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: `AccesoControlador` — completar registro, entrar, salir, recuperar

**Files:**
- Create: `src/Cuenta/AccesoControlador.php`
- Create: `plantillas/cuenta/completar.php`
- Create: `plantillas/cuenta/entrar.php`
- Create: `plantillas/cuenta/recuperar.php`
- Create: `plantillas/cuenta/recuperar_enviado.php`
- Create: `plantillas/cuenta/recuperar_confirmar.php`
- Create: `plantillas/cuenta/enlace_invalido.php`
- Test: `tests/Integracion/AccesoControladorTest.php`

**Interfaces:**
- Produces: `final class App\Cuenta\AccesoControlador` with `__construct(AutenticacionComprador $auth, CompradorRepo $compradores, CompradorSesionRepo $sesiones, CompradorEnlaceRepo $enlaces, CompraCursoRepo $compras, ?Smtp $smtp, string $urlBase)`, and methods `completarMostrar`, `completarProcesar`, `entrarMostrar`, `entrarProcesar`, `salir`, `recuperarMostrar`, `recuperarProcesar`, `recuperarConfirmarMostrar`, `recuperarConfirmarProcesar` — all `(Peticion $peticion): Respuesta`. `const COOKIE = 'pa_comprador'` (public, so Task 11's `/mis-cursos` route closure can read the same cookie name).
- Consumes: `AutenticacionComprador` (Task 5), `CompradorRepo` (Task 2), `CompradorSesionRepo` (Task 3), `CompradorEnlaceRepo` (Task 4), `CompraCursoRepo` (Task 7), `App\Soporte\Smtp` (existing).

Password-reset requests never reveal whether an email has an account: `recuperarProcesar()` always shows the same "revise su correo" message, and only actually sends an email when `CompradorRepo::porCorreo()` finds someone — same anti-enumeration posture as login.

Changing a password revokes every session for that buyer (`CompradorSesionRepo::revocarTodas()`), mirroring exactly what `Autenticacion::cambiarPassword()` already does for staff.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\AccesoControlador;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorEnlaceRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class AccesoControladorTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private AccesoControlador $controlador;
    private CompradorRepo $compradores;
    private CompradorEnlaceRepo $enlaces;
    private CompraCursoRepo $compras;
    private CompradorSesionRepo $sesiones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->sesiones = new CompradorSesionRepo($this->bd);
        $this->enlaces = new CompradorEnlaceRepo($this->bd);
        $this->compras = new CompraCursoRepo($this->bd);

        $this->controlador = new AccesoControlador(
            new AutenticacionComprador($this->compradores, $this->sesiones, new IntentoAccesoRepo($this->bd)),
            $this->compradores,
            $this->sesiones,
            $this->enlaces,
            $this->compras,
            null, // Smtp
            self::URL,
        );
    }

    private function cursoYCompra(string $correo): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso acceso', 'curso-acceso', 'r', 'd', '[]', 250000, 'publicado']);

        return $this->compras->crear($cursoId, 'Ana Gómez', $correo, 250000);
    }

    private function peticion(string $ruta, array $formulario = [], array $consulta = []): Peticion
    {
        return new Peticion(metodo: $formulario === [] ? 'GET' : 'POST', ruta: $ruta, consulta: $consulta, formulario: $formulario, ip: '190.85.1.1');
    }

    #[Test]
    public function unTokenInvalidoMuestraElEnlaceInvalido(): void
    {
        $r = $this->controlador->completarMostrar($this->peticion('/mis-cursos/completar', [], ['token' => 'no-existe']));

        self::assertSame(410, $r->estado);
    }

    #[Test]
    public function completarRegistroConCorreoNuevoCreaLaCuentaYVinculaLaCompra(): void
    {
        $compraId = $this->cursoYCompra('nueva@ejemplo.com');
        $token = $this->enlaces->crear('completar_registro', null, $compraId, 60);

        $r = $this->controlador->completarProcesar($this->peticion('/mis-cursos/completar', [
            'token' => $token, 'modo' => 'registro',
            'nombres' => 'Ana', 'apellidos' => 'Gómez', 'tipo_documento' => 'CC',
            'numero_documento' => '1010101010', 'celular' => '3001234567', 'password' => 'claveSegura123',
        ]));

        self::assertSame(302, $r->estado);
        self::assertSame('/mis-cursos', $r->cabeceras['Location']);

        $comprador = $this->compradores->porCorreo('nueva@ejemplo.com');
        self::assertNotNull($comprador);
        self::assertSame($comprador->id, $this->compras->porId($compraId)['comprador_id']);
    }

    #[Test]
    public function elEnlaceDeRegistroQuedaMarcadoUsadoTrasCompletarlo(): void
    {
        $compraId = $this->cursoYCompra('usado@ejemplo.com');
        $token = $this->enlaces->crear('completar_registro', null, $compraId, 60);

        $this->controlador->completarProcesar($this->peticion('/mis-cursos/completar', [
            'token' => $token, 'modo' => 'registro',
            'nombres' => 'Ana', 'apellidos' => 'Gómez', 'tipo_documento' => 'CC',
            'numero_documento' => '1010101010', 'celular' => '3001234567', 'password' => 'claveSegura123',
        ]));

        self::assertNull($this->enlaces->vigente($token, 'completar_registro'));
    }

    #[Test]
    public function completarConCorreoQueYaTieneCuentaVinculaPorLogin(): void
    {
        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'existente@ejemplo.com', 'claveVieja123');
        $compraId = $this->cursoYCompra('existente@ejemplo.com');
        $token = $this->enlaces->crear('completar_registro', null, $compraId, 60);

        $r = $this->controlador->completarProcesar($this->peticion('/mis-cursos/completar', [
            'token' => $token, 'modo' => 'login', 'password' => 'claveVieja123',
        ]));

        self::assertSame(302, $r->estado);
        $comprador = $this->compradores->porCorreo('existente@ejemplo.com');
        self::assertSame($comprador->id, $this->compras->porId($compraId)['comprador_id']);
    }

    #[Test]
    public function completarConClaveEquivocadaEnModoLoginNoVinculaNada(): void
    {
        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'existente2@ejemplo.com', 'claveVieja123');
        $compraId = $this->cursoYCompra('existente2@ejemplo.com');
        $token = $this->enlaces->crear('completar_registro', null, $compraId, 60);

        $this->controlador->completarProcesar($this->peticion('/mis-cursos/completar', [
            'token' => $token, 'modo' => 'login', 'password' => 'claveEquivocada',
        ]));

        self::assertNull($this->compras->porId($compraId)['comprador_id']);
    }

    #[Test]
    public function entrarConCredencialesCorrectasAbreSesion(): void
    {
        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'login@ejemplo.com', 'clave123');

        $r = $this->controlador->entrarProcesar($this->peticion('/entrar', [
            'correo' => 'login@ejemplo.com', 'password' => 'clave123',
        ]));

        self::assertSame(302, $r->estado);
        self::assertSame('/mis-cursos', $r->cabeceras['Location']);
    }

    #[Test]
    public function recuperarSiempreMuestraElMismoMensajeExistaOnoLaCuenta(): void
    {
        $r1 = $this->controlador->recuperarProcesar($this->peticion('/recuperar', ['correo' => 'no-existe@ejemplo.com']));

        $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'si-existe@ejemplo.com', 'clave123');
        $r2 = $this->controlador->recuperarProcesar($this->peticion('/recuperar', ['correo' => 'si-existe@ejemplo.com']));

        self::assertSame($r1->estado, $r2->estado);
        self::assertSame($r1->cuerpo, $r2->cuerpo);
    }

    #[Test]
    public function recuperarConCorreoExistenteCreaUnEnlaceDeReset(): void
    {
        $id = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'reset@ejemplo.com', 'claveVieja');

        $this->controlador->recuperarProcesar($this->peticion('/recuperar', ['correo' => 'reset@ejemplo.com']));

        $fila = $this->bd->pdo()->prepare("SELECT COUNT(*) FROM compradores_enlaces WHERE comprador_id = ? AND tipo = 'reset_password'");
        $fila->execute([$id]);
        self::assertSame(1, (int) $fila->fetchColumn());
    }

    #[Test]
    public function confirmarResetCambiaLaClaveYRevocaLasSesionesVivas(): void
    {
        $id = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'reset2@ejemplo.com', 'claveVieja');
        $comprador = $this->compradores->porId($id);
        $tokenSesion = $this->sesiones->crear($id, 60, null, null);
        $tokenReset = $this->enlaces->crear('reset_password', $id, null, 60);

        $r = $this->controlador->recuperarConfirmarProcesar($this->peticion('/recuperar/confirmar', [
            'token' => $tokenReset, 'password' => 'claveNueva123',
        ]));

        self::assertSame(302, $r->estado);
        self::assertTrue($this->compradores->verificarPassword('reset2@ejemplo.com', 'claveNueva123'));
        self::assertFalse($this->compradores->verificarPassword('reset2@ejemplo.com', 'claveVieja'));
        self::assertNull($this->sesiones->vigente($tokenSesion));
        self::assertNull($this->enlaces->vigente($tokenReset, 'reset_password'));
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/AccesoControladorTest.php`
Expected: FAIL — `Class "App\Cuenta\AccesoControlador" not found`

- [ ] **Step 3: Write `src/Cuenta/AccesoControlador.php`**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorEnlaceRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Servicios\AutenticacionComprador;
use App\Soporte\Entorno;
use App\Soporte\Smtp;

final class AccesoControlador
{
    public const COOKIE = 'pa_comprador';
    private const MINUTOS_RESET = 120; // 2 horas

    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly CompradorRepo $compradores,
        private readonly CompradorSesionRepo $sesiones,
        private readonly CompradorEnlaceRepo $enlaces,
        private readonly CompraCursoRepo $compras,
        private readonly ?Smtp $smtp,
        private readonly string $urlBase,
    ) {
    }

    public function completarMostrar(Peticion $peticion): Respuesta
    {
        $datos = $this->resolverEnlaceCompletar((string) ($peticion->consulta['token'] ?? ''));
        if ($datos === null) {
            return Respuesta::vista('cuenta/enlace_invalido', [], 410);
        }

        return Respuesta::vista('cuenta/completar', [
            'token' => $datos['token'],
            'correo' => $datos['correo'],
            'existeCuenta' => $this->compradores->existeCorreo($datos['correo']),
            'error' => $peticion->consulta['error'] ?? null,
        ]);
    }

    public function completarProcesar(Peticion $peticion): Respuesta
    {
        $token = (string) ($peticion->formulario['token'] ?? '');
        $datos = $this->resolverEnlaceCompletar($token);

        if ($datos === null) {
            return Respuesta::vista('cuenta/enlace_invalido', [], 410);
        }

        $correo = $datos['correo'];
        $modo = (string) ($peticion->formulario['modo'] ?? '');

        if ($modo === 'login') {
            $resultado = $this->auth->verificarCredenciales($correo, (string) ($peticion->formulario['password'] ?? ''), $peticion->ip);

            if (!$resultado['ok']) {
                return $this->redirigirCompletar($token, $resultado['motivo']);
            }

            $comprador = $resultado['comprador'];
        } else {
            if ($this->compradores->existeCorreo($correo)) {
                return $this->redirigirCompletar($token, 'Ese correo ya tiene una cuenta. Inicie sesión.');
            }

            $nombres = trim((string) ($peticion->formulario['nombres'] ?? ''));
            $apellidos = trim((string) ($peticion->formulario['apellidos'] ?? ''));
            $tipoDocumento = (string) ($peticion->formulario['tipo_documento'] ?? '');
            $numeroDocumento = trim((string) ($peticion->formulario['numero_documento'] ?? ''));
            $celular = trim((string) ($peticion->formulario['celular'] ?? ''));
            $password = (string) ($peticion->formulario['password'] ?? '');

            if ($nombres === '' || $apellidos === '' || $numeroDocumento === '' || $celular === ''
                || mb_strlen($password) < 8
                || !in_array($tipoDocumento, ['CC', 'CE', 'PASAPORTE', 'NIT'], true)
            ) {
                return $this->redirigirCompletar($token, 'Complete todos los campos. La contraseña debe tener al menos 8 caracteres.');
            }

            $compradorId = $this->compradores->crear($nombres, $apellidos, $tipoDocumento, $numeroDocumento, $celular, $correo, $password);
            $comprador = $this->compradores->porId($compradorId);
        }

        $this->compras->vincularComprador($datos['compraId'], $comprador->id);
        $this->enlaces->marcarUsado($datos['enlaceId']);

        $sesionToken = $this->auth->abrirSesion($comprador, $peticion->ip, $peticion->cabecera('user-agent'));

        return $this->conCookieDeSesion($sesionToken, '/mis-cursos');
    }

    public function entrarMostrar(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('cuenta/entrar', ['error' => $peticion->consulta['error'] ?? null]);
    }

    public function entrarProcesar(Peticion $peticion): Respuesta
    {
        $resultado = $this->auth->verificarCredenciales(
            (string) ($peticion->formulario['correo'] ?? ''),
            (string) ($peticion->formulario['password'] ?? ''),
            $peticion->ip,
        );

        if (!$resultado['ok']) {
            return new Respuesta('', 302, [
                'Location' => '/entrar?' . http_build_query(['error' => $resultado['motivo']]),
            ]);
        }

        $token = $this->auth->abrirSesion($resultado['comprador'], $peticion->ip, $peticion->cabecera('user-agent'));

        return $this->conCookieDeSesion($token, '/mis-cursos');
    }

    public function salir(Peticion $peticion): Respuesta
    {
        $token = $_COOKIE[self::COOKIE] ?? null;

        if (is_string($token) && $token !== '') {
            $this->auth->cerrarSesion($token);
        }

        $this->borrarCookieDeSesion();

        return new Respuesta('', 302, ['Location' => '/entrar']);
    }

    public function recuperarMostrar(Peticion $peticion): Respuesta
    {
        return Respuesta::vista('cuenta/recuperar', []);
    }

    public function recuperarProcesar(Peticion $peticion): Respuesta
    {
        $correo = mb_strtolower(trim((string) ($peticion->formulario['correo'] ?? '')));
        $comprador = $this->compradores->porCorreo($correo);

        // Mismo mensaje exista o no la cuenta — no se puede enumerar correos
        // desde este formulario, igual que el login.
        if ($comprador !== null && $this->smtp !== null) {
            $token = $this->enlaces->crear('reset_password', $comprador->id, null, self::MINUTOS_RESET);
            $url = rtrim($this->urlBase, '/') . '/recuperar/confirmar?token=' . $token;

            $this->smtp->enviar(
                $comprador->correo,
                'Recuperar su contraseña',
                "Hola {$comprador->nombreCompleto()},\n\nUse este enlace para elegir una contraseña nueva:\n{$url}\n\n"
                    . "Este enlace es válido por 2 horas. Si no pidió esto, ignore el correo.\n",
            );
        }

        return Respuesta::vista('cuenta/recuperar_enviado', []);
    }

    public function recuperarConfirmarMostrar(Peticion $peticion): Respuesta
    {
        $token = (string) ($peticion->consulta['token'] ?? '');

        if ($this->enlaces->vigente($token, 'reset_password') === null) {
            return Respuesta::vista('cuenta/enlace_invalido', [], 410);
        }

        return Respuesta::vista('cuenta/recuperar_confirmar', [
            'token' => $token,
            'error' => $peticion->consulta['error'] ?? null,
        ]);
    }

    public function recuperarConfirmarProcesar(Peticion $peticion): Respuesta
    {
        $token = (string) ($peticion->formulario['token'] ?? '');
        $enlace = $this->enlaces->vigente($token, 'reset_password');

        if ($enlace === null || $enlace['comprador_id'] === null) {
            return Respuesta::vista('cuenta/enlace_invalido', [], 410);
        }

        $password = (string) ($peticion->formulario['password'] ?? '');

        if (mb_strlen($password) < 8) {
            return new Respuesta('', 302, [
                'Location' => '/recuperar/confirmar?' . http_build_query([
                    'token' => $token,
                    'error' => 'La contraseña debe tener al menos 8 caracteres.',
                ]),
            ]);
        }

        $this->compradores->cambiarPassword($enlace['comprador_id'], $password);
        $this->enlaces->marcarUsado($enlace['id']);

        // Rotación al cambiar contraseña, misma disciplina que
        // Autenticacion::cambiarPassword() para el panel: si la clave se
        // filtró, una sesión ya abierta con la clave vieja no debe seguir viva.
        $this->sesiones->revocarTodas($enlace['comprador_id']);

        return new Respuesta('', 302, ['Location' => '/entrar']);
    }

    /** @return array{token:string,correo:string,compraId:string,enlaceId:string}|null */
    private function resolverEnlaceCompletar(string $token): ?array
    {
        $enlace = $this->enlaces->vigente($token, 'completar_registro');
        if ($enlace === null || $enlace['compra_id'] === null) {
            return null;
        }

        $compra = $this->compras->porId($enlace['compra_id']);
        if ($compra === null) {
            return null;
        }

        return [
            'token' => $token,
            'correo' => (string) $compra['correo'],
            'compraId' => (string) $compra['id'],
            'enlaceId' => $enlace['id'],
        ];
    }

    private function redirigirCompletar(string $token, string $error): Respuesta
    {
        return new Respuesta('', 302, [
            'Location' => '/mis-cursos/completar?' . http_build_query(['token' => $token, 'error' => $error]),
        ]);
    }

    private function conCookieDeSesion(string $token, string $destino): Respuesta
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => $this->seguro(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return new Respuesta('', 302, ['Location' => $destino]);
    }

    private function borrarCookieDeSesion(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->seguro(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function seguro(): bool
    {
        return (Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo';
    }
}
```

- [ ] **Step 4: Write the templates**

`plantillas/cuenta/enlace_invalido.php`:

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
<title>Enlace vencido</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">
<main class="mx-auto max-w-lg px-5 py-24 text-center md:px-7">
    <h1 class="titular-seccion">Este enlace ya no es válido</h1>
    <p class="mt-4 text-acero">Puede que ya lo haya usado o que haya vencido.</p>
    <a href="/recuperar" class="menu-enlace mt-6 inline-block">Pedir uno nuevo</a>
</main>
</body>
</html>
```

`plantillas/cuenta/completar.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

/**
 * @var string $token
 * @var string $correo
 * @var bool $existeCuenta
 * @var ?string $error
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$csrf = new Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $existeCuenta ? 'Iniciar sesión' : 'Completar registro' ?></title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <h1 class="titular-seccion">
        <?= $existeCuenta ? 'Ya tiene una cuenta' : 'Complete su registro' ?>
    </h1>
    <p class="mt-4 text-acero">
        <?= $existeCuenta
            ? 'Inicie sesión para ver su curso.'
            : 'Su pago fue confirmado. Cree su contraseña para acceder.' ?>
    </p>

    <?php if ($error !== null): ?>
    <p class="mt-4 rounded border border-alerta/40 bg-alerta/10 p-3 text-sm text-alerta"><?= $e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/mis-cursos/completar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>
        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <input type="hidden" name="modo" value="<?= $existeCuenta ? 'login' : 'registro' ?>">

        <label class="text-xs uppercase tracking-widest text-acero">Correo</label>
        <input value="<?= $e($correo) ?>" disabled class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-acero">

        <?php if (!$existeCuenta): ?>
        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Nombres</label>
        <input name="nombres" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Apellidos</label>
        <input name="apellidos" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Tipo de documento</label>
        <select name="tipo_documento" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">
            <option value="CC">Cédula de ciudadanía</option>
            <option value="CE">Cédula de extranjería</option>
            <option value="PASAPORTE">Pasaporte</option>
            <option value="NIT">NIT</option>
        </select>

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Número de documento</label>
        <input name="numero_documento" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Celular</label>
        <input name="celular" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">
        <?php endif; ?>

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Contraseña</label>
        <input name="password" type="password" required minlength="8" class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">
            <?= $existeCuenta ? 'Entrar' : 'Crear mi cuenta' ?>
        </button>
    </form>
</main>

</body>
</html>
```

`plantillas/cuenta/entrar.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

/** @var ?string $error */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$csrf = new Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <h1 class="titular-seccion">Iniciar sesión</h1>

    <?php if ($error !== null): ?>
    <p class="mt-4 rounded border border-alerta/40 bg-alerta/10 p-3 text-sm text-alerta"><?= $e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/entrar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>

        <label class="text-xs uppercase tracking-widest text-acero">Correo</label>
        <input name="correo" type="email" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <label class="mt-4 block text-xs uppercase tracking-widest text-acero">Contraseña</label>
        <input name="password" type="password" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">Entrar</button>
    </form>

    <a href="/recuperar" class="menu-enlace mt-6 inline-block">Olvidé mi contraseña</a>
</main>

</body>
</html>
```

`plantillas/cuenta/recuperar.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$csrf = new Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar contraseña</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <h1 class="titular-seccion">Recuperar contraseña</h1>
    <p class="mt-4 text-acero">Escriba su correo y le enviaremos un enlace para elegir una contraseña nueva.</p>

    <form method="post" action="/recuperar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>
        <label class="text-xs uppercase tracking-widest text-acero">Correo</label>
        <input name="correo" type="email" required class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">Enviar enlace</button>
    </form>
</main>

</body>
</html>
```

`plantillas/cuenta/recuperar_enviado.php`:

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
<title>Revise su correo</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">
<main class="mx-auto max-w-lg px-5 py-24 text-center md:px-7">
    <h1 class="titular-seccion">Revise su correo</h1>
    <p class="mt-4 text-acero">Si ese correo tiene una cuenta, le llegará un enlace para elegir una contraseña nueva.</p>
</main>
</body>
</html>
```

`plantillas/cuenta/recuperar_confirmar.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Soporte\Entorno;
use App\Soporte\Vista;

/**
 * @var string $token
 * @var ?string $error
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
$csrf = new Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Elegir nueva contraseña</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<main class="mx-auto max-w-lg px-5 py-16 md:px-7">
    <h1 class="titular-seccion">Elegir nueva contraseña</h1>

    <?php if ($error !== null): ?>
    <p class="mt-4 rounded border border-alerta/40 bg-alerta/10 p-3 text-sm text-alerta"><?= $e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/recuperar/confirmar" class="doble-bisel mt-6 p-6">
        <?= $csrf->campoOculto() ?>
        <input type="hidden" name="token" value="<?= $e($token) ?>">

        <label class="text-xs uppercase tracking-widest text-acero">Nueva contraseña</label>
        <input name="password" type="password" required minlength="8" class="mt-2 w-full rounded border border-linea bg-white/5 p-3 text-papel">

        <button type="submit" class="boton-diagnostico-global mt-6 w-full">Guardar</button>
    </form>
</main>

</body>
</html>
```

- [ ] **Step 5: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/AccesoControladorTest.php`
Expected: PASS (9/9)

- [ ] **Step 6: Commit**

```bash
git add src/Cuenta/AccesoControlador.php plantillas/cuenta/completar.php \
        plantillas/cuenta/entrar.php plantillas/cuenta/recuperar.php \
        plantillas/cuenta/recuperar_enviado.php plantillas/cuenta/recuperar_confirmar.php \
        plantillas/cuenta/enlace_invalido.php tests/Integracion/AccesoControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): AccesoControlador - completar registro, entrar, salir, recuperar clave

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: `/mis-cursos` + wiring todas las rutas de cuenta

**Files:**
- Create: `src/Cuenta/MisCursosControlador.php`
- Create: `plantillas/cuenta/mis_cursos.php`
- Modify: `src/Core/Aplicacion.php` (container entries for Tasks 2–5, 10, 11, and every route from Tasks 10–11)
- Test: `tests/Integracion/MisCursosControladorTest.php`

**Interfaces:**
- Produces: `final class App\Cuenta\MisCursosControlador` with `__construct(AutenticacionComprador $auth, CompraCursoRepo $compras)`, `mostrar(Peticion $peticion): Respuesta`.
- Consumes: `AutenticacionComprador` (Task 5), `CompraCursoRepo` (Task 7), `AccesoControlador::COOKIE` (Task 10, the shared cookie name).

`/mis-cursos` deliberately does **not** re-render each course's curriculum — the public `/cursos/{slug}` ficha page (from the catalog sub-project) already shows the full read-only temario for any course, published or not. The dashboard just lists what the buyer paid for and links to it, instead of duplicating that rendering.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Cuenta\AccesoControlador;
use App\Cuenta\MisCursosControlador;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Servicios\AutenticacionComprador;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class MisCursosControladorTest extends CasoBaseBd
{
    private MisCursosControlador $controlador;
    private AutenticacionComprador $auth;
    private CompradorRepo $compradores;
    private CompraCursoRepo $compras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $this->compras = new CompraCursoRepo($this->bd);
        $this->auth = new AutenticacionComprador($this->compradores, new CompradorSesionRepo($this->bd), new IntentoAccesoRepo($this->bd));
        $this->controlador = new MisCursosControlador($this->auth, $this->compras);
    }

    private function curso(string $titulo, string $slug): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-' . $slug]);
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, $titulo, $slug, 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    private function peticion(): Peticion
    {
        return new Peticion(metodo: 'GET', ruta: '/mis-cursos');
    }

    #[Test]
    public function sinSesionRedirigeAEntrar(): void
    {
        $r = $this->controlador->mostrar($this->peticion());

        self::assertSame(302, $r->estado);
        self::assertSame('/entrar', $r->cabeceras['Location']);
    }

    #[Test]
    public function conSesionMuestraSoloLosCursosPagadosDeEseComprador(): void
    {
        $cursoId = $this->curso('Curso propio', 'curso-propio');
        $otroCursoId = $this->curso('Curso de otro', 'curso-de-otro');

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana@ejemplo.com', 'clave123');
        $otroCompradorId = $this->compradores->crear('Beto', 'Ruiz', 'CC', '2020202020', '3009999999', 'beto@ejemplo.com', 'clave123');

        $compraPropia = $this->compras->crear($cursoId, 'Ana', 'ana@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraPropia);
        $this->compras->vincularComprador($compraPropia, $compradorId);

        $compraAjena = $this->compras->crear($otroCursoId, 'Beto', 'beto@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraAjena);
        $this->compras->vincularComprador($compraAjena, $otroCompradorId);

        $comprador = $this->compradores->porId($compradorId);
        $token = $this->auth->abrirSesion($comprador, null, null);
        $_COOKIE[AccesoControlador::COOKIE] = $token;

        $r = $this->controlador->mostrar($this->peticion());

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('Curso propio', $r->cuerpo);
        self::assertStringNotContainsString('Curso de otro', $r->cuerpo);

        unset($_COOKIE[AccesoControlador::COOKIE]);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `vendor/bin/phpunit tests/Integracion/MisCursosControladorTest.php`
Expected: FAIL — `Class "App\Cuenta\MisCursosControlador" not found`

- [ ] **Step 3: Write `src/Cuenta/MisCursosControlador.php`**

```php
<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\AutenticacionComprador;

final class MisCursosControlador
{
    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly CompraCursoRepo $compras,
    ) {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $token = $_COOKIE[AccesoControlador::COOKIE] ?? null;
        $comprador = (is_string($token) && $token !== '') ? $this->auth->compradorDeSesion($token) : null;

        if ($comprador === null) {
            return new Respuesta('', 302, ['Location' => '/entrar']);
        }

        return Respuesta::vista('cuenta/mis_cursos', [
            'comprador' => $comprador,
            'cursos' => $this->compras->pagadasDeComprador($comprador->id),
        ]);
    }
}
```

- [ ] **Step 4: Write `plantillas/cuenta/mis_cursos.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Modelos\Comprador $comprador
 * @var list<array<string,mixed>> $cursos
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mis cursos</title>
<meta name="robots" content="noindex, nofollow">
<style><?= $css ?></style>
</head>
<body class="bg-tinta text-papel">

<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/" class="flex shrink-0 items-center" aria-label="Pedro, abogado aduanero">
            <img src="/img/logo-pedro.png" alt="" width="40" height="40" class="h-10 w-10" decoding="async">
            <span class="sr-only">Pedro</span>
        </a>
        <p class="ml-auto text-sm text-acero"><?= $e($comprador->nombreCompleto()) ?></p>
        <form method="post" action="/salir">
            <button type="submit" class="menu-enlace">Salir</button>
        </form>
    </div>
</header>

<main class="mx-auto max-w-3xl px-5 py-12 md:px-7">
    <h1 class="titular-seccion">Mis cursos</h1>

    <?php if ($cursos === []): ?>
    <p class="mt-6 text-acero">Todavía no tiene cursos comprados.</p>
    <?php else: ?>
    <div class="mt-6 grid gap-4">
        <?php foreach ($cursos as $curso): ?>
        <a href="/cursos/<?= $e((string) $curso['slug']) ?>" class="doble-bisel block p-5">
            <h2 class="text-lg font-semibold"><?= $e((string) $curso['titulo']) ?></h2>
            <p class="mt-2 text-sm text-acero">
                Comprado el <?= $e(substr((string) $curso['pagado_en'], 0, 10)) ?>
            </p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>
```

- [ ] **Step 5: Run to confirm it passes**

Run: `vendor/bin/phpunit tests/Integracion/MisCursosControladorTest.php`
Expected: PASS (2/2)

- [ ] **Step 6: Wire every account container entry and route in `src/Core/Aplicacion.php`**

Register the container entries right after the `CompraCursoRepo::class` registration added by Task 8:

```php
        $this->contenedor->registrar(
            \App\Repositorios\CompradorRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CompradorRepo => new \App\Repositorios\CompradorRepo(
                $c->obtener(BD::class),
                $c->obtener(Cifrado::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CompradorSesionRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CompradorSesionRepo => new \App\Repositorios\CompradorSesionRepo(
                $c->obtener(BD::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Repositorios\CompradorEnlaceRepo::class,
            static fn (Contenedor $c): \App\Repositorios\CompradorEnlaceRepo => new \App\Repositorios\CompradorEnlaceRepo(
                $c->obtener(BD::class),
            ),
        );

        $this->contenedor->registrar(
            \App\Servicios\AutenticacionComprador::class,
            static fn (Contenedor $c): \App\Servicios\AutenticacionComprador => new \App\Servicios\AutenticacionComprador(
                $c->obtener(\App\Repositorios\CompradorRepo::class),
                $c->obtener(\App\Repositorios\CompradorSesionRepo::class),
                $c->obtener(\App\Repositorios\IntentoAccesoRepo::class),
            ),
        );
```

(`IntentoAccesoRepo::class` is already registered in this file — search for `IntentoAccesoRepo::class` and you'll find it bound around where the panel's own `Autenticacion::class` is registered. Reuse that binding via `$c->obtener(\App\Repositorios\IntentoAccesoRepo::class)`, do not register it a second time.)

Then, right after the three `/cursos/{slug}/comprar` and `/cursos/{slug}/gracias` routes added by Task 8, add:

```php
        $accesoControlador = fn (): \App\Cuenta\AccesoControlador => new \App\Cuenta\AccesoControlador(
            $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
            $this->contenedor->obtener(\App\Repositorios\CompradorRepo::class),
            $this->contenedor->obtener(\App\Repositorios\CompradorSesionRepo::class),
            $this->contenedor->obtener(\App\Repositorios\CompradorEnlaceRepo::class),
            $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
            \App\Soporte\Smtp::desdeEntorno(),
            $urlBase,
        );

        $this->router->get('/mis-cursos/completar', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->completarMostrar($p);
        });
        $this->router->post('/mis-cursos/completar', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->completarProcesar($p);
        });

        $this->router->get('/entrar', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->entrarMostrar($p);
        });
        $this->router->post('/entrar', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->entrarProcesar($p);
        });

        $this->router->post('/salir', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->salir($p);
        });

        $this->router->get('/recuperar', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->recuperarMostrar($p);
        });
        $this->router->post('/recuperar', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->recuperarProcesar($p);
        });

        $this->router->get('/recuperar/confirmar', function (Peticion $p) use ($accesoControlador): Respuesta {
            return $accesoControlador()->recuperarConfirmarMostrar($p);
        });
        $this->router->post('/recuperar/confirmar', function (Peticion $p) use ($accesoControlador): Respuesta {
            $csrf = new \App\Core\Csrf((Entorno::obtener('APP_ENV', 'produccion') ?? '') !== 'desarrollo');
            if (!$csrf->validar($p)) {
                return Respuesta::texto('Sesión de formulario expirada. Vuelva a intentarlo.', 419);
            }

            return $accesoControlador()->recuperarConfirmarProcesar($p);
        });

        $this->router->get('/mis-cursos', function (Peticion $p): Respuesta {
            return (new \App\Cuenta\MisCursosControlador(
                $this->contenedor->obtener(\App\Servicios\AutenticacionComprador::class),
                $this->contenedor->obtener(\App\Repositorios\CompraCursoRepo::class),
            ))->mostrar($p);
        });
```

The router passes `Peticion $p` into every route closure automatically (see how every other route in this file, including the ones added just above, declares `function (Peticion $p): Respuesta`) — this route follows the same pattern.

- [ ] **Step 7: Confirm the app still boots and nothing else regressed**

Run: `vendor/bin/phpunit tests/Integracion/ArranqueTest.php tests/Integracion/PanelTest.php tests/Unidad/NotaDeVozDePagoTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add src/Cuenta/MisCursosControlador.php plantillas/cuenta/mis_cursos.php \
        src/Core/Aplicacion.php tests/Integracion/MisCursosControladorTest.php
git commit -m "$(cat <<'EOF'
feat(cursos): /mis-cursos y cableado de todas las rutas de cuenta de comprador

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Panel — ver compras y aprobar a mano

**Files:**
- Modify: `src/Panel/PanelCursosControlador.php` (constructor gains two dependencies; two new methods)
- Modify: `tests/Integracion/PanelCursosTest.php` (update the `controlador()` helper for the new constructor; add new tests)
- Create: `plantillas/panel/cursos_compras.php`
- Modify: `src/Panel/Panel.php` (two new routes, updated `modulos()` instantiation)
- Modify: `plantillas/panel/_disposicion.php` (menu entry)

**Interfaces:**
- Produces: `PanelCursosControlador::__construct(BD $bd, AuditoriaRepo $auditoria, CompraCursoRepo $compras, ConfirmadorCompra $confirmador)` (constructor signature changes — every existing call site and test helper must be updated), `compras(Contexto $ctx): Respuesta`, `aprobarCompra(Contexto $ctx): Respuesta`.
- Consumes: `CompraCursoRepo` (Task 7), `ConfirmadorCompra` (Task 9).

**This task changes an existing constructor**, which is why it's scoped on its own rather than folded into Task 9 or 11 — every place that builds a `PanelCursosControlador` needs updating in the same commit, and a reviewer should see that blast radius as one unit.

- [ ] **Step 1: Update the test helper and add the failing tests**

In `tests/Integracion/PanelCursosTest.php`, find:

```php
    private function controlador(): PanelCursosControlador
    {
        return new PanelCursosControlador($this->bd, $this->auditoria);
    }
```

Replace with:

```php
    private function controlador(): PanelCursosControlador
    {
        return new PanelCursosControlador(
            $this->bd,
            $this->auditoria,
            new \App\Repositorios\CompraCursoRepo($this->bd),
            new \App\Cuenta\ConfirmadorCompra(
                new \App\Repositorios\CompraCursoRepo($this->bd),
                new \App\Repositorios\CompradorEnlaceRepo($this->bd),
                new \App\Wa\ConexionCompartida($this->bd, \App\Soporte\Cifrado::desdeEntorno(), $this->log, dirname(__DIR__, 2)),
                $this->bd,
                null,
                'https://pedroabogadoaduanero.com',
            ),
        );
    }
```

(`$this->log` is the `Logger` instance this test class's `setUp()` already builds — check the top of the file; if the property has a different name, use that instead.)

Then add these tests to the same file, in a new `// ── Compras ──` section:

```php
    private function compraDePruebaPara(string $slug): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-' . $slug]);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso panel', $slug, 'r', 'd', '[]', 250000, 'publicado']);

        return (new \App\Repositorios\CompraCursoRepo($this->bd))->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
    }

    #[Test]
    public function elAsistenteNoVeLasCompras(): void
    {
        $this->expectException(SinPermisoException::class);
        $this->controlador()->compras($this->ctx('asistente'));
    }

    #[Test]
    public function laListaDeComprasMuestraElNombreDelComprador(): void
    {
        $this->compraDePruebaPara('curso-panel-1');

        $html = $this->controlador()->compras($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('Ana Gómez', $html);
    }

    #[Test]
    public function aprobarAManoMarcaLaCompraPagadaYAuditaLaAccion(): void
    {
        $compraId = $this->compraDePruebaPara('curso-panel-2');

        $r = $this->controlador()->aprobarCompra($this->ctx('abogado', ['id' => $compraId]));

        self::assertSame(302, $r->estado);

        $repo = new \App\Repositorios\CompraCursoRepo($this->bd);
        self::assertSame('pagada', $repo->porId($compraId)['estado']);

        $auditadas = (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM auditoria WHERE entidad = 'compra_curso' AND accion = 'aprobar_manual'"
        )->fetchColumn();
        self::assertSame(1, $auditadas);
    }

    #[Test]
    public function aprobarUnaCompraInexistenteNoTruena(): void
    {
        $r = $this->controlador()->aprobarCompra($this->ctx('abogado', [
            'id' => (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn(),
        ]));

        self::assertSame(302, $r->estado);
        self::assertStringContainsString('no+existe', $r->cabeceras['Location']);
    }
```

- [ ] **Step 2: Run to confirm the new tests fail and the updated helper doesn't yet compile against the old class**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: FAIL — constructor argument count mismatch (`PanelCursosControlador::__construct()` still takes 2 arguments)

- [ ] **Step 3: Update `src/Panel/PanelCursosControlador.php`**

Find:

```php
final class PanelCursosControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }
```

Replace with:

```php
final class PanelCursosControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly AuditoriaRepo $auditoria,
        private readonly \App\Repositorios\CompraCursoRepo $compras,
        private readonly \App\Cuenta\ConfirmadorCompra $confirmador,
    ) {
    }
```

Then add the two new methods at the end of the class, right before the final closing `}`:

```php
    public function compras(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.ver');

        $filas = $this->bd->pdo()->query(
            "SELECT cc.*, c.titulo FROM compras_curso cc
               JOIN cursos c ON c.id = cc.curso_id
              ORDER BY cc.creado_en DESC"
        )->fetchAll();

        return $this->vista('panel/cursos_compras', [
            'ctx' => $ctx,
            'compras' => $filas,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function aprobarCompra(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'cursos.editar');

        $id = $ctx->campo('id');
        $compra = $this->compras->porId($id);

        if ($compra === null) {
            return $this->redirigirCon('/panel/cursos/compras', 'error', 'Esa compra no existe.');
        }

        $this->confirmador->confirmar($id);
        $this->auditoria->registrar('compra_curso', $id, 'aprobar_manual', $ctx->actor(), [], $ctx->ip());

        return $this->redirigirCon('/panel/cursos/compras', 'ok', 'Compra aprobada. Se envió el correo de registro.');
    }
```

- [ ] **Step 4: Run to confirm the tests pass**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php`
Expected: PASS — all tests from this plan's earlier tasks plus the 4 new ones in this task

- [ ] **Step 5: Write `plantillas/panel/cursos_compras.php`**

```php
<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $compras
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Compras de cursos';

$contenido = static function () use ($e, $ctx, $compras): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo">Compras de cursos</h2>

    <table class="tabla mt-4">
        <thead><tr><th>Curso</th><th>Comprador</th><th>Correo</th><th>Precio</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($compras as $c): ?>
        <tr>
            <td><?= $e((string) $c['titulo']) ?></td>
            <td><?= $e((string) $c['nombre']) ?></td>
            <td><?= $e((string) $c['correo']) ?></td>
            <td class="font-mono">$<?= $e(number_format((int) $c['precio_cop'], 0, ',', '.')) ?></td>
            <td><?= $e((string) $c['estado']) ?></td>
            <td>
                <?php if ($editable && $c['estado'] !== 'pagada'): ?>
                <form method="post" action="/panel/cursos/compras/aprobar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $c['id']) ?>">
                    <button type="submit" class="text-sm underline"
                            onclick="return confirm('¿Aprobar esta compra a mano? Se le enviará el correo de registro al comprador.')">
                        Aprobar a mano
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ($compras === []): ?>
        <tr><td colspan="6" class="text-acero">Todavía no hay compras.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php };

require __DIR__ . '/_disposicion.php';
```

- [ ] **Step 6: Wire the routes and update the `modulos()` instantiation in `src/Panel/Panel.php`**

Find (the cursos routes added by the catalog sub-project's plan):

```php
            'GET /cursos/categorias' => $modulos['cursos']->categorias($ctx),
            'POST /cursos/categorias/guardar' => $modulos['cursos']->guardarCategoria($ctx),
```

Insert immediately after it:

```php
            'GET /cursos/compras' => $modulos['cursos']->compras($ctx),
            'POST /cursos/compras/aprobar' => $modulos['cursos']->aprobarCompra($ctx),
```

Then find, in `modulos()`:

```php
            'cursos' => new PanelCursosControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(AuditoriaRepo::class),
            ),
```

Replace with:

```php
            'cursos' => new PanelCursosControlador(
                $this->c->obtener(BD::class),
                $this->c->obtener(AuditoriaRepo::class),
                $this->c->obtener(\App\Repositorios\CompraCursoRepo::class),
                $this->c->obtener(\App\Cuenta\ConfirmadorCompra::class),
            ),
```

`CompraCursoRepo::class` is already registered (Task 8). `ConfirmadorCompra::class` is not registered anywhere yet — add it right after the `\App\Wa\ConexionCompartida::class` registration from Task 8:

```php
        $this->contenedor->registrar(
            \App\Cuenta\ConfirmadorCompra::class,
            static fn (Contenedor $c): \App\Cuenta\ConfirmadorCompra => new \App\Cuenta\ConfirmadorCompra(
                $c->obtener(\App\Repositorios\CompraCursoRepo::class),
                $c->obtener(\App\Repositorios\CompradorEnlaceRepo::class),
                $c->obtener(\App\Wa\ConexionCompartida::class),
                $c->obtener(BD::class),
                \App\Soporte\Smtp::desdeEntorno(),
                $urlBase,
            ),
        );
```

(This registration belongs in `Aplicacion.php`, in the same block where `ConexionCompartida::class` and `CompraCursoRepo::class` were registered in Task 8 — not in `Panel.php`, which only holds panel-specific wiring and reads from the shared container built in `Aplicacion.php`.)

- [ ] **Step 7: Add the menu entry in `plantillas/panel/_disposicion.php`**

Find:

```php
    ['/panel/cursos', 'Cursos', 'cursos.ver'],
```

Insert immediately after it:

```php
    ['/panel/cursos/compras', 'Compras', 'cursos.ver'],
```

- [ ] **Step 8: Run the full course-related panel suite**

Run: `vendor/bin/phpunit tests/Integracion/PanelCursosTest.php tests/Integracion/CursosTest.php`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add src/Panel/PanelCursosControlador.php tests/Integracion/PanelCursosTest.php \
        plantillas/panel/cursos_compras.php src/Panel/Panel.php src/Core/Aplicacion.php \
        plantillas/panel/_disposicion.php
git commit -m "$(cat <<'EOF'
feat(cursos): panel de compras con aprobacion manual de respaldo

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: Suite completa y verificación manual

**Files:** none (verification only)

- [ ] **Step 1: Run the entire PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: every test green, including all pre-existing tests (no regressions) and everything added across Tasks 1–12.

- [ ] **Step 2: Confirm the appointment-payment webhook path is genuinely untouched**

Run: `vendor/bin/phpunit tests/Unidad/NotaDeVozDePagoTest.php`
Expected: PASS. This is the one existing test file that touches `WebhookControlador` — a final explicit check that Task 9's extension didn't regress it, beyond the check already done in Task 9 itself.

- [ ] **Step 3: Start the dev server**

Run: `php -S 127.0.0.1:8000 bin/servidor-dev.php`

- [ ] **Step 4: Manually verify the checkout flow end to end**

In the panel, publish a test course with a real price (e.g. $10.000 so a real sandbox Wompi payment is cheap to test with, if sandbox credentials are configured in local `wa_config`). Visit `/cursos/{slug}/comprar`, submit the form, confirm it redirects to a real Wompi checkout link (or shows the "cobro no disponible" error if `wa_config` has no Wompi credentials locally — either is a valid outcome to observe, not a bug). If Wompi credentials are configured, complete a sandbox payment and confirm: the webhook fires, `compras_curso.estado` becomes `pagada`, an email arrives (if local SMTP is configured) with a working `/mis-cursos/completar?token=...` link, and completing that link lands on `/mis-cursos` showing the purchased course.

- [ ] **Step 5: Manually verify the panel fallback**

Create a course purchase without going through Wompi (insert a `pendiente` row directly, or use the checkout with no Wompi configured so it becomes `fallida`, then manually flip its `estado` to `pendiente` for the test). Go to `/panel/cursos/compras`, click "Aprobar a mano", confirm the row becomes `pagada` and (if SMTP is configured) the registration email arrives.

- [ ] **Step 6: Manually verify login and password recovery**

With an account created via the completar-registro flow, log out (`/salir`), log back in via `/entrar`, then use `/recuperar` to request a reset link and confirm it appears in the local mail log or SMTP catch-all (if configured), completes at `/recuperar/confirmar`, and that the old password no longer works afterward.

- [ ] **Step 7: Report status**

No commit for this task — verification only. Report which manual checks passed and which were skipped because local Wompi/SMTP credentials aren't configured (expected in most local setups — this plan's Global Constraints never assumed they would be). Flag anything that didn't match expectations. Remember: per the spec's §11, this module should not go live in production (`cursos_activo = true` plus a published, purchasable course) until SMTP is configured on the VPS and the data-treatment policy is published — this task verifies the code works, not that it's ready to turn on for real customers.

