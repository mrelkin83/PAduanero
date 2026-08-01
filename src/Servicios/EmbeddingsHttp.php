<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Excepciones\LlmException;
use App\Soporte\Http;
use App\Soporte\Logger;

/**
 * Embeddings contra el endpoint compatible con OpenAI (`POST /embeddings`).
 *
 * El modelo sale de `modelos_ia` con `proposito = 'embeddings'`, con las
 * mismas puertas de siempre menos el dorado: activo, no retirado, costo
 * verificado y proveedor encendido. El costo verificado no es burocracia ni
 * aquí: indexar 130 escenarios son cientos de llamadas, y un modelo a costo
 * cero las dejaría fuera del corte por `presupuesto_ia_mensual_usd`.
 *
 * El consumo se registra en `consumo_ia` como el de conversación, porque el
 * presupuesto mensual es UNO: lo que se gaste vectorizando compite con lo
 * que se gasta conversando, y un tope que ignora una de las dos mitades no
 * es un tope.
 */
final class EmbeddingsHttp implements Embeddings
{
    public function __construct(
        private readonly BD $bd,
        private readonly Credenciales $credenciales,
        private readonly Http $http,
        private readonly Logger $log,
    ) {
    }

    public function vector(string $texto): array
    {
        $modelo = $this->modelo();

        $llave = $this->credenciales->obtener((string) $modelo['proveedor_clave'], 'api_key');

        $inicio = microtime(true);

        $respuesta = $this->http->pedir(
            'POST',
            rtrim((string) $modelo['base_url'], '/') . '/embeddings',
            [
                'Authorization' => 'Bearer ' . $llave,
                'content-type' => 'application/json',
            ],
            [
                'model' => (string) $modelo['identificador'],
                'input' => $texto,
            ],
        );

        $latencia = (int) round((microtime(true) - $inicio) * 1000);

        if ($respuesta->errorRed !== null || !$respuesta->ok()) {
            $this->registrar($modelo, 0, $latencia, false, $respuesta->errorRed ?? ('HTTP ' . $respuesta->estado));

            throw new LlmException(
                'El proveedor de embeddings no respondió: '
                . ($respuesta->errorRed ?? ('HTTP ' . $respuesta->estado)),
                'embeddings_caido',
            );
        }

        $cuerpo = $respuesta->json();
        $vector = $cuerpo['data'][0]['embedding'] ?? null;

        if (!is_array($vector) || $vector === []) {
            $this->registrar($modelo, 0, $latencia, false, 'respuesta sin vector');

            throw new LlmException('El proveedor devolvió una respuesta sin vector.', 'embeddings_malformado');
        }

        $this->registrar(
            $modelo,
            (int) ($cuerpo['usage']['prompt_tokens'] ?? 0),
            $latencia,
            true,
            null,
        );

        return array_map(floatval(...), array_values($vector));
    }

    /** @return array<string,mixed> */
    private function modelo(): array
    {
        $fila = $this->bd->pdo()->query(
            "SELECT m.*, p.clave AS proveedor_clave, p.base_url
               FROM modelos_ia m
               JOIN proveedores_ia p ON p.id = m.proveedor_id
              WHERE m.proposito = 'embeddings'
                AND m.activo = 1
                AND m.retirado_en IS NULL
                AND m.costos_verificados = 1
                AND p.activo = 1
              ORDER BY m.es_primario DESC, m.orden_fallback
              LIMIT 1"
        )->fetch();

        return $fila !== false
            ? $fila
            : throw LlmException::sinModeloAutorizado('embeddings');
    }

    /** @param array<string,mixed> $modelo */
    private function registrar(array $modelo, int $tokens, int $latenciaMs, bool $exito, ?string $error): void
    {
        // El costo se CALCULA, no se acepta: misma disciplina que
        // Llm::registrarConsumo. Un fallo no puede reportarse a costo cero
        // por accidente — aquí, si falló, los tokens son cero de verdad.
        $costo = $tokens / 1_000_000 * (float) ($modelo['costo_entrada_usd_1m'] ?? 0);

        $this->bd->pdo()->prepare(
            'INSERT INTO consumo_ia (modelo_id, tokens_entrada, tokens_salida, costo_usd, latencia_ms, exito, error)
             VALUES (?, ?, 0, ?, ?, ?, ?)'
        )->execute([
            $modelo['id'],
            $tokens,
            number_format($costo, 6, '.', ''),
            $latenciaMs,
            $exito ? 1 : 0,
            $error,
        ]);
    }
}
