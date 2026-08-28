<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var array<string,mixed>|null $curso
 * @var list<array{id:string,titulo:string,orden:int,lecciones:list<array<string,mixed>>}> $modulos
 * @var list<array{id:string,nombre:string}> $categorias
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = $curso === null ? 'Nuevo curso' : 'Editar curso';
$esNuevo = $curso === null;

$contenido = static function () use ($e, $ctx, $curso, $modulos, $categorias, $esNuevo): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo"><?= $esNuevo ? 'Nuevo curso' : 'Editar curso' ?></h2>

    <form method="post" action="/panel/cursos/guardar" class="tarjeta mt-4 p-4">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="id" value="<?= $e((string) ($curso['id'] ?? '')) ?>">

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="rotulo">Título</label>
                <input name="titulo" value="<?= $e((string) ($curso['titulo'] ?? '')) ?>"
                       class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div>
                <label class="rotulo">Categoría</label>
                <select name="categoria_id" class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= $e((string) $cat['id']) ?>"
                        <?= ($curso['categoria_id'] ?? '') === $cat['id'] ? 'selected' : '' ?>>
                        <?= $e((string) $cat['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="rotulo">Nivel</label>
                <select name="nivel" class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
                    <?php foreach (['basico', 'intermedio', 'avanzado'] as $opcion): ?>
                    <option value="<?= $e($opcion) ?>" <?= ($curso['nivel'] ?? 'basico') === $opcion ? 'selected' : '' ?>>
                        <?= $e(ucfirst($opcion)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Resumen (una línea, para la tarjeta del catálogo)</label>
                <input name="resumen" value="<?= $e((string) ($curso['resumen'] ?? '')) ?>"
                       class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Descripción</label>
                <textarea name="descripcion" rows="4" class="campo mt-1"
                          <?= $editable ? '' : 'disabled' ?>><?= $e((string) ($curso['descripcion'] ?? '')) ?></textarea>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Lo que aprenderá (una línea por punto)</label>
                <textarea name="lo_que_aprendera" rows="4" class="campo mt-1"
                          <?= $editable ? '' : 'disabled' ?>><?= $e((string) ($curso['lo_que_aprendera'] ?? '')) ?></textarea>
            </div>

            <div>
                <label class="rotulo">Precio (pesos)</label>
                <input name="precio_cop" type="number" min="0" step="1000"
                       value="<?= $e((string) ($curso['precio_cop'] ?? '')) ?>"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div>
                <label class="rotulo">Orden</label>
                <input name="orden" type="number" value="<?= $e((string) ($curso['orden'] ?? '0')) ?>"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Imagen de portada (nombre de archivo en public/img/cursos/)</label>
                <input name="imagen_portada" value="<?= $e((string) ($curso['imagen_portada'] ?? '')) ?>"
                       class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
            </div>
        </div>

        <?php if ($editable): ?>
        <button type="submit" class="boton mt-4">Guardar curso</button>
        <?php endif; ?>
    </form>

    <?php if (!$esNuevo && $editable): ?>
    <form method="post" action="/panel/cursos/<?= $curso['estado'] === 'publicado' ? 'despublicar' : 'publicar' ?>" class="mt-3">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="id" value="<?= $e((string) $curso['id']) ?>">
        <button type="submit" class="boton-secundario">
            <?= $curso['estado'] === 'publicado' ? 'Pasar a borrador' : 'Publicar' ?>
        </button>
    </form>
    <?php endif; ?>

    <?php if (!$esNuevo): ?>
    <section class="mt-8">
        <h2 class="rotulo">Temario</h2>

        <?php foreach ($modulos as $modulo): ?>
        <div class="tarjeta mt-3 p-4">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold"><?= $e((string) $modulo['titulo']) ?></h3>
                <?php if ($editable): ?>
                <form method="post" action="/panel/cursos/modulos/eliminar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $modulo['id']) ?>">
                    <button type="submit" class="text-sm underline">Eliminar módulo</button>
                </form>
                <?php endif; ?>
            </div>

            <ul class="mt-2 space-y-1 text-sm text-acero">
                <?php foreach ($modulo['lecciones'] as $leccion): ?>
                <li class="flex items-center justify-between gap-4">
                    <span>
                        <?= $e((string) $leccion['titulo']) ?>
                        <?= $leccion['duracion_min'] !== null ? ' · ' . $e((string) $leccion['duracion_min']) . ' min' : '' ?>
                        <?= (int) $leccion['vista_previa_gratis'] === 1 ? ' · vista previa gratis' : '' ?>
                    </span>
                    <span class="flex items-center gap-3">
                    <a href="/panel/cursos/lecciones/editar?id=<?= $e((string) $leccion['id']) ?>" class="underline">Editar contenido</a>
                    <?php if ($editable): ?>
                    <form method="post" action="/panel/cursos/lecciones/eliminar">
                        <?= $ctx->csrf->campoOculto() ?>
                        <input type="hidden" name="id" value="<?= $e((string) $leccion['id']) ?>">
                        <button type="submit" class="underline">Eliminar</button>
                    </form>
                    <?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($editable): ?>
            <form method="post" action="/panel/cursos/lecciones/agregar" class="mt-3 flex flex-wrap items-end gap-2">
                <?= $ctx->csrf->campoOculto() ?>
                <input type="hidden" name="modulo_id" value="<?= $e((string) $modulo['id']) ?>">
                <div>
                    <label class="rotulo">Nueva lección</label>
                    <input name="titulo" class="campo mt-1" required>
                </div>
                <div>
                    <label class="rotulo">Minutos</label>
                    <input name="duracion_min" type="number" min="0" class="campo mt-1 font-mono">
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="vista_previa_gratis" value="1"> Vista previa gratis
                </label>
                <button type="submit" class="boton-secundario">Agregar lección</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($editable): ?>
        <form method="post" action="/panel/cursos/modulos/agregar" class="tarjeta mt-3 flex flex-wrap items-end gap-2 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <input type="hidden" name="curso_id" value="<?= $e((string) $curso['id']) ?>">
            <div>
                <label class="rotulo">Nuevo módulo</label>
                <input name="titulo" class="campo mt-1" required>
            </div>
            <button type="submit" class="boton-secundario">Agregar módulo</button>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>
<?php };

require __DIR__ . '/_disposicion.php';
