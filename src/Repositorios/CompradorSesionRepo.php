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
