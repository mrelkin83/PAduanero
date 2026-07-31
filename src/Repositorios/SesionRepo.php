<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

/**
 * Sesiones del panel en base, con **hash** del token.
 *
 * El token en claro solo existe en la cookie del navegador. Si alguien lee la
 * tabla `sesiones` —un volcado, un respaldo, una inyección— no puede
 * suplantar a nadie, porque de un SHA-256 no se vuelve atrás
 * (docs/PANEL_ADMIN.md §4.2).
 *
 * Aquí no hace falta pepper, al contrario que en `telefono_hash`: el token es
 * aleatorio de 256 bits, no un dato adivinable como un número de celular.
 */
final class SesionRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    /** @return string el token EN CLARO, que solo se ve aquí y en la cookie */
    public function crear(string $usuarioId, int $duracionMinutos, ?string $ip, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));

        $this->bd->pdo()->prepare(
            'INSERT INTO sesiones (usuario_id, token_hash, ip, user_agent, expira_en)
             VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))'
        )->execute([
            $usuarioId,
            hash('sha256', $token),
            $ip,
            $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            $duracionMinutos,
        ]);

        return $token;
    }

    /** @return array{id:string,usuario_id:string}|null */
    public function vigente(string $token): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, usuario_id FROM sesiones
              WHERE token_hash = ? AND revocada_en IS NULL AND expira_en > UTC_TIMESTAMP()'
        );
        $stmt->execute([hash('sha256', $token)]);
        $fila = $stmt->fetch();

        return $fila === false ? null : ['id' => (string) $fila['id'], 'usuario_id' => (string) $fila['usuario_id']];
    }

    public function revocar(string $token): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE sesiones SET revocada_en = UTC_TIMESTAMP() WHERE token_hash = ? AND revocada_en IS NULL'
        )->execute([hash('sha256', $token)]);
    }

    /**
     * Revoca todas las sesiones de un usuario.
     *
     * Se llama al cambiar la contraseña: si no, quien robó la sesión sigue
     * dentro después de que la víctima «arregle» el problema. También es el
     * botón de revocación remota del panel.
     */
    public function revocarTodas(string $usuarioId): int
    {
        $stmt = $this->bd->pdo()->prepare(
            'UPDATE sesiones SET revocada_en = UTC_TIMESTAMP()
              WHERE usuario_id = ? AND revocada_en IS NULL'
        );
        $stmt->execute([$usuarioId]);

        return $stmt->rowCount();
    }

    /** @return list<array<string,mixed>> sesiones vivas, para el panel */
    public function activasDe(string $usuarioId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, ip, user_agent, creado_en, expira_en FROM sesiones
              WHERE usuario_id = ? AND revocada_en IS NULL AND expira_en > UTC_TIMESTAMP()
              ORDER BY creado_en DESC'
        );
        $stmt->execute([$usuarioId]);

        return $stmt->fetchAll();
    }

    /** Purga lo caducado. Lo llama el cron junto con las reservas vencidas. */
    public function purgar(int $diasRetencion = 30): int
    {
        $stmt = $this->bd->pdo()->prepare(
            'DELETE FROM sesiones WHERE expira_en < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)'
        );
        $stmt->execute([$diasRetencion]);

        return $stmt->rowCount();
    }
}
