<?php

declare(strict_types=1);

namespace App\Servicios\Descubridores;

use App\Soporte\Http;

/**
 * `GET /v1/models` de Anthropic.
 *
 * Es el caso que motivó la petición del PO: hoy hay Opus 5 y mañana puede
 * haber Opus 6. Este endpoint lo dice sin que nadie edite una lista.
 *
 * Devuelve por modelo `id`, `display_name`, `created_at`, `max_input_tokens`,
 * `max_tokens` y un árbol `capabilities` con `supported` en cada hoja. No
 * devuelve precio —ver `ModeloDescubierto`— ni fecha de retiro.
 *
 * Pagina con `after_id` + `has_more`. Se recorre con tope, porque un bucle
 * de paginación sin freno contra un servidor que devuelve siempre
 * `has_more: true` es una forma barata de tumbar el cron.
 */
final class DescubridorAnthropic implements Descubridor
{
    private const VERSION_API = '2023-06-01';
    private const POR_PAGINA = 100;
    private const MAX_PAGINAS = 10;

    public function __construct(private readonly Http $http)
    {
    }

    public function formato(): string
    {
        return 'anthropic';
    }

    public function claveCredencial(): ?string
    {
        return 'api_key';
    }

    public function listar(string $baseUrl, ?string $secreto): array
    {
        $modelos = [];
        $despues = null;

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $url = rtrim($baseUrl, '/') . '/v1/models?limit=' . self::POR_PAGINA
                 . ($despues !== null ? '&after_id=' . rawurlencode($despues) : '');

            $respuesta = $this->http->pedir('GET', $url, [
                'x-api-key' => $secreto ?? '',
                'anthropic-version' => self::VERSION_API,
                'accept' => 'application/json',
            ]);

            if ($respuesta->errorRed !== null) {
                throw DescubrimientoFallido::red('Anthropic', $respuesta->errorRed);
            }

            if (!$respuesta->ok()) {
                throw DescubrimientoFallido::estado('Anthropic', $respuesta->estado);
            }

            $cuerpo = $respuesta->json();
            $datos = $cuerpo['data'] ?? null;

            if (!is_array($datos)) {
                throw DescubrimientoFallido::cuerpo('Anthropic');
            }

            foreach ($datos as $fila) {
                $modelo = $this->desdeFila($fila);

                if ($modelo !== null) {
                    $modelos[] = $modelo;
                }
            }

            if (($cuerpo['has_more'] ?? false) !== true) {
                break;
            }

            $ultimo = $cuerpo['last_id'] ?? null;

            // Sin cursor no hay página siguiente que pedir. Reintentar la
            // misma sería el bucle infinito que el tope ya frena, pero mejor
            // salir por la razón correcta.
            if (!is_string($ultimo) || $ultimo === '' || $ultimo === $despues) {
                break;
            }

            $despues = $ultimo;
        }

        return $modelos;
    }

    private function desdeFila(mixed $fila): ?ModeloDescubierto
    {
        if (!is_array($fila) || !is_string($fila['id'] ?? null) || $fila['id'] === '') {
            return null;
        }

        $id = $fila['id'];
        $nombre = is_string($fila['display_name'] ?? null) && $fila['display_name'] !== ''
            ? $fila['display_name']
            : $id;

        return new ModeloDescubierto(
            identificador: $id,
            nombreVisible: $nombre,
            proposito: ModeloDescubierto::propositoDe($id),
            // `max_input_tokens` es la ventana de contexto. El campo
            // `context_window` no existe en esta API; buscarlo devuelve null
            // y deja la columna vacía sin que nadie se entere.
            ventanaContexto: self::entero($fila['max_input_tokens'] ?? null),
            maxSalida: self::entero($fila['max_tokens'] ?? null),
            capacidades: is_array($fila['capabilities'] ?? null) ? $fila['capabilities'] : [],
        );
    }

    private static function entero(mixed $valor): ?int
    {
        return is_int($valor) && $valor > 0 ? $valor : null;
    }
}
