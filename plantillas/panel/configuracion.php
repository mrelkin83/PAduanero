<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Formulario generado desde los metadatos de las propias filas. Añadir un
 * parámetro es un INSERT; esta pantalla no se toca.
 *
 * @var \App\Panel\Contexto $ctx
 * @var array<string,array{titulo:string,filas:list<\App\Modelos\Configuracion>}> $grupos
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Configuración';

$contenido = static function () use ($e, $ctx, $grupos): void { ?>

    <p class="text-sm text-acero">
        Cada cambio queda en la bitácora con su autor y su motivo.
    </p>

    <?php foreach ($grupos as $grupo): ?>
    <section class="mt-8">
        <h2 class="rotulo"><?= $e($grupo['titulo']) ?></h2>

        <div class="mt-3 space-y-3">
            <?php foreach ($grupo['filas'] as $fila): ?>
                <?php $editable = $ctx->permisos->puedeEditarConfiguracion($ctx->usuario, $fila->rolMinimo); ?>

                <form method="post" action="/panel/configuracion" class="tarjeta p-4">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="clave" value="<?= $e($fila->clave) ?>">

                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <label for="c-<?= $e($fila->clave) ?>" class="font-semibold">
                            <?= $e($fila->etiqueta) ?>
                        </label>
                        <code class="mascara"><?= $e($fila->clave) ?></code>
                    </div>

                    <?php if ($fila->ayuda !== null && $fila->ayuda !== ''): ?>
                        <p class="mt-1 text-sm text-acero"><?= $e($fila->ayuda) ?></p>
                    <?php endif; ?>

                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <div class="min-w-56 flex-1">
                            <?php if ($fila->tipo === 'booleano'): ?>
                                <select id="c-<?= $e($fila->clave) ?>" name="valor" class="campo"
                                        <?= $editable ? '' : 'disabled' ?>>
                                    <option value="1" <?= $fila->valor === true ? 'selected' : '' ?>>Sí</option>
                                    <option value="0" <?= $fila->valor === true ? '' : 'selected' ?>>No</option>
                                </select>

                            <?php elseif ($fila->opciones !== null): ?>
                                <select id="c-<?= $e($fila->clave) ?>" name="valor" class="campo"
                                        <?= $editable ? '' : 'disabled' ?>>
                                    <?php foreach ($fila->opciones as $opcion): ?>
                                        <option value="<?= $e((string) $opcion) ?>"
                                            <?= $fila->valor === $opcion ? 'selected' : '' ?>>
                                            <?= $e((string) $opcion) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($fila->tipo === 'json'): ?>
                                <textarea id="c-<?= $e($fila->clave) ?>" name="valor" rows="2"
                                          class="campo font-mono text-xs"
                                          <?= $editable ? '' : 'disabled' ?>><?= $e(json_encode($fila->valor, JSON_UNESCAPED_UNICODE) ?: '') ?></textarea>

                            <?php else: ?>
                                <input id="c-<?= $e($fila->clave) ?>" name="valor"
                                       type="<?= in_array($fila->tipo, ['entero', 'decimal'], true) ? 'number' : 'text' ?>"
                                       <?= $fila->tipo === 'decimal' ? 'step="0.01"' : '' ?>
                                       <?= $fila->minimo !== null ? 'min="' . $e((string) $fila->minimo) . '"' : '' ?>
                                       <?= $fila->maximo !== null ? 'max="' . $e((string) $fila->maximo) . '"' : '' ?>
                                       value="<?= $e(is_scalar($fila->valor) ? (string) $fila->valor : '') ?>"
                                       class="campo" <?= $editable ? '' : 'disabled' ?>>
                            <?php endif; ?>
                        </div>

                        <?php if ($editable): ?>
                            <input name="motivo" placeholder="Motivo (opcional)" class="campo w-full sm:w-56">
                            <button type="submit" class="boton">Guardar</button>
                        <?php else: ?>
                            <p class="text-sm text-acero">Solo un administrador técnico puede cambiarla.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($fila->requiereReinicio): ?>
                        <p class="mt-2 text-xs text-ambar">Requiere reiniciar el worker del outbox.</p>
                    <?php endif; ?>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

<?php };

require __DIR__ . '/_disposicion.php';
