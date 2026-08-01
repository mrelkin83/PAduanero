<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Excepciones\LlmException;
use App\Servicios\BaseConocimientoMysql;
use App\Servicios\Embeddings;
use App\Soporte\Logger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * La base de conocimiento (`PRUEBAS.md` §1, nivel 1 — regla 10).
 *
 * Lo innegociable: un fragmento sin verificación del abogado no sale de
 * `buscar()` bajo NINGUNA circunstancia. El resto —prefiltros, coseno,
 * degradación sin embeddings— es calidad de recuperación.
 */
#[Group('critica')]
final class BaseConocimientoTest extends CasoBaseBd
{
    /**
     * Embeddings deterministas de juguete: tres dimensiones temáticas
     * (aduanas, impuestos, ruido) contadas por palabras clave. Suficiente
     * para que el coseno ordene de verdad sin salir a la red.
     */
    private function embeddings(): Embeddings
    {
        return new class implements Embeddings {
            public function vector(string $texto): array
            {
                $texto = mb_strtolower($texto);

                return [
                    (float) (substr_count($texto, 'aprehen') + substr_count($texto, 'decomis') + substr_count($texto, 'mercanc')),
                    (float) (substr_count($texto, 'renta') + substr_count($texto, 'requerimiento') + substr_count($texto, 'tribut')),
                    (float) substr_count($texto, 'general'),
                ];
            }
        };
    }

    private function embeddingsCaidos(): Embeddings
    {
        return new class implements Embeddings {
            public function vector(string $texto): array
            {
                throw new LlmException('proveedor caído', 'embeddings_caido');
            }
        };
    }

    private function kb(?Embeddings $embeddings = null): BaseConocimientoMysql
    {
        return new BaseConocimientoMysql(
            $this->bd,
            $embeddings ?? $this->embeddings(),
            Logger::desdeEntorno(),
        );
    }

    /**
     * @param  list<string> $chunks
     * @return string id del documento
     */
    private function documento(
        string $titulo,
        array $chunks,
        string $area = 'aduanero',
        ?string $verificadoPor = 'Pedro',
        ?string $tipoCaso = null,
        bool $vigente = true,
    ): string {
        $pdo = $this->bd->pdo();

        $pdo->prepare(
            'INSERT INTO kb_documentos (titulo, area, tipo_fuente, referencia, vigente, verificado_por, verificado_en)
             VALUES (?, ?, \'escenario\', ?, ?, ?, IF(? IS NULL, NULL, CURDATE()))'
        )->execute([$titulo, $area, $titulo, $vigente ? 1 : 0, $verificadoPor, $verificadoPor]);

        $id = (string) $pdo->query(
            'SELECT id FROM kb_documentos ORDER BY creado_en DESC, id DESC LIMIT 1'
        )->fetchColumn();

        $insertar = $pdo->prepare(
            'INSERT INTO kb_chunks (documento_id, orden, contenido, tipo_caso) VALUES (?, ?, ?, ?)'
        );

        foreach ($chunks as $orden => $contenido) {
            $insertar->execute([$id, $orden + 1, $contenido, $tipoCaso]);
        }

        $this->kb()->indexarDocumento($id);

        return $id;
    }

    // ── Regla 10 ─────────────────────────────────────────────────────────

    #[Test]
    public function unDocumentoSinVerificarNoSaleJamas(): void
    {
        $this->documento(
            'Borrador sin revisar',
            ['La aprehensión de mercancía tiene un procedimiento que nadie ha verificado.'],
            verificadoPor: null,
        );

        self::assertSame([], $this->kb()->buscar('aprehensión de mercancía', null, null));
    }

    #[Test]
    public function unDocumentoRetiradoTampoco(): void
    {
        $this->documento(
            'Norma derogada',
            ['La aprehensión de mercancía se regía por un régimen que ya no está vigente.'],
            vigente: false,
        );

        self::assertSame([], $this->kb()->buscar('aprehensión de mercancía', null, null));
    }

    #[Test]
    public function verificarUnDocumentoLoHaceAparecer(): void
    {
        // El mismo texto, antes y después de la firma: es la firma lo que lo
        // activa, no la carga.
        $pdo = $this->bd->pdo();
        $id = $this->documento(
            'Escenario de decomiso',
            ['El decomiso de mercancía admite defensa en sede administrativa.'],
            verificadoPor: null,
        );

        self::assertSame([], $this->kb()->buscar('decomiso de mercancía', null, null));

        $pdo->prepare(
            "UPDATE kb_documentos SET verificado_por = 'Pedro', verificado_en = CURDATE() WHERE id = ?"
        )->execute([$id]);

        $resultados = $this->kb()->buscar('decomiso de mercancía', null, null);

        self::assertCount(1, $resultados);
        self::assertSame($id, $resultados[0]['documentoId']);
    }

    // ── Prefiltros ───────────────────────────────────────────────────────

    #[Test]
    public function elAreaFiltraYAmbosAplicaALasDos(): void
    {
        $this->documento('Aduanero puro', ['La aprehensión de mercancía en zona primaria aduanera.'], 'aduanero');
        $this->documento('Tributario puro', ['La aprehensión conceptual del requerimiento de renta.'], 'tributario');
        $this->documento('Común', ['La aprehensión como concepto general del procedimiento.'], 'ambos');

        $areas = array_map(
            static fn (array $r): string => $r['referencia'],
            $this->kb()->buscar('aprehensión', 'aduanero', null, 10),
        );

        self::assertContains('Aduanero puro', $areas);
        self::assertContains('Común', $areas, "«ambos» aplica a las dos ramas");
        self::assertNotContains('Tributario puro', $areas);
    }

    #[Test]
    public function elTipoDeCasoFiltraYLosGeneralesEntran(): void
    {
        $this->documento('Específico', ['El decomiso directo de mercancía tiene defensa propia.'], tipoCaso: 'decomiso');
        $this->documento('Otro tipo', ['El decomiso no aplica: esto habla de valoración de mercancía.'], tipoCaso: 'valoracion_aduanera');
        $this->documento('General', ['Concepto general del decomiso y la aprehensión de mercancía.']);

        $referencias = array_map(
            static fn (array $r): string => $r['referencia'],
            $this->kb()->buscar('decomiso de mercancía', null, 'decomiso', 10),
        );

        self::assertContains('Específico', $referencias);
        self::assertContains('General', $referencias, 'un chunk sin tipo es general del documento');
        self::assertNotContains('Otro tipo', $referencias);
    }

    // ── El coseno ordena ─────────────────────────────────────────────────

    #[Test]
    public function elMasParecidoSemanticamenteGana(): void
    {
        // Los dos coinciden léxicamente («mercancía»); FULLTEXT no hace
        // stemming, así que la palabra compartida tiene que ser literal. El
        // coseno es quien decide cuál va primero.
        $this->documento('Muy pertinente', ['Aprehensión y decomiso de mercancía: qué revisar de la mercancía aprehendida.']);
        $this->documento('Poco pertinente', ['Nota general sobre mercancía: aspectos generales de almacén.']);

        $resultados = $this->kb()->buscar('me decomisaron la mercancía aprehendida', null, null, 2);

        self::assertSame('Muy pertinente', $resultados[0]['referencia']);
        self::assertGreaterThan($resultados[1]['similitud'], $resultados[0]['similitud']);
    }

    #[Test]
    public function seDevuelvenComoMaximoLosPedidos(): void
    {
        foreach (range(1, 6) as $i) {
            $this->documento("Doc {$i}", ["Fragmento {$i} sobre la aprehensión de mercancía en aduanas."]);
        }

        self::assertCount(4, $this->kb()->buscar('aprehensión de mercancía', null, null, 4));
    }

    // ── Degradación ──────────────────────────────────────────────────────

    #[Test]
    public function sinEmbeddingsSeSirveElOrdenLexicoEnVezDeNada(): void
    {
        // El RAG caído no puede tumbar el turno del bot. Peor ranking con
        // buen fundamento léxico gana sobre ninguna respuesta.
        $this->documento('Único', ['La aprehensión de mercancía admite objeción del interesado.']);

        $resultados = $this->kb($this->embeddingsCaidos())->buscar('aprehensión de mercancía', null, null);

        self::assertCount(1, $resultados);
        self::assertSame(0.0, $resultados[0]['similitud']);
    }

    #[Test]
    public function indexarCalculaVectorYNorma(): void
    {
        $id = $this->documento('Indexado', ['Decomiso de mercancía y aprehensión: dos figuras distintas.']);

        $fila = $this->bd->pdo()->query(
            "SELECT embedding, embedding_norma FROM kb_chunks WHERE documento_id = '{$id}'"
        )->fetch();

        $vector = json_decode((string) $fila['embedding'], true);

        self::assertIsArray($vector);
        self::assertGreaterThan(0.0, (float) $fila['embedding_norma']);
        // La norma guardada ES la norma del vector guardado.
        self::assertEqualsWithDelta(
            sqrt(array_sum(array_map(static fn (float $c): float => $c * $c, $vector))),
            (float) $fila['embedding_norma'],
            0.0001,
        );
    }
}
