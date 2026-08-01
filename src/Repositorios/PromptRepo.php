<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

/**
 * Todo el SQL de `prompts`.
 *
 * Existe porque `ConstructorPrompt` vive en `src/Motor/` y ahí no puede haber
 * SQL — lo comprueba `ArquitecturaTest`. Que la prueba de arquitectura
 * atrapara esto antes que una revisión humana es exactamente para lo que se
 * escribió.
 *
 * Un prompt nace inactivo y solo el abogado lo activa (ADR-008). Este
 * repositorio **no tiene método de activación**: eso vive en el módulo del
 * panel, con su comprobación de permiso y su fila en `auditoria`.
 */
final class PromptRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    /** @return array{id:string,version:int,contenido:string}|null */
    public function activo(string $clave): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, version, contenido FROM prompts WHERE clave = ? AND activo = 1 LIMIT 1'
        );
        $stmt->execute([$clave]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            return null;
        }

        return [
            'id' => (string) $fila['id'],
            'version' => (int) $fila['version'],
            'contenido' => (string) $fila['contenido'],
        ];
    }

    /**
     * Una versión concreta, activa o no.
     *
     * Es lo que permite correr el conjunto dorado contra una versión que
     * todavía no está activa — y sin eso el ciclo no cierra: una versión no se
     * puede activar hasta tener dorado verde, y no se podría probar si hubiera
     * que activarla antes.
     *
     * @return array{id:string,version:int,contenido:string}|null
     */
    public function porVersion(string $clave, int $version): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, version, contenido FROM prompts WHERE clave = ? AND version = ? LIMIT 1'
        );
        $stmt->execute([$clave, $version]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            return null;
        }

        return [
            'id' => (string) $fila['id'],
            'version' => (int) $fila['version'],
            'contenido' => (string) $fila['contenido'],
        ];
    }

    /** @return array{id:string,version:int,contenido:string}|null */
    public function porId(string $id): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, version, contenido FROM prompts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            return null;
        }

        return [
            'id' => (string) $fila['id'],
            'version' => (int) $fila['version'],
            'contenido' => (string) $fila['contenido'],
        ];
    }

    /**
     * Activa una versión y desactiva la anterior, en una transacción.
     *
     * En este orden y atómico porque `ux_prompt_activo` solo admite una activa
     * por clave: bajar la vieja y subir la nueva por separado deja, si algo
     * falla en medio, al motor sin prompt — y sin prompt el motor no habla.
     *
     * **No comprueba permisos ni el gate dorado.** Eso lo hace el controlador,
     * que es quien sabe quién está pulsando el botón.
     */
    public function activar(string $id, string $clave, string $aprobadoPor): void
    {
        $pdo = $this->bd->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('UPDATE prompts SET activo = 0 WHERE clave = ?')->execute([$clave]);

            $pdo->prepare(
                'UPDATE prompts SET activo = 1, aprobado_por = ?, aprobado_en = NOW() WHERE id = ?'
            )->execute([$aprobadoPor, $id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function versiones(string $clave): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT p.id, p.version, p.notas_cambio, p.activo, p.aprobado_en, p.creado_en,
                    u.nombre AS aprobado_por_nombre
               FROM prompts p
               LEFT JOIN usuarios u ON u.id = p.aprobado_por
              WHERE p.clave = ?
              ORDER BY p.version DESC'
        );
        $stmt->execute([$clave]);

        return $stmt->fetchAll();
    }

    /**
     * Crea una versión nueva, **inactiva** (ADR-008).
     *
     * El número de versión se toma como `MAX + 1` y aquí sí es correcto, a
     * diferencia del radicado: no hay concurrencia real —dos personas no
     * editan el prompt a la vez— y si la hubiera, el `UNIQUE (clave, version)`
     * hace fallar la segunda escritura de forma visible, sin entregar dos
     * veces el mismo número como haría en `casos`.
     */
    public function crearVersion(string $clave, string $contenido, ?string $notas, ?string $creadoPor): string
    {
        $pdo = $this->bd->pdo();
        $id = (string) $pdo->query('SELECT UUID()')->fetchColumn();

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM prompts WHERE clave = ?');
        $stmt->execute([$clave]);
        $version = (int) $stmt->fetchColumn();

        $pdo->prepare(
            'INSERT INTO prompts (id, clave, version, contenido, notas_cambio, creado_por, activo)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        )->execute([$id, $clave, $version, $contenido, $notas, $creadoPor]);

        return $id;
    }
}
