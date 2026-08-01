<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Excepciones\LlmException;
use App\Soporte\Logger;

/**
 * RAG sobre MySQL: FULLTEXT + coseno en PHP (ADR-005, sin pgvector).
 *
 * La degradación está pensada de antemano porque va a ocurrir: si el
 * proveedor de embeddings no responde en el momento de buscar, se sirve el
 * orden del prefiltro léxico con `similitud = 0.0` y se deja constancia. Un
 * RAG caído no puede tumbar el turno del bot — peor respuesta con buen
 * fundamento léxico gana sobre ninguna respuesta.
 *
 * Lo que NO se degrada jamás es la regla 10: el filtro de verificación está
 * en el SQL de la única consulta que lee chunks, no en un `if` posterior
 * que una rama nueva pueda olvidar.
 */
final class BaseConocimientoMysql implements BaseConocimiento
{
    /** Candidatos máximos que pasan del prefiltro léxico al coseno. */
    private const CANDIDATOS = 60;

    public function __construct(
        private readonly BD $bd,
        private readonly Embeddings $embeddings,
        private readonly Logger $log,
    ) {
    }

    public function buscar(string $texto, ?string $area, ?string $tipoCaso, int $limite = 4): array
    {
        $texto = trim($texto);

        if ($texto === '' || $limite < 1) {
            return [];
        }

        // ── Pasos 1 y 2: prefiltros en SQL ───────────────────────────────
        //
        // La regla 10 vive AQUÍ: `vigente = 1 AND verificado_por IS NOT
        // NULL` en la única consulta que lee chunks. `area = 'ambos'`
        // aplica a las dos ramas; un chunk sin `tipo_caso` es general del
        // documento y también cuenta.
        $sql = "SELECT c.id, c.contenido, c.documento_id, c.embedding, c.embedding_norma,
                       d.referencia,
                       MATCH(c.contenido) AGAINST (:q IN NATURAL LANGUAGE MODE) AS puntaje_lexico
                  FROM kb_chunks c
                  JOIN kb_documentos d ON d.id = c.documento_id
                 WHERE d.vigente = 1
                   AND d.verificado_por IS NOT NULL";

        $parametros = ['q' => $texto, 'q2' => $texto];

        if ($area !== null && $area !== '') {
            $sql .= " AND (d.area = :area OR d.area = 'ambos')";
            $parametros['area'] = $area;
        }

        if ($tipoCaso !== null && $tipoCaso !== '') {
            $sql .= ' AND (c.tipo_caso = :tipo OR c.tipo_caso IS NULL)';
            $parametros['tipo'] = $tipoCaso;
        }

        $sql .= ' AND MATCH(c.contenido) AGAINST (:q2 IN NATURAL LANGUAGE MODE) > 0
                  ORDER BY puntaje_lexico DESC
                  LIMIT ' . self::CANDIDATOS;

        $stmt = $this->bd->pdo()->prepare($sql);
        $stmt->execute($parametros);
        $candidatos = $stmt->fetchAll();

        if ($candidatos === []) {
            return [];
        }

        // ── Paso 3: coseno en PHP ────────────────────────────────────────
        try {
            $consulta = $this->embeddings->vector($texto);
        } catch (LlmException $e) {
            $this->log->warn('kb.sin_embeddings', ['motivo' => $e->motivo]);

            return $this->resultado(array_slice($candidatos, 0, $limite), []);
        }

        $normaConsulta = $this->norma($consulta);

        if ($normaConsulta <= 0.0) {
            return $this->resultado(array_slice($candidatos, 0, $limite), []);
        }

        $puntuados = [];

        foreach ($candidatos as $chunk) {
            $vector = json_decode((string) $chunk['embedding'], true);
            $normaChunk = (float) ($chunk['embedding_norma'] ?? 0.0);

            if (!is_array($vector) || $vector === [] || $normaChunk <= 0.0) {
                // Un chunk verificado pero sin indexar no se pierde: entra
                // con su puntaje léxico normalizado a un coseno bajo, para
                // que exista aunque pierda contra los vectorizados.
                $puntuados[] = ['fila' => $chunk, 'similitud' => 0.0];
                continue;
            }

            $punto = 0.0;

            foreach ($vector as $i => $componente) {
                $punto += (float) $componente * ($consulta[$i] ?? 0.0);
            }

            $puntuados[] = [
                'fila' => $chunk,
                'similitud' => $punto / ($normaConsulta * $normaChunk),
            ];
        }

        usort(
            $puntuados,
            static fn (array $a, array $b): int => $b['similitud'] <=> $a['similitud'],
        );

        $mejores = array_slice($puntuados, 0, $limite);

        return $this->resultado(
            array_map(static fn (array $p): array => $p['fila'], $mejores),
            array_map(static fn (array $p): float => $p['similitud'], $mejores),
        );
    }

    public function indexarDocumento(string $documentoId): void
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, contenido FROM kb_chunks WHERE documento_id = ? AND embedding IS NULL ORDER BY orden'
        );
        $stmt->execute([$documentoId]);

        $guardar = $this->bd->pdo()->prepare(
            'UPDATE kb_chunks SET embedding = ?, embedding_norma = ? WHERE id = ?'
        );

        foreach ($stmt->fetchAll() as $chunk) {
            $vector = $this->embeddings->vector((string) $chunk['contenido']);

            $guardar->execute([
                json_encode($vector),
                $this->norma($vector),
                $chunk['id'],
            ]);
        }
    }

    /** @param list<float> $vector */
    private function norma(array $vector): float
    {
        $suma = 0.0;

        foreach ($vector as $componente) {
            $suma += $componente * $componente;
        }

        return sqrt($suma);
    }

    /**
     * @param  list<array<string,mixed>> $filas
     * @param  list<float>               $similitudes
     * @return list<array{contenido:string,referencia:string,documentoId:string,similitud:float}>
     */
    private function resultado(array $filas, array $similitudes): array
    {
        return array_values(array_map(
            static fn (array $fila, int $i): array => [
                'contenido' => (string) $fila['contenido'],
                'referencia' => (string) ($fila['referencia'] ?? ''),
                'documentoId' => (string) $fila['documento_id'],
                'similitud' => round($similitudes[$i] ?? 0.0, 4),
            ],
            $filas,
            array_keys($filas),
        ));
    }
}
