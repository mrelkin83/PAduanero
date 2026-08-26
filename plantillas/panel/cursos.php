<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $cursos
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Cursos';

$contenido = static function () use ($e, $ctx, $cursos): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <div class="flex items-center justify-between">
        <h2 class="rotulo">Cursos</h2>
        <div class="flex gap-3">
            <a href="/panel/cursos/categorias" class="boton-secundario">Categorías</a>
            <?php if ($editable): ?>
            <a href="/panel/cursos/editar" class="boton">Nuevo curso</a>
            <?php endif; ?>
        </div>
    </div>

    <table class="tabla mt-4">
        <thead>
            <tr><th>Título</th><th>Categoría</th><th>Precio</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($cursos as $c): ?>
            <tr>
                <td><?= $e((string) $c['titulo']) ?></td>
                <td><?= $e((string) $c['categoria_nombre']) ?></td>
                <td class="font-mono">$<?= $e(number_format((int) $c['precio_cop'], 0, ',', '.')) ?></td>
                <td><?= $c['estado'] === 'publicado' ? 'Publicado' : 'Borrador' ?></td>
                <td>
                    <a href="/panel/cursos/editar?id=<?= $e((string) $c['id']) ?>" class="underline">Editar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($cursos === []): ?>
            <tr><td colspan="5" class="text-acero">Todavía no hay cursos.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php };

require __DIR__ . '/_disposicion.php';
