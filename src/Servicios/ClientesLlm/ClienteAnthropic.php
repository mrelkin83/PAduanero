<?php

declare(strict_types=1);

namespace App\Servicios\ClientesLlm;

use App\Servicios\RespuestaLlm;
use App\Soporte\Http;

/**
 * `POST /v1/messages` de Anthropic. cURL nativo, sin SDK (docs/CONTRATOS.md).
 *
 * DOS PARÁMETROS QUE NO SE ENVÍAN, Y NO ES UN OLVIDO
 *
 *  · **`temperature`.** Los modelos actuales de la familia Opus/Sonnet 5 lo
 *    **rechazan con 400**. Enviarlo porque `modelos_ia.temperatura_default`
 *    existe rompería todas las llamadas. Esa columna sigue teniendo sentido
 *    para los proveedores compatibles con OpenAI, que sí lo aceptan; aquí no
 *    se usa. Lo que en otros modelos se conseguía bajando la temperatura
 *    —respuestas menos dispersas— aquí se consigue con el prompt, que además
 *    es lo que el abogado aprueba y queda versionado (ADR-008).
 *
 *  · **`thinking`.** Se deja el valor por defecto del modelo. Fijarlo en
 *    `disabled` tiene un efecto documentado y desagradable para este caso de
 *    uso: el modelo puede filtrar etiquetas internas al texto visible, y ese
 *    texto va tal cual a un cliente por WhatsApp.
 *
 * CONSECUENCIA QUE HAY QUE VIGILAR
 *
 * `max_tokens` acota el pensamiento **más** la respuesta. Un tope corto puede
 * agotarse pensando y devolver texto vacío. Por eso una respuesta sin texto
 * se trata aquí como fallo reintentable y no como turno válido: es preferible
 * bajar al suplente que enviarle al contacto un mensaje en blanco.
 */
final class ClienteAnthropic implements ClienteLlm
{
    private const VERSION_API = '2023-06-01';

    public function __construct(private readonly Http $http)
    {
    }

    public function formato(): string
    {
        return 'anthropic';
    }

    public function chat(
        string $baseUrl,
        ?string $secreto,
        array $modelo,
        string $systemPrompt,
        array $mensajes,
        int $maxTokens,
    ): RespuestaLlm {
        $cuerpo = [
            'model' => (string) $modelo['identificador'],
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => array_map(
                static fn (array $m): array => [
                    'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => (string) $m['content'],
                ],
                array_values($mensajes),
            ),
        ];

        $respuesta = $this->http->pedir(
            'POST',
            rtrim($baseUrl, '/') . '/v1/messages',
            [
                'x-api-key' => $secreto ?? '',
                'anthropic-version' => self::VERSION_API,
                'accept' => 'application/json',
            ],
            $cuerpo,
        );

        if ($respuesta->errorRed !== null) {
            throw FalloProveedor::red($respuesta->errorRed, $respuesta->latenciaMs);
        }

        if (!$respuesta->ok()) {
            throw FalloProveedor::estado($respuesta->estado, $respuesta->cuerpo, $respuesta->latenciaMs);
        }

        $datos = $respuesta->json();

        // Los clasificadores de seguridad pueden declinar la petición. Llega
        // como 200 con `stop_reason: refusal` y `content` vacío: leer
        // `content[0]` sin comprobarlo revienta con una respuesta exitosa.
        if (($datos['stop_reason'] ?? null) === 'refusal') {
            throw FalloProveedor::vacia('el proveedor declinó la petición', $respuesta->latenciaMs);
        }

        $texto = $this->texto($datos);

        if (trim($texto) === '') {
            throw FalloProveedor::vacia(
                ($datos['stop_reason'] ?? null) === 'max_tokens'
                    ? 'se agotó max_tokens antes de escribir'
                    : 'sin bloques de texto',
                $respuesta->latenciaMs,
            );
        }

        $uso = is_array($datos['usage'] ?? null) ? $datos['usage'] : [];

        return new RespuestaLlm(
            texto: $texto,
            tokensEntrada: (int) ($uso['input_tokens'] ?? 0),
            tokensSalida: (int) ($uso['output_tokens'] ?? 0),
            modeloId: (string) $modelo['id'],
            modeloIdentificador: (string) $modelo['identificador'],
            latenciaMs: $respuesta->latenciaMs,
        );
    }

    /**
     * Concatena los bloques de texto.
     *
     * `content` es una lista de bloques y no todos son texto: los de
     * pensamiento llegan con `type: thinking` y **no se incluyen**. Meterlos
     * enviaría el razonamiento del modelo a un cliente por WhatsApp.
     *
     * @param array<string,mixed> $datos
     */
    private function texto(array $datos): string
    {
        $bloques = $datos['content'] ?? null;

        if (!is_array($bloques)) {
            return '';
        }

        $partes = [];

        foreach ($bloques as $bloque) {
            if (is_array($bloque) && ($bloque['type'] ?? null) === 'text') {
                $partes[] = (string) ($bloque['text'] ?? '');
            }
        }

        return trim(implode('', $partes));
    }
}
