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

    /** @param array<string,mixed> $datos */
    public static function vista(string $plantilla, array $datos = [], int $estado = 200): self
    {
        $ruta = dirname(__DIR__, 2) . '/plantillas/' . $plantilla . '.php';

        if (!is_readable($ruta)) {
            throw new \RuntimeException("No existe la plantilla «{$plantilla}».");
        }

        extract($datos, EXTR_SKIP);
        ob_start();
        require $ruta;

        return self::html((string) ob_get_clean(), $estado);
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
