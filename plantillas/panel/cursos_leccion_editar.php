<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var array<string,mixed> $leccion
 * @var list<array<string,mixed>> $materiales
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Editar lección';

$contenido = static function () use ($e, $ctx, $leccion, $materiales): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo">Editar lección</h2>

    <form method="post" action="/panel/cursos/lecciones/guardar" class="tarjeta mt-4 p-4">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="id" value="<?= $e((string) $leccion['id']) ?>">

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="rotulo">Título</label>
                <input name="titulo" value="<?= $e((string) $leccion['titulo']) ?>"
                       class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div>
                <label class="rotulo">Minutos</label>
                <input name="duracion_min" type="number" min="0"
                       value="<?= $e((string) ($leccion['duracion_min'] ?? '')) ?>"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="vista_previa_gratis" value="1"
                       <?= (int) $leccion['vista_previa_gratis'] === 1 ? 'checked' : '' ?>
                       <?= $editable ? '' : 'disabled' ?>>
                Vista previa gratis
            </label>

            <div class="sm:col-span-2">
                <label class="rotulo">Video de la lección (archivo local)</label>
                <input name="video_archivo" value="<?= $e((string) ($leccion['video_archivo'] ?? '')) ?>"
                       placeholder="mi-leccion.mp4"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
                <p class="mt-1 text-xs text-acero">
                    Sube el archivo por SFTP a
                    <code class="font-mono">storage/cursos/videos/<?= $e((string) ($leccion['id'] ?? '&lt;id-lección&gt;')) ?>/</code>
                    y escribe aquí solo el nombre del archivo (ej. <code class="font-mono">mi-leccion.mp4</code>).
                    Se sirve protegido: solo lo ve quien compró el curso. Tiene prioridad sobre Bunny.
                </p>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">ID de video en Bunny Stream (alternativa)</label>
                <input name="video_bunny_id" value="<?= $e((string) ($leccion['video_bunny_id'] ?? '')) ?>"
                       placeholder="Solo si usas Bunny en vez del archivo local"
                       class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
            </div>

            <div class="sm:col-span-2">
                <label class="rotulo">Contenido de texto</label>
                <textarea name="contenido_texto" rows="8" class="campo mt-1"
                          <?= $editable ? '' : 'disabled' ?>><?= $e((string) ($leccion['contenido_texto'] ?? '')) ?></textarea>
            </div>
        </div>

        <?php if ($editable): ?>
        <button type="submit" class="boton mt-4">Guardar lección</button>
        <?php endif; ?>
    </form>

    <section class="mt-8">
        <h2 class="rotulo">Materiales descargables</h2>
        <ul class="mt-3 space-y-2">
            <?php foreach ($materiales as $m): ?>
            <li class="tarjeta flex items-center justify-between p-3">
                <span><?= $e((string) $m['nombre']) ?> <span class="text-xs text-acero">(<?= $e(number_format((int) $m['tamanio_bytes'] / 1024, 0)) ?> KB)</span></span>
                <?php if ($editable): ?>
                <form method="post" action="/panel/cursos/lecciones/materiales/eliminar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                    <input type="hidden" name="leccion_id" value="<?= $e((string) $leccion['id']) ?>">
                    <button type="submit" class="underline">Eliminar</button>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
            <?php if ($materiales === []): ?>
            <li class="text-sm text-acero">Todavía no hay materiales.</li>
            <?php endif; ?>
        </ul>

        <?php if ($editable): ?>
        <form method="post" action="/panel/cursos/lecciones/materiales/agregar" enctype="multipart/form-data"
              class="tarjeta mt-3 flex flex-wrap items-end gap-2 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <input type="hidden" name="leccion_id" value="<?= $e((string) $leccion['id']) ?>">
            <div>
                <label class="rotulo">Nombre a mostrar</label>
                <input name="nombre" class="campo mt-1" required>
            </div>
            <div>
                <label class="rotulo">Archivo</label>
                <input type="file" name="archivo" class="campo mt-1" required>
            </div>
            <button type="submit" class="boton-secundario">Subir material</button>
        </form>
        <p class="mt-1 text-xs text-acero">PDF, Word, Excel, ZIP, JPG o PNG. Máx. 30 MB.</p>
        <?php endif; ?>
    </section>
<?php };

require __DIR__ . '/_disposicion.php';
