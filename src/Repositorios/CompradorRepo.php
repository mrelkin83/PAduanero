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

    public function numeroDocumento(string $compradorId): ?string
    {
        $stmt = $this->bd->pdo()->prepare('SELECT numero_documento_cifrado FROM compradores WHERE id = ?');
        $stmt->execute([$compradorId]);
        $blob = $stmt->fetchColumn();

        return $blob === false ? null : $this->cifrado->descifrar((string) $blob);
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
