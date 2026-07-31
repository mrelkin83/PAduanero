<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var string $titulo
 * @var string $mensaje
 */

$e = Vista::e(...);
$avisos = ['ok' => '', 'error' => ''];

$contenido = static function () use ($e, $mensaje): void { ?>
    <p class="aviso aviso-error"><?= $e($mensaje) ?></p>
    <a href="/panel" class="mt-4 inline-block text-sm underline">Volver al tablero</a>
<?php };

if ($ctx->usuario === null) {
    // Sin sesión no hay menú que pintar: el marco necesita usuario.
    echo '<!doctype html><meta charset="utf-8"><title>' . $e($titulo) . '</title>'
        . '<p style="font:16px system-ui;margin:3rem">' . $e($mensaje)
        . ' <a href="/panel/entrar">Entrar</a></p>';

    return;
}

require __DIR__ . '/_disposicion.php';
