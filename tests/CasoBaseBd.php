<?php

declare(strict_types=1);

namespace Pruebas;

use App\Core\BD;
use App\Core\Migrador;
use App\Soporte\Entorno;
use PHPUnit\Framework\TestCase;

/**
 * Base para las pruebas que tocan MySQL.
 *
 * La base de pruebas se recrea desde db/migraciones/ en la primera prueba de
 * la corrida (docs/PRUEBAS.md §6). Si MySQL no está disponible, las pruebas
 * se marcan como omitidas en vez de fallar: en una máquina de desarrollo sin
 * MySQL levantado, un fallo rojo sería ruido, no información.
 */
abstract class CasoBaseBd extends TestCase
{
    protected BD $bd;
    private static bool $migrada = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Otra clase pudo haber vaciado el entorno a propósito.
        pruebas_cargar_entorno();

        $base = Entorno::obtener('DB_NAME', '');
        if (!is_string($base) || !str_ends_with($base, '_pruebas')) {
            self::fail('DB_NAME debe terminar en _pruebas. Revisar tests/arranque.php.');
        }

        $this->bd = BD::desdeEntorno();

        try {
            $this->bd->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'MySQL no disponible para pruebas de integración: ' . $e->getMessage()
            );
        }

        if (!self::$migrada) {
            $this->recrear();
            self::$migrada = true;
        }

        $this->limpiar();
    }

    /** Deja la base vacía y vuelve a aplicar las migraciones desde cero. */
    private function recrear(): void
    {
        $pdo = $this->bd->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tablas = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tablas as $tabla) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $tabla . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        (new Migrador($this->bd, dirname(__DIR__) . '/db/migraciones'))->migrar();
    }

    /**
     * Vacía las tablas transaccionales entre pruebas, conservando las
     * semillas: roles, permisos, configuraciones, modalidades y horarios son
     * la configuración del negocio, no datos de prueba.
     */
    protected function limpiar(): void
    {
        $pdo = $this->bd->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ([
            'pagos', 'consultas', 'caso_partes', 'casos', 'consentimientos',
            'conversacion_estado', 'contactos', 'eventos_outbox', 'auditoria',
            'credenciales', 'sesiones', 'usuarios', 'intentos_acceso',
            'configuraciones_historial', 'secuencias',
            'kb_chunks', 'kb_documentos', 'consumo_ia',
        ] as $tabla) {
            $pdo->exec('TRUNCATE TABLE `' . $tabla . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
