<?php

declare(strict_types=1);

namespace App\Core;

final readonly class Respuesta
{
    /** @param array<string,string> $cabeceras */
    public function __construct(
        public string $cuerpo = '',
        public int $estado = 200,
        public array $cabeceras = [],
    ) {
    }

    /** @param array<string,mixed>|list<mixed> $datos */
    public static function json(array $datos, int $estado = 200): self
    {
        return new self(
            json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $estado,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function texto(string $texto, int $estado = 200): self
    {
        return new self($texto, $estado, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $html, int $estado = 200): self
    {
        return new self($html, $estado, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function archivo(string $contenido, string $nombreDescarga, string $mime): self
    {
        return new self($contenido, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $nombreDescarga . '"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }

    /** @param array<string,mixed> $datos */
    public static function vista(string $plantilla, array $datos = [], int $estado = 200): self
    {
        $ruta = dirname(__DIR__, 2) . '/plantillas/' . $plantilla . '.php';

        if (!is_readable($ruta)) {
            throw new \RuntimeException("No existe la plantilla «{$plantilla}».");
        }

        return self::html(self::renderizar($ruta, $datos), $estado);
    }

    /**
     * Renderiza en un ámbito aislado.
     *
     * El aislamiento no es cosmético. Haciendo el `extract()` directamente en
     * `vista()`, sus propias variables locales —`$plantilla`, `$datos`,
     * `$estado`— chocan con los datos de la plantilla, y con `EXTR_SKIP` el
     * dato se descarta **en silencio**: una plantilla que espera `$estado`
     * recibe el código HTTP y pinta la página vacía sin un solo error.
     *
     * Aquí solo existen dos locales, con nombres que ninguna plantilla usaría,
     * y si aun así alguien los usa se falla en voz alta en vez de callar.
     *
     * @param array<string,mixed> $datos
     */
    private static function renderizar(string $__ruta, array $__datos): string
    {
        foreach (['__ruta', '__datos'] as $__reservado) {
            if (array_key_exists($__reservado, $__datos)) {
                throw new \InvalidArgumentException(
                    "«{$__reservado}» es un nombre reservado del renderizador."
                );
            }
        }

        extract($__datos, EXTR_OVERWRITE);

        ob_start();

        try {
            require $__ruta;

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            // Sin esto, una plantilla que revienta a mitad deja el búfer
            // abierto y la salida sale mezclada con la de la página de error.
            ob_end_clean();

            throw $e;
        }
    }

    public function enviar(): void
    {
        if (!headers_sent()) {
            http_response_code($this->estado);

            foreach ($this->cabeceras as $nombre => $valor) {
                header($nombre . ': ' . $valor, true);
            }

            // Cabeceras de seguridad en toda respuesta. La CSP concreta del
            // panel se endurece en la Etapa 3; esto es el piso.
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        echo $this->cuerpo;
    }
}
