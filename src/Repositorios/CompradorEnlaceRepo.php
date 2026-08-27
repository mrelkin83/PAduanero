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
