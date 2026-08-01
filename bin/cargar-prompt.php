<?php

declare(strict_types=1);

/**
 * Carga un prompt desde un archivo como versión **inactiva**.
 *
 *   php bin/cargar-prompt.php db/prompts/conversacion.v1.txt
 *
 * Existe para que el borrador viva en el repositorio —revisable en un
 * `git diff`, que es como se revisa un texto— y entre a la base por un acto
 * explícito, en vez de aparecer solo al aplicar una migración.
 *
 * **Nunca activa nada.** La versión nace inactiva y la activa el abogado
 * desde `/panel/prompts`, con el conjunto dorado en verde por delante
 * (ADR-008 y ADR-016). Este script no tiene opción para saltárselo.
 */

use App\Core\Aplicacion;
use App\Repositorios\PromptRepo;
use App\Servicios\GateDorado;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

$ruta = $argv[1] ?? null;

if ($ruta === null) {
    fwrite(STDERR, 'Uso: php bin/cargar-prompt.php <archivo> [notas]' . PHP_EOL);
    exit(1);
}

$ruta = str_starts_with($ruta, '/') || preg_match('/^[A-Za-z]:/', $ruta) === 1
    ? $ruta
    : $raiz . '/' . $ruta;

if (!is_readable($ruta)) {
    fwrite(STDERR, "No se puede leer «{$ruta}»." . PHP_EOL);
    exit(1);
}

$contenido = trim((string) file_get_contents($ruta));

if (mb_strlen($contenido) < 200) {
    fwrite(STDERR, 'El archivo es demasiado corto para ser un prompt de conversación.' . PHP_EOL);
    exit(1);
}

try {
    $prompts = (new Aplicacion($raiz))->contenedor->obtener(PromptRepo::class);

    $id = $prompts->crearVersion(
        GateDorado::CLAVE_PROMPT,
        $contenido,
        $argv[2] ?? ('cargado desde ' . basename($ruta)),
        null,
    );

    $version = $prompts->porId($id);

    printf(
        "Versión %d creada, INACTIVA.%s%s",
        $version['version'] ?? 0,
        PHP_EOL,
        PHP_EOL,
    );

    printf("Siguiente paso:%s", PHP_EOL);
    printf("  php bin/correr-dorado.php --prompt=%d%s", $version['version'] ?? 0, PHP_EOL);
    printf("Y cuando salga en verde, el abogado la activa en /panel/prompts.%s", PHP_EOL);

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
