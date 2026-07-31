<?php

declare(strict_types=1);

namespace App\Core;

use App\Excepciones\ConfiguracionFatalException;
use App\Soporte\Entorno;
use PDO;
use PDOException;

/**
 * Conexión PDO a MySQL. Perezosa: no conecta hasta que alguien pide.
 *
 * Sin ORM y sin capa de abstracción (docs/PLAN_BUILD.md). El SQL vive en
 * `src/Repositorios/` y solo ahí.
 */
final class BD
{
    public const ERROR_DUPLICADO = 1062;

    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $base,
        private readonly string $usuario,
        private readonly string $password,
        private readonly string $charset = 'utf8mb4',
    ) {
    }

    public static function desdeEntorno(): self
    {
        return new self(
            Entorno::obtener('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
            (int) (Entorno::obtener('DB_PORT', '3306') ?? '3306'),
            Entorno::exigir('DB_NAME'),
            Entorno::exigir('DB_USER'),
            Entorno::obtener('DB_PASS', '') ?? '',
            Entorno::obtener('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4',
        );
    }

    public function nombreBase(): string
    {
        return $this->base;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->puerto,
            $this->base,
            $this->charset,
        );

        try {
            $this->pdo = new PDO($dsn, $this->usuario, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Sentencias preparadas de verdad, no emuladas: la emulación
                // interpola en el cliente y ahí es donde vuelve la inyección.
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            // El mensaje de PDO trae el DSN, y el DSN trae el usuario.
            throw new ConfiguracionFatalException(
                'No se pudo conectar a MySQL. Revisar DB_HOST, DB_NAME y credenciales.',
                0,
                $e,
            );
        }

        // La base guarda UTC; la aplicación convierte a Bogotá al presentar.
        $this->pdo->exec("SET time_zone = '+00:00'");
        // STRICT_ALL_TABLES: sin esto MySQL trunca en silencio en vez de
        // fallar, que es como un radicado de 25 caracteres entra en una
        // columna de 20 y nadie se entera.
        $this->pdo->exec(
            "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'"
        );

        return $this->pdo;
    }

    /** ¿La excepción es una violación de índice único? (MySQL 1062, no 23505) */
    public static function esDuplicado(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === self::ERROR_DUPLICADO;
    }

    /**
     * Ejecuta el callback dentro de una transacción. Confirma o revierte.
     *
     * @template T
     * @param  callable(PDO):T $trabajo
     * @return T
     */
    public function enTransaccion(callable $trabajo): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $resultado = $trabajo($pdo);
            $pdo->commit();

            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
