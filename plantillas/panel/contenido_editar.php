<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Editor de un bloque. El formulario se GENERA de la estructura del JSON:
 * cada clave escalar es un campo, cada lista de objetos una serie de
 * tarjetas con añadir/quitar. La estructura no se puede cambiar desde aquí
 * a propósito — las claves son las que las plantillas de la página esperan.
 *
 * @var \App\Panel\Contexto $ctx
 * @var array<string,mixed> $bloque
 * @var array<string,mixed> $datos      el JSON del bloque, ya decodificado
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Editar: ' . (string) $bloque['clave'];

/** Nombre humano de una clave del JSON. */
$rotulo = static function (string $clave): string {
    $mapa = [
        'cta' => 'Botón (texto)', 'cta_texto' => 'Botón (texto)', 'cta_url' => 'Botón (enlace)',
        'url' => 'Enlace', 'imagen' => 'Imagen (ruta en /img)', 'items' => 'Elementos',
        'pasos' => 'Pasos', 'texto' => 'Texto', 'autor' => 'Autor', 'cargo' => 'Cargo',
        'empresa' => 'Empresa', 'titulo' => 'Título', 'subtitulo' => 'Subtítulo',
        'descripcion' => 'Descripción', 'nota' => 'Nota', 'valor' => 'Valor',
        'etiqueta' => 'Etiqueta', 'nombre' => 'Nombre',
    ];

    return $mapa[$clave] ?? ucfirst(str_replace('_', ' ', $clave));
};

$contenido = static function () use ($e, $ctx, $bloque, $datos, $rotulo): void {
    $editable = $ctx->puede('contenido.editar');

    /**
     * Pinta un valor del JSON como campos de formulario, recursivamente.
     * $nombre es el name= completo (c[items][0][autor]); $clave la última
     * clave, para el rótulo y los casos especiales.
     */
    $campo = function (string $nombre, string $clave, mixed $valor, int $nivel = 0) use (&$campo, $e, $rotulo, $editable): void {
        // La marca de relleno: casilla con semántica invertida y explicada.
        if ($clave === 'pendiente') {
            ?>
            <label class="mt-2 flex items-center gap-2 text-sm">
                <input type="hidden" name="<?= $e($nombre) ?>" value="0">
                <input type="checkbox" name="<?= $e($nombre) ?>" value="1"
                       <?= $valor ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                Dato pendiente de confirmar (la página lo pinta en gris; desmarque al cargar el dato real)
            </label>
            <?php
            return;
        }

        if (is_bool($valor)) {
            ?>
            <label class="mt-2 flex items-center gap-2 text-sm">
                <input type="hidden" name="<?= $e($nombre) ?>" value="0">
                <input type="checkbox" name="<?= $e($nombre) ?>" value="1"
                       <?= $valor ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                <?= $e($rotulo($clave)) ?>
            </label>
            <?php
            return;
        }

        if (is_scalar($valor) || $valor === null) {
            $texto = (string) $valor;
            ?>
            <div class="mt-2">
                <label class="rotulo"><?= $e($rotulo($clave)) ?></label>
                <?php if (mb_strlen($texto) > 90 || str_contains($texto, "\n")): ?>
                    <textarea name="<?= $e($nombre) ?>" rows="<?= min(8, max(2, (int) ceil(mb_strlen($texto) / 90))) ?>"
                              class="campo mt-1" <?= $editable ? '' : 'disabled' ?>><?= $e($texto) ?></textarea>
                <?php else: ?>
                    <input name="<?= $e($nombre) ?>" value="<?= $e($texto) ?>"
                           class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
                <?php endif; ?>
            </div>
            <?php
            return;
        }

        // Lista: cada elemento en su tarjeta, con quitar por elemento y
        // añadir al final. Los botones guardan también lo editado.
        if (is_array($valor) && array_is_list($valor)) {
            ?>
            <fieldset class="mt-4">
                <legend class="rotulo"><?= $e($rotulo($clave)) ?> (<?= count($valor) ?>)</legend>
                <?php foreach ($valor as $i => $item): ?>
                    <div class="tarjeta mt-3 p-3">
                        <div class="flex items-center justify-between">
                            <p class="rotulo"><?= $e($rotulo($clave)) ?> <?= $i + 1 ?></p>
                            <?php if ($editable && $nivel === 0): ?>
                                <button type="submit" name="quitar" value="<?= $e($clave . ':' . $i) ?>"
                                        class="text-sm underline"
                                        onclick="return confirm('¿Eliminar el elemento <?= $i + 1 ?>? Se guarda el resto tal como está en pantalla.')">
                                    Eliminar
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if (is_array($item)): ?>
                            <?php foreach ($item as $k => $v) { $campo($nombre . '[' . $i . '][' . $k . ']', (string) $k, $v, $nivel + 1); } ?>
                        <?php else: ?>
                            <?php $campo($nombre . '[' . $i . ']', $clave, $item, $nivel + 1); ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($editable && $nivel === 0 && $valor !== []): ?>
                    <button type="submit" name="agregar" value="<?= $e($clave) ?>" class="boton mt-3">
                        Añadir <?= $e(mb_strtolower($rotulo($clave))) ?>
                    </button>
                <?php endif; ?>
            </fieldset>
            <?php
            return;
        }

        // Objeto: sus claves, una a una.
        if (is_array($valor)) {
            ?>
            <fieldset class="mt-4 <?= $nivel > 0 ? 'pl-3' : '' ?>">
                <legend class="rotulo"><?= $e($rotulo($clave)) ?></legend>
                <?php foreach ($valor as $k => $v) { $campo($nombre . '[' . $k . ']', (string) $k, $v, $nivel + 1); } ?>
            </fieldset>
            <?php
        }
    };

    ?>
    <p class="text-sm text-acero">
        <a class="underline" href="/panel/contenido">← Todos los bloques</a> ·
        Los cambios se ven en la página en la próxima visita (la caché se
        invalida sola al guardar).
    </p>

    <form method="post" action="/panel/contenido/guardar" class="mt-4 max-w-3xl">
        <?= $ctx->csrf->campoOculto() ?>
        <input type="hidden" name="clave" value="<?= $e((string) $bloque['clave']) ?>">

        <div class="tarjeta p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="rotulo">Título de la sección</label>
                    <input name="titulo" value="<?= $e((string) ($bloque['titulo'] ?? '')) ?>"
                           class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
                </div>
                <div class="sm:col-span-2">
                    <label class="rotulo">Subtítulo</label>
                    <textarea name="subtitulo" rows="2" class="campo mt-1"
                              <?= $editable ? '' : 'disabled' ?>><?= $e((string) ($bloque['subtitulo'] ?? '')) ?></textarea>
                </div>
                <div>
                    <label class="rotulo">Orden en la página</label>
                    <input name="orden" type="number" min="0" value="<?= (int) $bloque['orden'] ?>"
                           class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="visible" value="0">
                        <input type="checkbox" name="visible" value="1"
                               <?= (int) $bloque['visible'] === 1 ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                        Visible en la página
                    </label>
                </div>
            </div>

            <?php foreach ($datos as $k => $v) { $campo('c[' . $k . ']', (string) $k, $v); } ?>

            <?php if ($editable): ?>
                <button type="submit" class="boton mt-5">Guardar bloque</button>
            <?php endif; ?>
        </div>
    </form>

    <details class="mt-4 max-w-3xl text-sm text-acero">
        <summary class="cursor-pointer">Ver el JSON guardado (solo lectura)</summary>
        <pre class="tarjeta mt-2 overflow-x-auto p-3 font-mono text-xs"><?= $e(json_encode(json_decode((string) $bloque['contenido']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '') ?></pre>
    </details>
<?php };

require __DIR__ . '/_disposicion.php';
