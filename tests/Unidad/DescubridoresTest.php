<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Servicios\Descubridores\DescubridorAnthropic;
use App\Servicios\Descubridores\DescubridorOllama;
use App\Servicios\Descubridores\DescubridorOpenAiCompatible;
use App\Servicios\Descubridores\DescubrimientoFallido;
use App\Servicios\Descubridores\ModeloDescubierto;
use App\Soporte\Http;
use App\Soporte\RespuestaHttp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los descubridores de modelos, sin salir a la red.
 *
 * Las respuestas simuladas siguen la forma documentada de cada API: Anthropic
 * devuelve `{data, has_more, last_id}` con `max_input_tokens`; los
 * compatibles con OpenAI devuelven `{object:"list", data:[{id}]}`; Ollama
 * devuelve `{models:[{name, details}]}`.
 */
#[Group('critica')]
final class DescubridoresTest extends TestCase
{
    /** @param list<RespuestaHttp> $respuestas en orden de llamada */
    private function http(array $respuestas): Http
    {
        return new class ($respuestas) extends Http {
            /** @var list<array{string,string}> */
            public array $llamadas = [];

            /** @param list<RespuestaHttp> $respuestas */
            public function __construct(private array $respuestas)
            {
                parent::__construct();
            }

            public function pedir(
                string $metodo,
                string $url,
                array $cabeceras = [],
                ?array $json = null,
            ): RespuestaHttp {
                $this->llamadas[] = [$metodo, $url];

                return array_shift($this->respuestas)
                    ?? new RespuestaHttp(500, '', 'sin respuesta preparada', 0);
            }
        };
    }

    private function ok(string $cuerpo): RespuestaHttp
    {
        return new RespuestaHttp(200, $cuerpo, null, 30);
    }

    // ── Anthropic ────────────────────────────────────────────────────────

    #[Test]
    public function anthropicLeeIdentificadorNombreYVentanas(): void
    {
        $http = $this->http([$this->ok(json_encode([
            'data' => [[
                'type' => 'model',
                'id' => 'claude-opus-5',
                'display_name' => 'Claude Opus 5',
                'max_input_tokens' => 1000000,
                'max_tokens' => 128000,
                'capabilities' => ['image_input' => ['supported' => true]],
            ]],
            'has_more' => false,
        ], JSON_THROW_ON_ERROR))]);

        $modelos = (new DescubridorAnthropic($http))->listar('https://api.anthropic.com', 'sk-x');

        self::assertCount(1, $modelos);
        self::assertSame('claude-opus-5', $modelos[0]->identificador);
        self::assertSame('Claude Opus 5', $modelos[0]->nombreVisible);
        self::assertSame('conversacion', $modelos[0]->proposito);
        // La ventana de contexto es `max_input_tokens`. Buscar un campo
        // `context_window` —que esta API no tiene— dejaría la columna vacía
        // sin que nadie se enterara.
        self::assertSame(1000000, $modelos[0]->ventanaContexto);
        self::assertSame(128000, $modelos[0]->maxSalida);
        self::assertTrue($modelos[0]->capacidades['image_input']['supported']);
    }

    #[Test]
    public function anthropicDescubreUnModeloQueElSistemaNoConocia(): void
    {
        // Este es el caso que pidió el PO, tal cual: mañana sale Opus 6 y el
        // sistema se entera sin que nadie edite una lista en el código.
        $http = $this->http([$this->ok(json_encode([
            'data' => [
                ['id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
                ['id' => 'claude-opus-6', 'display_name' => 'Claude Opus 6'],
            ],
            'has_more' => false,
        ], JSON_THROW_ON_ERROR))]);

        $modelos = (new DescubridorAnthropic($http))->listar('https://api.anthropic.com', 'sk-x');

        self::assertSame(
            ['claude-opus-5', 'claude-opus-6'],
            array_map(static fn (ModeloDescubierto $m): string => $m->identificador, $modelos),
        );
    }

    #[Test]
    public function anthropicSigueLaPaginacion(): void
    {
        $http = $this->http([
            $this->ok(json_encode([
                'data' => [['id' => 'a', 'display_name' => 'A']],
                'has_more' => true,
                'last_id' => 'a',
            ], JSON_THROW_ON_ERROR)),
            $this->ok(json_encode([
                'data' => [['id' => 'b', 'display_name' => 'B']],
                'has_more' => false,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $modelos = (new DescubridorAnthropic($http))->listar('https://api.anthropic.com', 'sk-x');

        self::assertCount(2, $modelos);
        self::assertStringContainsString('after_id=a', $http->llamadas[1][1]);
    }

    #[Test]
    public function anthropicNoSeCuelgaSiElCursorNoAvanza(): void
    {
        // Un servidor que siempre dice `has_more: true` con el mismo cursor
        // colgaría el cron. Se corta al ver que el cursor se repite.
        $pagina = $this->ok(json_encode([
            'data' => [['id' => 'a', 'display_name' => 'A']],
            'has_more' => true,
            'last_id' => 'a',
        ], JSON_THROW_ON_ERROR));

        $http = $this->http(array_fill(0, 30, $pagina));

        $modelos = (new DescubridorAnthropic($http))->listar('https://api.anthropic.com', 'sk-x');

        self::assertCount(2, $modelos);
        self::assertLessThanOrEqual(3, count($http->llamadas));
    }

    #[Test]
    public function unaCredencialRechazadaEsExcepcionYNoListaVacia(): void
    {
        // La distinción es la razón de ser de `DescubrimientoFallido`: una
        // lista vacía haría que el catálogo entero apareciera como retirado.
        $http = $this->http([new RespuestaHttp(401, '{"error":{}}', null, 30)]);

        $this->expectException(DescubrimientoFallido::class);
        $this->expectExceptionMessageMatches('/credencial rechazada/');

        (new DescubridorAnthropic($http))->listar('https://api.anthropic.com', 'caducada');
    }

    #[Test]
    public function unFalloDeRedEsExcepcion(): void
    {
        $http = $this->http([new RespuestaHttp(0, '', 'Could not resolve host', 8000)]);

        $this->expectException(DescubrimientoFallido::class);
        $this->expectExceptionMessageMatches('/No hubo respuesta/');

        (new DescubridorAnthropic($http))->listar('https://api.anthropic.com', 'sk-x');
    }

    #[Test]
    public function unCuerpoInesperadoEsExcepcion(): void
    {
        // Un proxy que devuelve HTML con 200 no es «cero modelos».
        $http = $this->http([$this->ok('<html>portal cautivo</html>')]);

        $this->expectException(DescubrimientoFallido::class);

        (new DescubridorAnthropic($http))->listar('https://api.anthropic.com', 'sk-x');
    }

    // ── Compatibles con OpenAI ───────────────────────────────────────────

    #[Test]
    public function openAiDeduceElPropositoDelIdentificador(): void
    {
        $http = $this->http([$this->ok(json_encode([
            'object' => 'list',
            'data' => [
                ['id' => 'gpt-4o-mini'],
                ['id' => 'text-embedding-3-large'],
            ],
        ], JSON_THROW_ON_ERROR))]);

        $modelos = (new DescubridorOpenAiCompatible($http))->listar('https://api.openai.com/v1', 'sk-x');

        self::assertSame('conversacion', $modelos[0]->proposito);
        self::assertSame('embeddings', $modelos[1]->proposito);
        // Sin `name`, el identificador es el mejor nombre disponible.
        self::assertSame('gpt-4o-mini', $modelos[0]->nombreVisible);
    }

    // ── Ollama ───────────────────────────────────────────────────────────

    #[Test]
    public function ollamaListaLoDescargadoEnLaMaquina(): void
    {
        $http = $this->http([$this->ok(json_encode([
            'models' => [[
                'name' => 'llama3.1:8b',
                'details' => ['family' => 'llama', 'parameter_size' => '8.0B', 'quantization_level' => 'Q4_0'],
            ]],
        ], JSON_THROW_ON_ERROR))]);

        $descubridor = new DescubridorOllama($http);
        $modelos = $descubridor->listar('http://127.0.0.1:11434', null);

        self::assertNull($descubridor->claveCredencial());
        self::assertSame('llama3.1:8b', $modelos[0]->identificador);
        self::assertSame('llama', $modelos[0]->capacidades['familia']);
        self::assertStringEndsWith('/api/tags', $http->llamadas[0][1]);
    }
}
