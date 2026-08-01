<?php

declare(strict_types=1);

namespace App\Servicios\Descubridores;

use App\Soporte\Http;

/**
 * `GET /api/tags` de Ollama.
 *
 * Lista lo que hay **descargado en esa máquina**, no lo que existe en el
 * mundo. Es una diferencia de fondo con los otros dos descubridores: aquí
 * «apareció un modelo nuevo» significa que alguien hizo `ollama pull` en el
 * VPS, y «desapareció» significa que alguien lo borró.
 *
 * Sin autenticación: Ollama no la tiene. Si el VPS lo expone a internet, el
 * problema es de firewall y no de este archivo.
 */
final class DescubridorOllama implements Descubridor
{
    public function __construct(private readonly Http $http)
    {
    }

    public function formato(): string
    {
        return 'ollama';
    }

    public function claveCredencial(): ?string
    {
        return null;
    }

    public function listar(string $baseUrl, ?string $secreto): array
    {
        $respuesta = $this->http->pedir(
            'GET',
            rtrim($baseUrl, '/') . '/api/tags',
            ['accept' => 'application/json'],
        );

        if ($respuesta->errorRed !== null) {
            throw DescubrimientoFallido::red('Ollama', $respuesta->errorRed);
        }

        if (!$respuesta->ok()) {
            throw DescubrimientoFallido::estado('Ollama', $respuesta->estado);
        }

        $datos = $respuesta->json()['models'] ?? null;

        if (!is_array($datos)) {
            throw DescubrimientoFallido::cuerpo('Ollama');
        }

        $modelos = [];

        foreach ($datos as $fila) {
            if (!is_array($fila) || !is_string($fila['name'] ?? null) || $fila['name'] === '') {
                continue;
            }

            $nombre = $fila['name'];
            $detalles = is_array($fila['details'] ?? null) ? $fila['details'] : [];

            $modelos[] = new ModeloDescubierto(
                identificador: $nombre,
                nombreVisible: $nombre,
                proposito: ModeloDescubierto::propositoDe($nombre),
                capacidades: array_filter([
                    'familia' => is_string($detalles['family'] ?? null) ? $detalles['family'] : null,
                    'parametros' => is_string($detalles['parameter_size'] ?? null)
                        ? $detalles['parameter_size']
                        : null,
                    'cuantizacion' => is_string($detalles['quantization_level'] ?? null)
                        ? $detalles['quantization_level']
                        : null,
                ], static fn (?string $v): bool => $v !== null),
            );
        }

        return $modelos;
    }
}
