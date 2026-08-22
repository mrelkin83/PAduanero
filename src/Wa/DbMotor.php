<?php

declare(strict_types=1);

namespace App\Wa;

use App\Core\BD;
use ElkinLinan\WhatsappAiEngine\Ports\DbPort;
use PDO;

/**
 * La conexión de PAduanero detrás del contrato del motor de WhatsApp.
 *
 * Es la excepción consciente a «el SQL vive en Repositorios y solo ahí»: el
 * motor vendorizado trae sus consultas consigo (tablas wa_*) y este puerto
 * solo le presta la conexión. El SQL PROPIO de PAduanero sigue donde estaba.
 */
final class DbMotor implements DbPort
{
    public function __construct(private readonly BD $bd)
    {
    }

    private function pdo(): PDO
    {
        return $this->bd->pdo();
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        $fila = $st->fetch();

        return $fila === false ? null : $fila;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);

        return $st->fetchAll() ?: [];
    }

    public function insert(string $sql, array $params = []): int
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);

        return (int) $this->pdo()->lastInsertId();
    }

    public function query(string $sql, array $params = []): int
    {
        // El contrato pide FILAS AFECTADAS: el guard atómico del paso a
        // confirmación y el consumo de enlaces de un solo uso dependen de esto.
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);

        return $st->rowCount();
    }

    public function beginTransaction(): void
    {
        $this->pdo()->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo()->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }

    /** Un solo negocio: no hay master ni otras bases. */
    public function maestra(): DbPort
    {
        return $this;
    }

    public function conectarA(?string $baseDatos): DbPort
    {
        return $this;
    }
}
