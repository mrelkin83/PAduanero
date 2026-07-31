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
            $this->capturarSemillas();
            self::$migrada = true;
        }

        $this->limpiar();
        $this->restaurarSemillas();
    }

    /**
     * Tablas de semillas que las pruebas modifican y hay que devolver a su
     * sitio.
     *
     * No se vacían en `limpiar()` porque son la configuración del negocio, no
     * datos de prueba — pero eso significa que un `set()` o un `DELETE` de
     * una prueba sobrevive a la siguiente, y el fallo aparece en otra clase.
     * Se guarda una copia justo después de migrar y se restaura entre casos.
     */
    private const TABLAS_SEMILLA = [
        'configuraciones',
        'landing_bloques',
        // Las toca el módulo de tarifas: sin restaurarlas, una prueba que
        // sube el precio deja a la siguiente comprobando contra $450.000.
        // El orden importa — `consultas` tiene una clave foránea hacia
        // `modalidades_asesoria`, y `limpiar()` la vacía justo antes.
        'modalidades_asesoria',
        'horarios',
    ];

    /** @var array<string,list<array<string,mixed>>> */
    private static array $semillas = [];

    private function capturarSemillas(): void
    {
        foreach (self::TABLAS_SEMILLA as $tabla) {
            self::$semillas[$tabla] = $this->bd->pdo()
                ->query("SELECT * FROM `{$tabla}`")
                ->fetchAll();
        }
    }

    private function restaurarSemillas(): void
    {
        $pdo = $this->bd->pdo();

        foreach (self::$semillas as $tabla => $filas) {
            $pdo->exec("DELETE FROM `{$tabla}`");

            if ($filas === []) {
                continue;
            }

            $columnas = array_keys($filas[0]);
            $lista = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columnas));
            $huecos = implode(', ', array_fill(0, count($columnas), '?'));

            $stmt = $pdo->prepare("INSERT INTO `{$tabla}` ({$lista}) VALUES ({$huecos})");

            foreach ($filas as $fila) {
                $stmt->execute(array_values($fila));
            }
        }
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
            'configuraciones_historial', 'secuencias', 'eventos_landing',
            'kb_chunks', 'kb_documentos', 'consumo_ia',
        ] as $tabla) {
            $pdo->exec('TRUNCATE TABLE `' . $tabla . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
