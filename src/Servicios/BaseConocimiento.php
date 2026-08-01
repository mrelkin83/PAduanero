<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * La base de conocimiento jurídico (docs/CONTRATOS.md §BaseConocimiento).
 *
 * Regla 10, que es la que gobierna todo lo demás: **un fragmento sin
 * verificación del abogado no entra al RAG bajo ninguna circunstancia.**
 * No es un filtro de calidad, es la diferencia entre que el bot se apoye en
 * lo que Pedro revisó y que cite algo que nadie miró.
 */
interface BaseConocimiento
{
    /**
     * Sin pgvector (ADR-005). Tres pasos:
     *
     *  1. Prefiltro por `area` y `tipo_caso`.
     *  2. Prefiltro léxico con MATCH … AGAINST sobre el índice FULLTEXT.
     *  3. Coseno en PHP sobre los candidatos, con `embedding_norma`
     *     precalculada. Con ~2.000 chunks son milisegundos.
     *
     * Devuelve SOLO chunks de documentos con vigente=1 y verificado_por
     * NOT NULL.
     *
     * @return list<array{contenido:string,referencia:string,documentoId:string,similitud:float}>
     */
    public function buscar(string $texto, ?string $area, ?string $tipoCaso, int $limite = 4): array;

    /** Calcula y guarda el embedding de cada chunk del documento. */
    public function indexarDocumento(string $documentoId): void;
}
