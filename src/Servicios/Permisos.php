<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Modelos\Usuario;

/**
 * Permisos por rol, contra `roles_permisos`.
 *
 * La matriz vive en base (sembrada en `0003_semillas.sql`), no en código:
 * cambiar quién puede qué no debería exigir un despliegue. Aquí solo se
 * consulta.
 *
 * Las dos asimetrías del ADR-007 salen de esa matriz y no de este archivo,
 * pero conviene recordarlas porque parecen erratas y no lo son:
 *   · el `super_admin` NO aprueba prompts, no verifica normas y no publica
 *     contenido — tiene las llaves técnicas, no la responsabilidad
 *     profesional;
 *   · el `abogado` NO ve credenciales — no las necesita y no debería poder
 *     filtrarlas.
 */
final class Permisos
{
    /** @var array<string,list<string>> caché por rol dentro de la petición */
    private array $cache = [];

    public function __construct(private readonly BD $bd)
    {
    }

    /** @return list<string> claves de permiso del rol */
    public function delRol(string $rol): array
    {
        if (isset($this->cache[$rol])) {
            return $this->cache[$rol];
        }

        $stmt = $this->bd->pdo()->prepare(
            'SELECT p.clave FROM roles_permisos rp
               JOIN roles r    ON r.id = rp.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE r.clave = ?
              ORDER BY p.clave'
        );
        $stmt->execute([$rol]);

        return $this->cache[$rol] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function puede(?Usuario $usuario, string $permiso): bool
    {
        if ($usuario === null || !$usuario->activo) {
            return false;
        }

        return in_array($permiso, $this->delRol($usuario->rol), true);
    }

    /** ¿Tiene al menos uno? Para menús donde varias rutas llevan al módulo. */
    public function puedeAlguno(?Usuario $usuario, string ...$permisos): bool
    {
        foreach ($permisos as $permiso) {
            if ($this->puede($usuario, $permiso)) {
                return true;
            }
        }

        return false;
    }

    /** @throws SinPermisoException */
    public function exigir(?Usuario $usuario, string $permiso): void
    {
        if (!$this->puede($usuario, $permiso)) {
            throw new SinPermisoException($permiso);
        }
    }

    /**
     * Filtra las claves de configuración que este usuario puede editar,
     * según el `rol_minimo` de cada fila.
     *
     * Es el «✔ (parcial)» del abogado en Configuración general: puede entrar
     * al módulo, pero no tocar las filas marcadas como `super_admin`, que son
     * las que apuntan a credenciales y proveedores.
     */
    public function puedeEditarConfiguracion(?Usuario $usuario, string $rolMinimo): bool
    {
        if ($usuario === null) {
            return false;
        }

        if ($usuario->rol === 'super_admin') {
            return true;
        }

        return $rolMinimo !== 'super_admin' && $this->puede($usuario, 'config.editar');
    }
}
