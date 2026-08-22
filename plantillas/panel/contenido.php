<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Bloques de la página pública.
 *
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $bloques
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Contenido de la página';

$contenido = static function () use ($e, $ctx, $bloques): void { ?>

    <p class="text-sm text-acero">
        Todo lo que dice la landing y el diagnóstico sale de estos bloques.
        Un bloque <strong>oculto</strong> desaparece de la página entera, sin error:
        cuidado con apagar algo y olvidarlo.
    </p>

    <table class="tabla mt-4">
        <thead>
            <tr><th>Orden</th><th>Bloque</th><th>Título</th><th>Visible</th>
                <th>Pendientes</th><th>Actualizado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($bloques as $b): ?>
            <tr>
                <td class="font-mono"><?= (int) $b['orden'] ?></td>
                <td class="font-mono"><?= $e((string) $b['clave']) ?></td>
                <td><?= $e((string) ($b['titulo'] ?? '')) ?></td>
                <td><?= (int) $b['visible'] === 1 ? 'Sí' : '<strong>OCULTO</strong>' ?></td>
                <td>
                    <?php if ((int) $b['pendientes'] > 0): ?>
                        <span class="aviso aviso-error px-2 py-0.5"><?= (int) $b['pendientes'] ?> por confirmar</span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="font-mono text-xs"><?= $e((string) $b['actualizado_en']) ?></td>
                <td><a class="underline" href="/panel/contenido/editar?clave=<?= $e(urlencode((string) $b['clave'])) ?>">editar</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="mt-3 text-sm text-acero">
        «Pendientes» cuenta los datos de relleno (marca <code class="font-mono">pendiente</code>):
        la página los pinta en gris como no confirmados. Al cargar el dato real,
        desmarque la casilla en el editor.
    </p>

<?php };

require __DIR__ . '/_disposicion.php';
