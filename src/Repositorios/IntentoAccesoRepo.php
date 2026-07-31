<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

/**
 * Rate limit por IP (docs/PANEL_ADMIN.md §4.4).
 *
 * Complementa al bloqueo por cuenta de `usuarios.intentos_fallidos`, que no
 * cubre el caso real: quien prueba mil contraseñas contra mil correos
 * distintos nunca dispara el bloqueo por usuario, porque ninguna cuenta llega
 * a cinco intentos.
 */
final class IntentoAccesoRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    public function registrar(string $accion, ?string $ip, bool $exito, ?string $usuario = null): void
    {
        // INET6_ATON guarda IPv4 e IPv6 en el mismo VARBINARY. Con IP
        // ausente (CLI, proxy mal configurado) se guarda NULL en vez de
        // inventar un valor.
        $this->bd->pdo()->prepare(
            'INSERT INTO intentos_acceso (ip, accion, exito, usuario)
             VALUES (INET6_ATON(?), ?, ?, ?)'
        )->execute([$ip, $accion, (int) $exito, $usuario !== null ? mb_substr($usuario, 0, 180) : null]);
    }

    /** Fallos desde esa IP en la ventana. */
    public function fallosRecientes(string $accion, ?string $ip, int $minutos = 15): int
    {
        if ($ip === null) {
            return 0;
        }

        $stmt = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM intentos_acceso
              WHERE ip = INET6_ATON(?) AND accion = ? AND exito = 0
                AND creado_en > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([$ip, $accion, $minutos]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * La IP es dato personal bajo la Ley 1581 de 2012: no se guarda
     * indefinidamente. Lo llama el cron.
     */
    public function purgar(int $dias = 30): int
    {
        $stmt = $this->bd->pdo()->prepare(
            'DELETE FROM intentos_acceso WHERE creado_en < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)'
        );
        $stmt->execute([$dias]);

        return $stmt->rowCount();
    }
}
