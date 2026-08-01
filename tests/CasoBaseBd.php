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
        // El catálogo de IA es semilla desde 0007. Las pruebas del
        // descubrimiento dan de alta y retiran modelos, así que hay que
        // devolverlo a su sitio. `proveedores_ia` va ANTES que `modelos_ia`:
        // el DELETE del padre arrastra a los hijos por la foránea, y
        // restaurar al revés dejaría los modelos sin proveedor.
        'proveedores_ia',
        'modelos_ia',
    ];

    /** @var array<string,list<array<string,mixed>>> */
    private static array $semillas = [];

    /** @var array<string,list<string>> columnas reales por tabla */
    private static array $columnas = [];

    private function capturarSemillas(): void
    {
        foreach (self::TABLAS_SEMILLA as $tabla) {
            // Columnas reales, sin las generadas. `SELECT *` las traería y
            // el INSERT de vuelta moriría con MySQL 3105: no se puede
            // escribir en una columna GENERATED. El proyecto las usa para
            // emular índices únicos parciales —`primario_key`, `slot_unico`,
            // `activo_key`—, así que esto no es un caso raro sino el patrón
            // de la casa.
            $columnas = $this->columnasReales($tabla);
            self::$columnas[$tabla] = $columnas;

            $lista = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columnas));

            self::$semillas[$tabla] = $this->bd->pdo()
                ->query("SELECT {$lista} FROM `{$tabla}`")
                ->fetchAll();
        }
    }

    /**
     * @return list<string>
     *
     * El filtro va sobre `GENERATION_EXPRESSION` y NO sobre `EXTRA`. `EXTRA`
     * dice `STORED GENERATED` para una columna generada, pero también
     * `DEFAULT_GENERATED` para cualquier columna con `DEFAULT (UUID())` o
     * `DEFAULT CURRENT_TIMESTAMP` — que en este esquema son casi todas las
     * claves primarias. Filtrar por la subcadena «GENERATED» se lleva por
     * delante los `id`, las semillas se restauran con UUID nuevos y revientan
     * las foráneas. `GENERATION_EXPRESSION` está vacía salvo en columnas
     * realmente generadas.
     */
    private function columnasReales(string $tabla): array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
              ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([$tabla]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function restaurarSemillas(): void
    {
        $pdo = $this->bd->pdo();

        foreach (self::$semillas as $tabla => $filas) {
            $pdo->exec("DELETE FROM `{$tabla}`");

            if ($filas === []) {
                continue;
            }

            $columnas = self::$columnas[$tabla] ?? array_keys($filas[0]);
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
            'kb_chunks', 'kb_documentos', 'consumo_ia', 'sincronizaciones_modelos',
        ] as $tabla) {
            $pdo->exec('TRUNCATE TABLE `' . $tabla . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
