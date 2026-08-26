<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $categorias
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Categorías de cursos';

$contenido = static function () use ($e, $ctx, $categorias): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo">Categorías</h2>

    <table class="tabla mt-4">
        <thead><tr><th>Nombre</th><th>Slug</th><th>Orden</th><th>Activa</th></tr></thead>
        <tbody>
        <?php foreach ($categorias as $cat): ?>
        <tr>
            <td>
                <form method="post" action="/panel/cursos/categorias/guardar" class="flex flex-wrap items-center gap-2">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $cat['id']) ?>">
                    <input name="nombre" value="<?= $e((string) $cat['nombre']) ?>"
                           class="campo" <?= $editable ? '' : 'disabled' ?>>
            </td>
            <td class="font-mono"><?= $e((string) $cat['slug']) ?></td>
            <td>
                    <input name="orden" type="number" value="<?= $e((string) $cat['orden']) ?>"
                           class="campo w-20 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </td>
            <td>
                    <input type="checkbox" name="activa" value="1"
                           <?= (int) $cat['activa'] === 1 ? 'checked' : '' ?>
                           <?= $editable ? '' : 'disabled' ?>>
                    <?php if ($editable): ?>
                    <button type="submit" class="boton-secundario ml-2">Guardar</button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($editable): ?>
    <form method="post" action="/panel/cursos/categorias/guardar" class="tarjeta mt-6 flex flex-wrap items-end gap-2 p-4">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="activa" value="1">
        <div>
            <label class="rotulo">Nueva categoría</label>
            <input name="nombre" class="campo mt-1" required>
        </div>
        <div>
            <label class="rotulo">Orden</label>
            <input name="orden" type="number" value="0" class="campo mt-1 font-mono w-20">
        </div>
        <button type="submit" class="boton">Crear categoría</button>
    </form>
    <?php endif; ?>
<?php };

require __DIR__ . '/_disposicion.php';
