<?php

declare(strict_types=1);

namespace App\Core;

final readonly class Peticion
{
    /**
     * @param array<string,string>       $consulta
     * @param array<string,mixed>        $formulario
     * @param array<string,string>       $cabeceras
     * @param array<string,string>       $parametros  capturados de la ruta
     */
    public function __construct(
        public string $metodo,
        public string $ruta,
        public array $consulta = [],
        public array $formulario = [],
        public array $cabeceras = [],
        public string $cuerpoCrudo = '',
        public array $parametros = [],
        public string $ip = '',
    ) {
    }

    public static function desdeGlobales(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $ruta = parse_url($uri, PHP_URL_PATH);
        $ruta = is_string($ruta) ? $ruta : '/';

        // El cuerpo crudo importa: la firma de los webhooks de pago se valida
        // contra él, nunca contra el JSON reparseado (docs/CONTRATOS.md).
        $cuerpo = (string) file_get_contents('php://input');

        return new self(
            metodo: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            ruta: '/' . trim($ruta, '/'),
            consulta: array_map('strval', $_GET),
            formulario: $_POST,
            cabeceras: self::cabeceras(),
            cuerpoCrudo: $cuerpo,
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function conParametros(array $parametros): self
    {
        return new self(
            $this->metodo,
            $this->ruta,
            $this->consulta,
            $this->formulario,
            $this->cabeceras,
            $this->cuerpoCrudo,
            $parametros,
            $this->ip,
        );
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $datos = json_decode($this->cuerpoCrudo, true);

        return is_array($datos) ? $datos : [];
    }

    /** @return array<string,string> en minúsculas, que es como se consultan */
    private static function cabeceras(): array
    {
        $salida = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with((string) $clave, 'HTTP_')) {
                $nombre = strtolower(str_replace('_', '-', substr((string) $clave, 5)));
                $salida[$nombre] = (string) $valor;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $salida['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $salida;
    }
}
