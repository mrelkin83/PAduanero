<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;
use App\Soporte\Logger;

/**
 * Bitácora. Quién cambió qué, cuándo y desde dónde.
 *
 * El detalle pasa por el redactor del logger antes de guardarse: la auditoría
 * registra que alguien leyó una credencial, nunca cuál era su valor.
 */
final class AuditoriaRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    /** @param array<string,mixed> $detalle */
    public function registrar(
        string $entidad,
        ?string $entidadId,
        string $accion,
        string $actor,
        array $detalle = [],
        ?string $ip = null,
    ): void {
        if ($ip !== null) {
            $detalle['ip'] = $ip;
        }

        $codificado = json_encode(Logger::redactar($detalle), JSON_UNESCAPED_UNICODE);

        $this->bd->pdo()->prepare(
            'INSERT INTO auditoria (entidad, entidad_id, accion, actor, detalle) VALUES (?, ?, ?, ?, ?)'
        )->execute([$entidad, $entidadId, $accion, $actor, $codificado === false ? null : $codificado]);
    }

    /**
     * @param  array{entidad?:string,accion?:string,actor?:string} $filtros
     * @return list<array<string,mixed>>
     */
    public function listar(array $filtros = [], int $limite = 100, int $desplazamiento = 0): array
    {
        $condiciones = [];
        $parametros = [];

        foreach (['entidad', 'accion', 'actor'] as $campo) {
            if (($filtros[$campo] ?? '') !== '') {
                $condiciones[] = "{$campo} = ?";
                $parametros[] = $filtros[$campo];
            }
        }

        $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        // LIMIT y OFFSET van interpolados como enteros ya casteados, no
        // concatenados desde la petición: MySQL no admite parámetros ahí con
        // sentencias preparadas reales.
        $sql = 'SELECT * FROM auditoria' . $where . ' ORDER BY id DESC LIMIT '
            . max(1, min(500, $limite)) . ' OFFSET ' . max(0, $desplazamiento);

        $stmt = $this->bd->pdo()->prepare($sql);
        $stmt->execute($parametros);

        return $stmt->fetchAll();
    }

    /** @return list<string> valores distintos, para poblar los filtros */
    public function valoresDe(string $campo): array
    {
        if (!in_array($campo, ['entidad', 'accion', 'actor'], true)) {
            throw new \InvalidArgumentException("Campo no filtrable: {$campo}");
        }

        return $this->bd->pdo()
            ->query("SELECT DISTINCT {$campo} FROM auditoria ORDER BY {$campo}")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<array<string,mixed>> historial de `configuraciones` */
    public function historialConfiguracion(int $limite = 100): array
    {
        $sql = 'SELECT h.*, u.nombre AS usuario_nombre
                  FROM configuraciones_historial h
                  LEFT JOIN usuarios u ON u.id = h.usuario_id
                 ORDER BY h.id DESC LIMIT ' . max(1, min(500, $limite));

        return $this->bd->pdo()->query($sql)->fetchAll();
    }
}
