<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runner de migraciones (ADR-013).
 *
 * Numeradas, idempotentes y aditivas. La tabla `migraciones` guarda el hash
 * del archivo aplicado, y si el contenido cambió después **se aborta** en vez
 * de reaplicar: reaplicar una migración editada deja la base en un estado que
 * no corresponde a ninguna versión del código.
 */
final class Migrador
{
    /** @var list<string> */
    private array $mensajes = [];

    public function __construct(
        private readonly BD $bd,
        private readonly string $directorio,
    ) {
    }

    /** @return list<string> bitácora de lo ocurrido, para imprimir */
    public function migrar(): array
    {
        $this->mensajes = [];
        $this->crearTablaControl();

        $aplicadas = $this->aplicadas();
        $archivos = $this->archivos();

        if ($archivos === []) {
            $this->anotar("No hay migraciones en {$this->directorio}.");

            return $this->mensajes;
        }

        $this->verificarIntegridad($archivos, $aplicadas);

        $pendientes = array_filter(
            $archivos,
            static fn (string $ruta): bool => !isset($aplicadas[basename($ruta)]),
        );

        if ($pendientes === []) {
            $this->anotar('Todo al día: ' . count($aplicadas) . ' migraciones aplicadas.');

            return $this->mensajes;
        }

        foreach ($pendientes as $ruta) {
            $this->aplicar($ruta);
        }

        return $this->mensajes;
    }

    private function crearTablaControl(): void
    {
        $this->bd->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migraciones (
               version    VARCHAR(100) NOT NULL,
               hash       CHAR(64)     NOT NULL,
               aplicada_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (version)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );
    }

    /** @return array<string,string> version => hash */
    private function aplicadas(): array
    {
        $filas = $this->bd->pdo()->query('SELECT version, hash FROM migraciones')->fetchAll();

        $salida = [];
        foreach ($filas as $fila) {
            $salida[(string) $fila['version']] = (string) $fila['hash'];
        }

        return $salida;
    }

    /** @return list<string> rutas ordenadas por nombre */
    private function archivos(): array
    {
        $encontrados = glob($this->directorio . '/*.sql');

        if ($encontrados === false) {
            return [];
        }

        sort($encontrados, SORT_STRING);

        return array_values($encontrados);
    }

    /**
     * @param list<string>         $archivos
     * @param array<string,string> $aplicadas
     */
    private function verificarIntegridad(array $archivos, array $aplicadas): void
    {
        $presentes = [];

        foreach ($archivos as $ruta) {
            $version = basename($ruta);
            $presentes[$version] = true;

            $registrado = $aplicadas[$version] ?? null;
            if ($registrado === null) {
                continue;
            }

            $actual = $this->hash($ruta);
            if ($actual !== $registrado) {
                throw new \RuntimeException(
                    "La migración «{$version}» ya está aplicada pero su contenido cambió.\n"
                    . "  registrado: {$registrado}\n"
                    . "  actual:     {$actual}\n"
                    . 'Las migraciones son inmutables: crea una nueva en vez de editar esta.'
                );
            }
        }

        // Una migración aplicada cuyo archivo desapareció significa que la
        // base va por delante del código — típicamente un rollback a medias.
        foreach (array_keys($aplicadas) as $version) {
            if (!isset($presentes[$version])) {
                throw new \RuntimeException(
                    "La migración «{$version}» está aplicada en la base pero su archivo no existe. "
                    . 'La base va por delante del código: revisar el despliegue antes de continuar.'
                );
            }
        }
    }

    private function aplicar(string $ruta): void
    {
        $version = basename($ruta);
        $sql = (string) file_get_contents($ruta);
        $pdo = $this->bd->pdo();

        // MySQL hace DDL implícitamente transaccional por sentencia: un
        // CREATE TABLE no se puede revertir. Por eso las migraciones son
        // aditivas e idempotentes (IF NOT EXISTS, INSERT IGNORE): si una se
        // corta a la mitad, volver a correrla termina el trabajo.
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Falló la migración «{$version}»: " . $e->getMessage(),
                0,
                $e,
            );
        }

        $pdo->prepare('INSERT INTO migraciones (version, hash) VALUES (?, ?)')
            ->execute([$version, $this->hash($ruta)]);

        $this->anotar("Aplicada {$version}");
    }

    private function hash(string $ruta): string
    {
        // Normalizar los saltos de línea: este repositorio se edita en
        // Windows y se despliega en Linux. Sin esto, el mismo archivo daría
        // hashes distintos en cada sitio y migrar.php abortaría en el VPS.
        $contenido = str_replace("\r\n", "\n", (string) file_get_contents($ruta));

        return hash('sha256', $contenido);
    }

    private function anotar(string $mensaje): void
    {
        $this->mensajes[] = $mensaje;
    }
}
