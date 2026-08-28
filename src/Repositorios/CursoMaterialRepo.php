<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

final class CursoMaterialRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    public function crear(string $leccionId, string $nombre, string $archivo, string $extension, int $tamanioBytes): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $siguienteOrden = $this->bd->pdo()->prepare(
            'SELECT COALESCE(MAX(orden), 0) + 1 FROM curso_materiales WHERE leccion_id = ?'
        );
        $siguienteOrden->execute([$leccionId]);
        $orden = (int) $siguienteOrden->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO curso_materiales (id, leccion_id, nombre, archivo, extension, tamanio_bytes, orden)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $leccionId, $nombre, $archivo, $extension, $tamanioBytes, $orden]);

        return $id;
    }

    /** @return array<string,mixed>|null */
    public function porId(string $id): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM curso_materiales WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /** @return list<array<string,mixed>> */
    public function deLeccion(string $leccionId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT * FROM curso_materiales WHERE leccion_id = ? ORDER BY orden'
        );
        $stmt->execute([$leccionId]);

        return $stmt->fetchAll();
    }

    public function eliminar(string $id): void
    {
        $this->bd->pdo()->prepare('DELETE FROM curso_materiales WHERE id = ?')->execute([$id]);
    }
}
