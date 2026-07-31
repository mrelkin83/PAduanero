<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;
use App\Modelos\Usuario;
use App\Soporte\Cifrado;

/**
 * Todo el SQL de `usuarios` vive aquí y solo aquí (docs/CONTRATOS.md).
 */
final class UsuarioRepo
{
    private const CAMPOS = 'u.id, u.email, u.nombre, u.rol_id, u.chatwoot_agent_id,
                            u.totp_activo, u.activo, u.intentos_fallidos, u.bloqueado_hasta,
                            r.clave AS rol';

    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
    ) {
    }

    public function porId(string $id): ?Usuario
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Usuario::desdeFila($fila);
    }

    public function porEmail(string $email): ?Usuario
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.email = ?'
        );
        $stmt->execute([$email]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Usuario::desdeFila($fila);
    }

    /** @return list<Usuario> */
    public function listar(): array
    {
        $filas = $this->bd->pdo()->query(
            'SELECT ' . self::CAMPOS . ' FROM usuarios u JOIN roles r ON r.id = u.rol_id
              ORDER BY u.activo DESC, u.nombre'
        )->fetchAll();

        return array_map(static fn (array $f): Usuario => Usuario::desdeFila($f), $filas);
    }

    public function crear(string $email, string $nombre, string $password, int $rolId): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        if ($hash === false) {
            throw new \RuntimeException('No se pudo generar el hash Argon2id.');
        }

        $id = $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO usuarios (id, email, nombre, password_hash, rol_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $email, $nombre, $hash, $rolId]);

        return (string) $id;
    }

    /**
     * Comprueba la contraseña.
     *
     * Cuando el usuario no existe se gasta igualmente el tiempo de un
     * `password_verify` contra un hash de descarte. Sin eso, la diferencia de
     * milisegundos entre «no existe» y «existe con contraseña mala» permite
     * enumerar qué correos tienen cuenta en el panel.
     */
    public function verificarPassword(string $email, string $password): bool
    {
        $stmt = $this->bd->pdo()->prepare('SELECT password_hash FROM usuarios WHERE email = ? AND activo = 1');
        $stmt->execute([$email]);
        $hash = $stmt->fetchColumn();

        if ($hash === false) {
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$'
                . 'ZGVzY2FydGVkZXNjYXJ0ZQ$0000000000000000000000000000000000000000000');

            return false;
        }

        return password_verify($password, (string) $hash);
    }

    public function cambiarPassword(string $usuarioId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        if ($hash === false) {
            throw new \RuntimeException('No se pudo generar el hash Argon2id.');
        }

        $this->bd->pdo()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $usuarioId]);
    }

    public function registrarAcceso(string $usuarioId): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE usuarios SET ultimo_acceso_en = UTC_TIMESTAMP(), intentos_fallidos = 0,
                                 bloqueado_hasta = NULL
              WHERE id = ?'
        )->execute([$usuarioId]);
    }

    /**
     * Suma un intento fallido y bloquea a partir del quinto, con espera
     * creciente (docs/PANEL_ADMIN.md §4.3).
     *
     * @return int minutos de bloqueo aplicados, 0 si todavía no bloquea
     */
    public function registrarFallo(string $email): int
    {
        $pdo = $this->bd->pdo();

        $pdo->prepare('UPDATE usuarios SET intentos_fallidos = intentos_fallidos + 1 WHERE email = ?')
            ->execute([$email]);

        $stmt = $pdo->prepare('SELECT intentos_fallidos FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $intentos = (int) $stmt->fetchColumn();

        if ($intentos < 5) {
            return 0;
        }

        // 5→1 min, 6→2, 7→4, 8→8… con tope de una hora. Creciente para que
        // la fuerza bruta se vuelva inviable sin dejar la cuenta muerta.
        $minutos = min(60, 2 ** ($intentos - 5));

        $pdo->prepare(
            'UPDATE usuarios SET bloqueado_hasta = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE email = ?'
        )->execute([$minutos, $email]);

        return $minutos;
    }

    /** @return int|null minutos que faltan, o null si no está bloqueado */
    public function minutosDeBloqueo(string $email): ?int
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), bloqueado_hasta) AS faltan
               FROM usuarios WHERE email = ? AND bloqueado_hasta > UTC_TIMESTAMP()'
        );
        $stmt->execute([$email]);
        $faltan = $stmt->fetchColumn();

        return $faltan === false ? null : max(1, (int) $faltan);
    }

    public function activar(string $usuarioId, bool $activo): void
    {
        $this->bd->pdo()->prepare('UPDATE usuarios SET activo = ? WHERE id = ?')
            ->execute([(int) $activo, $usuarioId]);
    }

    public function guardarChatwootAgentId(string $usuarioId, int $agenteId): void
    {
        $this->bd->pdo()->prepare('UPDATE usuarios SET chatwoot_agent_id = ? WHERE id = ?')
            ->execute([$agenteId, $usuarioId]);
    }

    // ── TOTP ─────────────────────────────────────────────────────────────

    /** Guarda el secreto cifrado, todavía sin activar. */
    public function guardarSecretoTotp(string $usuarioId, string $secretoBase32): void
    {
        $stmt = $this->bd->pdo()->prepare(
            'UPDATE usuarios SET totp_secret_cifrado = :blob, totp_activo = 0 WHERE id = :id'
        );
        $stmt->bindValue(':blob', $this->cifrado->cifrar($secretoBase32), \PDO::PARAM_LOB);
        $stmt->bindValue(':id', $usuarioId);
        $stmt->execute();
    }

    public function secretoTotp(string $usuarioId): ?string
    {
        $stmt = $this->bd->pdo()->prepare('SELECT totp_secret_cifrado FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $blob = $stmt->fetchColumn();

        if ($blob === false || $blob === null) {
            return null;
        }

        $binario = is_resource($blob) ? (string) stream_get_contents($blob) : (string) $blob;

        return $binario === '' ? null : $this->cifrado->descifrar($binario);
    }

    /** Solo tras verificar un código: activar antes dejaría fuera al usuario. */
    public function activarTotp(string $usuarioId): void
    {
        $this->bd->pdo()->prepare('UPDATE usuarios SET totp_activo = 1 WHERE id = ?')
            ->execute([$usuarioId]);
    }

    public function desactivarTotp(string $usuarioId): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE usuarios SET totp_activo = 0, totp_secret_cifrado = NULL WHERE id = ?'
        )->execute([$usuarioId]);
    }
}
