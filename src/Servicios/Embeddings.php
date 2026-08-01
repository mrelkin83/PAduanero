<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * Vectoriza texto para la búsqueda semántica de la base de conocimiento.
 *
 * Interfaz aparte de `Llm` porque no comparten casi nada: aquí no hay
 * cascada de fallback, no hay prompt, no hay conjunto dorado —un modelo de
 * embeddings no le dice nada a nadie— y la respuesta es un vector, no un
 * texto.
 */
interface Embeddings
{
    /**
     * @return list<float> vector del texto
     * @throws \App\Excepciones\LlmException si no hay modelo de embeddings
     *                                       utilizable o el proveedor falla
     */
    public function vector(string $texto): array;
}
