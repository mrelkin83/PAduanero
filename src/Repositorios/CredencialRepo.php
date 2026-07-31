<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

/**
 * Listado de credenciales **para pintar el panel**. Solo lectura.
 *
 * Deliberadamente separado de `App\Servicios\Credenciales`, que es quien
 * descifra. La diferencia no es de estilo: el `SELECT` de aquí **no incluye
 * `valor_cifrado`**, así que por esta ruta es estructuralmente imposible que
 * un secreto llegue a una plantilla. No hay que acordarse de no imprimirlo —
 * no está.
 */
final class CredencialRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    /**
     * Máscaras y metadatos de un servicio.
     *
     * @return list<array{entorno:string,clave:string,mascara:string,activo:int,
     *                    key_version:int,ultima_prueba_en:?string,ultima_prueba_ok:?int,
     *                    actualizado_en:string}>
     */
    public function resumen(string $servicio): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT entorno, clave, mascara, activo, key_version,
                    ultima_prueba_en, ultima_prueba_ok, actualizado_en
               FROM credenciales
              WHERE servicio = ?
              ORDER BY entorno, clave'
        );
        $stmt->execute([$servicio]);

        return $stmt->fetchAll();
    }

    /** @return list<string> servicios con alguna credencial guardada */
    public function servicios(): array
    {
        return $this->bd->pdo()
            ->query('SELECT DISTINCT servicio FROM credenciales ORDER BY servicio')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }
}
