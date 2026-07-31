<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Modelos\Configuracion;

/**
 * Config sobre MySQL con caché de dos niveles y TTL de 60 s.
 *
 * APCu si está disponible; archivo en storage/cache/ si no. El fallback
 * existe para desarrollo en Windows — en el VPS APCu es requisito
 * (`pecl install apcu`), porque sin memoria compartida cada petición de
 * PHP-FPM vuelve a leer la tabla.
 *
 * La invalidación entre procesos va por el centinela `storage/config.sentinel`:
 * PHP-FPM y el worker del outbox son procesos distintos y APCu no se comparte
 * entre ellos. Al escribir se toca el archivo; al leer se compara su `mtime`
 * contra el de la caché. Es lo que hace que apagar la IA desde el panel llegue
 * al worker sin reiniciarlo.
 */
final class ConfigMysql implements Config
{
    private const TTL = 60;
    private const PREFIJO = 'pedro.config.';

    /** @var array<string,mixed> caché de proceso, evita releer en el mismo request */
    private array $memoria = [];
    private ?int $sentinelaVisto = null;

    public function __construct(
        private readonly BD $bd,
        private readonly string $rutaSentinela,
        private readonly string $rutaCacheArchivo,
    ) {
    }

    public function get(string $clave, mixed $porDefecto = null): mixed
    {
        $this->descartarSiCambioElSentinela();

        if (array_key_exists($clave, $this->memoria)) {
            return $this->memoria[$clave];
        }

        $enCache = $this->leerCache($clave);
        if ($enCache !== null) {
            return $this->memoria[$clave] = $enCache['valor'];
        }

        $stmt = $this->bd->pdo()->prepare('SELECT valor FROM configuraciones WHERE clave = ?');
        $stmt->execute([$clave]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            // No se cachea la ausencia: una clave que falta suele ser una
            // migración pendiente, y cachearla alarga el desconcierto.
            return $porDefecto;
        }

        $valor = json_decode((string) $fila['valor'], true);
        $this->escribirCache($clave, $valor);

        return $this->memoria[$clave] = $valor;
    }

    public function set(string $clave, mixed $valor, string $usuarioId, ?string $motivo = null): void
    {
        $pdo = $this->bd->pdo();

        $stmt = $pdo->prepare('SELECT * FROM configuraciones WHERE clave = ?');
        $stmt->execute([$clave]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            throw new \InvalidArgumentException("No existe la configuración «{$clave}».");
        }

        $config = Configuracion::desdeFila($fila);
        $validado = $config->validar($valor);

        $codificado = json_encode($validado, JSON_UNESCAPED_UNICODE);
        if ($codificado === false) {
            throw new \InvalidArgumentException("No se pudo codificar el valor de «{$clave}».");
        }

        $this->bd->enTransaccion(function (\PDO $pdo) use ($clave, $codificado, $fila, $usuarioId, $motivo): void {
            $pdo->prepare(
                'INSERT INTO configuraciones_historial (clave, valor_anterior, valor_nuevo, usuario_id, motivo)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$clave, $fila['valor'], $codificado, $usuarioId, $motivo]);

            $pdo->prepare(
                'UPDATE configuraciones SET valor = ?, actualizado_por = ? WHERE clave = ?'
            )->execute([$codificado, $usuarioId, $clave]);
        });

        $this->invalidarCache($clave);
    }

    /** @return list<Configuracion> */
    public function getGrupo(string $grupo): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT * FROM configuraciones WHERE grupo = ? ORDER BY clave'
        );
        $stmt->execute([$grupo]);

        return array_map(
            static fn (array $fila): Configuracion => Configuracion::desdeFila($fila),
            $stmt->fetchAll(),
        );
    }

    public function invalidarCache(?string $clave = null): void
    {
        if ($clave === null) {
            $this->memoria = [];
        } else {
            unset($this->memoria[$clave]);
        }

        if ($this->apcuDisponible()) {
            if ($clave === null) {
                apcu_clear_cache();
            } else {
                apcu_delete(self::PREFIJO . $clave);
            }
        }

        if ($clave === null) {
            @unlink($this->rutaCacheArchivo);
        } else {
            $datos = $this->leerArchivoCompleto();
            unset($datos[$clave]);
            $this->escribirArchivoCompleto($datos);
        }

        $this->tocarSentinela();
    }

    // ── Caché ────────────────────────────────────────────────────────────

    /** @return array{valor:mixed}|null */
    private function leerCache(string $clave): ?array
    {
        if ($this->apcuDisponible()) {
            $ok = false;
            $valor = apcu_fetch(self::PREFIJO . $clave, $ok);

            return $ok ? ['valor' => $valor] : null;
        }

        $datos = $this->leerArchivoCompleto();
        $entrada = $datos[$clave] ?? null;

        if (!is_array($entrada) || ($entrada['expira'] ?? 0) < time()) {
            return null;
        }

        return ['valor' => $entrada['valor']];
    }

    private function escribirCache(string $clave, mixed $valor): void
    {
        if ($this->apcuDisponible()) {
            apcu_store(self::PREFIJO . $clave, $valor, self::TTL);

            return;
        }

        $datos = $this->leerArchivoCompleto();
        $datos[$clave] = ['valor' => $valor, 'expira' => time() + self::TTL];
        $this->escribirArchivoCompleto($datos);
    }

    /** @return array<string,array{valor:mixed,expira:int}> */
    private function leerArchivoCompleto(): array
    {
        if (!is_readable($this->rutaCacheArchivo)) {
            return [];
        }

        $crudo = @file_get_contents($this->rutaCacheArchivo);
        if ($crudo === false || $crudo === '') {
            return [];
        }

        $datos = json_decode($crudo, true);

        return is_array($datos) ? $datos : [];
    }

    /** @param array<string,mixed> $datos */
    private function escribirArchivoCompleto(array $datos): void
    {
        $codificado = json_encode($datos, JSON_UNESCAPED_UNICODE);
        if ($codificado === false) {
            return;
        }

        $directorio = dirname($this->rutaCacheArchivo);
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0o770, true);
        }

        @file_put_contents($this->rutaCacheArchivo, $codificado, LOCK_EX);
    }

    // ── Centinela ────────────────────────────────────────────────────────

    private function descartarSiCambioElSentinela(): void
    {
        $mtime = $this->mtimeSentinela();

        if ($this->sentinelaVisto === null) {
            $this->sentinelaVisto = $mtime;

            return;
        }

        if ($mtime !== $this->sentinelaVisto) {
            $this->sentinelaVisto = $mtime;
            $this->memoria = [];

            if ($this->apcuDisponible()) {
                apcu_clear_cache();
            }
        }
    }

    private function mtimeSentinela(): int
    {
        clearstatcache(true, $this->rutaSentinela);

        return is_file($this->rutaSentinela) ? (int) @filemtime($this->rutaSentinela) : 0;
    }

    private function tocarSentinela(): void
    {
        $directorio = dirname($this->rutaSentinela);
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0o770, true);
        }

        @touch($this->rutaSentinela);
        $this->sentinelaVisto = $this->mtimeSentinela();
    }

    private function apcuDisponible(): bool
    {
        // apcu.enable_cli está apagado por defecto, y el worker del outbox es
        // CLI: sin esta comprobación el worker escribiría en una caché que
        // nunca se lee.
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
