<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Plantilla del certificado: imagen de fondo + posición de cada dato.
 *
 * @var \App\Panel\Contexto $ctx
 * @var string|null $imagenFondo
 * @var array<string,array<string,mixed>> $campos
 * @var array<string,string> $etiquetas
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Plantilla del certificado';

$contenido = static function () use ($e, $ctx, $imagenFondo, $campos, $etiquetas): void {
    $editable = $ctx->puede('cursos.editar');
    $alineaciones = ['left' => 'Izquierda', 'center' => 'Centrado', 'right' => 'Derecha'];
    ?>
    <h2 class="rotulo">Plantilla del certificado</h2>
    <p class="mt-2 text-sm text-acero">
        Sube el diseño del certificado como imagen (apaisado, tamaño carta) y ubica
        encima cada dato. Los alumnos reciben este diseño con su nombre, el curso, la
        fecha, el código y un <strong>QR de verificación</strong>. Si no subes imagen,
        se usa un diseño por defecto. Ajusta las posiciones y usa
        <a class="underline" href="/panel/cursos/certificado/ejemplo" target="_blank">Ver ejemplo (PDF)</a>
        para revisar cómo queda.
    </p>

    <form method="post" action="/panel/cursos/certificado" enctype="multipart/form-data" class="tarjeta mt-4 p-4">
        <?= $ctx->csrf->campoOculto() ?>

        <div class="mb-4">
            <label class="rotulo">Imagen de fondo (JPG, PNG o WebP · máx. 5 MB)</label>
            <?php if ($imagenFondo !== null): ?>
                <p class="mt-1 text-sm text-acero">Actual: <span class="font-mono"><?= $e($imagenFondo) ?></span> — sube otra para reemplazarla.</p>
            <?php endif; ?>
            <input type="file" name="imagen_fondo" accept="image/jpeg,image/png,image/webp"
                   class="mt-1 block text-sm" <?= $editable ? '' : 'disabled' ?>>
        </div>

        <p class="rotulo mt-4">Posición de cada dato</p>
        <p class="text-xs text-acero mb-2">
            «Alto» y «lateral» van en % de la hoja. Para el QR, «tamaño» es su ancho en %
            de la hoja; para el texto, es el tamaño de letra en puntos.
        </p>

        <div class="overflow-x-auto">
        <table class="tabla">
            <thead><tr>
                <th>Dato</th><th>Mostrar</th><th>Alto %</th><th>Alineación</th>
                <th>Lateral %</th><th>Tamaño</th><th>Color</th>
            </tr></thead>
            <tbody>
            <?php foreach ($etiquetas as $clave => $etiqueta):
                $c = $campos[$clave] ?? []; $esQr = $clave === 'qr'; ?>
                <tr>
                    <td><?= $e($etiqueta) ?></td>
                    <td>
                        <input type="hidden" name="campo_<?= $e($clave) ?>_visible" value="0">
                        <input type="checkbox" name="campo_<?= $e($clave) ?>_visible" value="1"
                               <?= !empty($c['visible']) ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                    </td>
                    <td><input type="number" min="0" max="100" name="campo_<?= $e($clave) ?>_y"
                               value="<?= $e((string) ($c['y'] ?? 0)) ?>" class="campo w-20 font-mono" <?= $editable ? '' : 'disabled' ?>></td>
                    <td>
                        <select name="campo_<?= $e($clave) ?>_alineacion" class="campo" <?= $editable ? '' : 'disabled' ?>>
                            <?php foreach ($alineaciones as $v => $et): ?>
                                <option value="<?= $e($v) ?>" <?= ($c['alineacion'] ?? '') === $v ? 'selected' : '' ?>><?= $e($et) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" min="0" max="100" name="campo_<?= $e($clave) ?>_x"
                               value="<?= $e((string) ($c['x'] ?? 0)) ?>" class="campo w-20 font-mono" <?= $editable ? '' : 'disabled' ?>></td>
                    <td><input type="number" min="4" max="96" name="campo_<?= $e($clave) ?>_tamano"
                               value="<?= $e((string) ($c['tamano'] ?? 12)) ?>" class="campo w-20 font-mono" <?= $editable ? '' : 'disabled' ?>></td>
                    <td>
                        <?php if ($esQr): ?>
                            <span class="text-xs text-acero">—</span>
                            <input type="hidden" name="campo_qr_color" value="#000000">
                        <?php else: ?>
                            <input type="color" name="campo_<?= $e($clave) ?>_color"
                                   value="<?= $e((string) ($c['color'] ?? '#000000')) ?>" <?= $editable ? '' : 'disabled' ?>>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($editable): ?>
        <div class="mt-4 flex items-center gap-3">
            <button type="submit" class="boton">Guardar plantilla</button>
            <a class="boton-secundario" href="/panel/cursos/certificado/ejemplo" target="_blank">Ver ejemplo (PDF)</a>
        </div>
        <?php endif; ?>
    </form>
<?php };

require __DIR__ . '/_disposicion.php';
