<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Dónde se guardan las notas de voz y las fotos que llegan por WhatsApp, y
 * cuánto espacio le queda al negocio.
 */
interface StoragePort
{
    /** Carpeta absoluta donde escribir la media de este negocio. */
    public function directorio(): string;

    /**
     * Raíz del almacén, de la que cuelgan las rutas que se guardan en la base.
     * Se necesita aparte de directorio() para dos cosas que el motor hace a
     * diario: convertir una ruta absoluta en la relativa que va a la BD, y
     * volver a encontrar un archivo al leerlo o al purgarlo.
     */
    public function raiz(): string;

    /** URL pública de una ruta relativa guardada en la base. */
    public function url(string $rutaRelativa): string;

    /**
     * Recomprime una imagen antes de guardarla.
     * @return array{bin:string,mime:string}|null null si no mejora o no aplica.
     */
    public function comprimirImagen(string $binario, int $maxLado = 1024, int $calidad = 78): ?array;

    /** ¿Cabe este archivo en el cupo del negocio? */
    public function cabe(int $bytes): bool;
}
