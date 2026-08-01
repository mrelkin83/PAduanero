<?php

declare(strict_types=1);

namespace App\Servicios\ClientesLlm;

use App\Servicios\CatalogoProveedores;
use App\Servicios\RespuestaLlm;
use App\Soporte\Http;

/**
 * `POST /chat/completions`, el formato que hablan casi todos.
 *
 * Cubre OpenAI, Groq, DeepSeek, Mistral, Together y OpenRouter. También
 * Ollama, que expone este mismo endpoint bajo `/v1` además del suyo propio;
 * por eso `formato()` se resuelve por construcción y no está fijo — así el
 * proveedor `ollama` reutiliza este cliente en vez de duplicarlo.
 *
 * A diferencia del cliente de Anthropic, aquí **sí** se envía `temperature`:
 * estos proveedores la aceptan, y `modelos_ia.temperatura_default` existe
 * para ellos.
 */
final class ClienteOpenAiCompatible implements ClienteLlm
{
    public function __construct(
        private readonly Http $http,
        private readonly string $formato = 'openai_compatible',
        /** Ollama sirve el formato OpenAI bajo `/v1`; el resto ya lo trae en su base_url. */
        private readonly string $prefijo = '',
    ) {
    }

    public function formato(): string
    {
        return $this->formato;
    }

    public function chat(
        string $baseUrl,
        ?string $secreto,
        array $modelo,
        string $systemPrompt,
        array $mensajes,
        int $maxTokens,
    ): RespuestaLlm {
        $cabeceras = ['accept' => 'application/json'];

        if ($secreto !== null && $secreto !== '') {
            $cabeceras['Authorization'] = 'Bearer ' . $secreto;
        }

        // El parámetro que acota la respuesta NO se llama igual en todos.
        // OpenAI lo renombró a `max_completion_tokens` en sus modelos
        // recientes y **rechaza `max_tokens` con un 400**; el resto de
        // compatibles siguen esperando `max_tokens`. Mandar el equivocado no
        // degrada nada: rompe todas las llamadas a ese proveedor.
        $campoMax = CatalogoProveedores::campoMax((string) $modelo['proveedor_clave']);

        $cuerpo = [
            'model' => (string) $modelo['identificador'],
            $campoMax => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ...array_map(
                    static fn (array $m): array => [
                        'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                        'content' => (string) $m['content'],
                    ],
                    array_values($mensajes),
                ),
            ],
        ];

        if ($modelo['temperatura_default'] !== null) {
            $cuerpo['temperature'] = (float) $modelo['temperatura_default'];
        }

        $respuesta = $this->http->pedir(
            'POST',
            rtrim($baseUrl, '/') . $this->prefijo . '/chat/completions',
            $cabeceras,
            $cuerpo,
        );

        if ($respuesta->errorRed !== null) {
            throw FalloProveedor::red($respuesta->errorRed, $respuesta->latenciaMs);
        }

        if (!$respuesta->ok()) {
            throw FalloProveedor::estado($respuesta->estado, $respuesta->cuerpo, $respuesta->latenciaMs);
        }

        $datos = $respuesta->json();
        $texto = (string) ($datos['choices'][0]['message']['content'] ?? '');

        if (trim($texto) === '') {
            throw FalloProveedor::vacia(
                'sin contenido en choices[0]',
                $respuesta->latenciaMs,
            );
        }

        $uso = is_array($datos['usage'] ?? null) ? $datos['usage'] : [];

        return new RespuestaLlm(
            texto: trim($texto),
            tokensEntrada: (int) ($uso['prompt_tokens'] ?? 0),
            tokensSalida: (int) ($uso['completion_tokens'] ?? 0),
            modeloId: (string) $modelo['id'],
            modeloIdentificador: (string) $modelo['identificador'],
            latenciaMs: $respuesta->latenciaMs,
        );
    }
}
