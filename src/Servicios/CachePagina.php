<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * Caché en disco de una página pública ya renderizada.
 *
 * Existe porque la landing y el diagnóstico (`/perfil`) necesitan
 * exactamente lo mismo y las tres sutilezas de esta clase son justo las que
 * nadie vuelve a deducir bien la segunda vez:
 *
 *  · **El centinela.** Lo toca el panel al guardar un bloque. Sin él, Pedro
 *    cambia un texto y no lo ve hasta que expira el TTL, y acaba pensando
 *    que el panel no guarda.
 *  · **El mtime del CSS.** El CSS va incrustado en el HTML, así que un
 *    `npm run build:css` tiene que invalidar igual que editar un bloque. Sin
 *    esto el síntoma —cambio de CSS que no aparece— cuesta una tarde.
 *  · **La escritura atómica.** Sin el `rename`, una visita concurrente lee
 *    el archivo a medio escribir y se sirve HTML truncado.
 *
 * Las dos páginas comparten centinela a propósito: los bloques viven en la
 * misma tabla y editar uno puede afectar a las dos.
 */
final class CachePagina
{
    public function __construct(
        private readonly string $rutaCache,
        private readonly string $rutaSentinela,
        private readonly string $rutaCss,
    ) {
    }

    /**
     * Devuelve el HTML cacheado, o lo genera con `$render` y lo guarda.
     *
     * @param callable():string $render
     */
    public function obtener(int $ttl, callable $render): string
    {
        if ($ttl > 0 && $this->vigente($ttl)) {
            $html = @file_get_contents($this->rutaCache);

            if (is_string($html) && $html !== '') {
                return $html;
            }
        }

        $html = $render();

        if ($ttl > 0) {
            $this->guardar($html);
        }

        return $html;
    }

    public function invalidar(): void
    {
        @unlink($this->rutaCache);
        $this->tocarSentinela();
    }

    private function vigente(int $ttl): bool
    {
        clearstatcache(true, $this->rutaCache);

        if (!is_file($this->rutaCache)) {
            return false;
        }

        $generada = (int) @filemtime($this->rutaCache);

        if ($generada + $ttl < time()) {
            return false;
        }

        return $this->mtime($this->rutaSentinela) <= $generada
            && $this->mtime($this->rutaCss) <= $generada;
    }

    private function mtime(string $ruta): int
    {
        clearstatcache(true, $ruta);

        return is_file($ruta) ? (int) @filemtime($ruta) : 0;
    }

    private function guardar(string $html): void
    {
        $directorio = dirname($this->rutaCache);

        if (!is_dir($directorio)) {
            @mkdir($directorio, 0o770, true);
        }

        $temporal = $this->rutaCache . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporal, $html) === false) {
            return;
        }

        if (!@rename($temporal, $this->rutaCache)) {
            @unlink($temporal);
        }
    }

    private function tocarSentinela(): void
    {
        $directorio = dirname($this->rutaSentinela);

        if (!is_dir($directorio)) {
            @mkdir($directorio, 0o770, true);
        }

        @touch($this->rutaSentinela);
    }
}
