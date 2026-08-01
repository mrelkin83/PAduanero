<?php

declare(strict_types=1);

namespace App\Servicios\Descubridores;

use App\Soporte\Http;

/**
 * `GET /models` de cualquier proveedor con API compatible con OpenAI.
 *
 * Cubre OpenAI, Groq, DeepSeek, Mistral, Together y OpenRouter: todos
 * exponen la misma forma, `{"object":"list","data":[{"id":"..."}]}`, sin
 * paginación.
 *
 * Lo que este formato NO da, y conviene tener presente al mirar el panel:
 * ni nombre legible, ni ventana de contexto, ni propósito. Un catálogo
 * compatible con OpenAI devuelve además todo lo que el proveedor tenga
 * publicado —transcripción, imágenes, moderación, instantáneas viejas—, así
 * que la lista es larga y mayormente irrelevante. Por eso los modelos nacen
 * inactivos: la lista es material para revisar, no para usar.
 */
final class DescubridorOpenAiCompatible implements Descubridor
{
    public function __construct(private readonly Http $http)
    {
    }

    public function formato(): string
    {
        return 'openai_compatible';
    }

    public function claveCredencial(): ?string
    {
        return 'api_key';
    }

    public function listar(string $baseUrl, ?string $secreto): array
    {
        $cabeceras = ['accept' => 'application/json'];

        if ($secreto !== null && $secreto !== '') {
            $cabeceras['Authorization'] = 'Bearer ' . $secreto;
        }

        $respuesta = $this->http->pedir('GET', rtrim($baseUrl, '/') . '/models', $cabeceras);

        if ($respuesta->errorRed !== null) {
            throw DescubrimientoFallido::red('el proveedor', $respuesta->errorRed);
        }

        if (!$respuesta->ok()) {
            throw DescubrimientoFallido::estado('el proveedor', $respuesta->estado);
        }

        $datos = $respuesta->json()['data'] ?? null;

        if (!is_array($datos)) {
            throw DescubrimientoFallido::cuerpo('el proveedor');
        }

        $modelos = [];

        foreach ($datos as $fila) {
            if (!is_array($fila) || !is_string($fila['id'] ?? null) || $fila['id'] === '') {
                continue;
            }

            $id = $fila['id'];

            $modelos[] = new ModeloDescubierto(
                identificador: $id,
                // OpenRouter sí manda `name`; el resto no manda nada, y
                // entonces el identificador es el mejor nombre disponible.
                nombreVisible: is_string($fila['name'] ?? null) && $fila['name'] !== ''
                    ? $fila['name']
                    : $id,
                proposito: ModeloDescubierto::propositoDe($id),
                ventanaContexto: self::entero($fila['context_length'] ?? null),
            );
        }

        return $modelos;
    }

    private static function entero(mixed $valor): ?int
    {
        return is_int($valor) && $valor > 0 ? $valor : null;
    }
}
