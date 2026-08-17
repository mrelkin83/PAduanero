<?php

declare(strict_types=1);

/**
 * Índice de situaciones — el elemento distintivo de la página.
 *
 * Se compone como el índice de un arancel: filas monoespaciadas separadas
 * por filetes finos, en dos columnas por rama. El lector busca SU situación
 * en la lista, y encontrarla es justo el paso previo a escribir. Por eso el
 * botón queda al final del bloque y no antes.
 *
 * Sin numerar: estas situaciones no son una secuencia, son un catálogo.
 * Numerarlas sugeriría un orden que no existe.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var string $waBase
 * @var callable $e
 */

$columnas = [
    'aduanero' => ['Aduanero y comercio exterior', 'A'],
    'tributario' => ['Tributario', 'B'],
];
?>
<section id="situaciones" class="bg-papel py-16 md:py-24">
    <div class="mx-auto max-w-6xl px-5 md:px-8">

        <p class="rotulo">Índice de situaciones</p>

        <h2 class="titular-seccion mt-4 max-w-2xl text-[1.75rem] md:text-4xl">
            <?= $e($bloque->titulo) ?>
        </h2>

        <?php if ($bloque->subtitulo !== null): ?>
        <p class="mt-4 max-w-xl text-acero"><?= $e($bloque->subtitulo) ?></p>
        <?php endif; ?>

        <div class="mt-12 grid gap-x-14 gap-y-10 md:grid-cols-2">
            <?php foreach ($columnas as $clave => [$encabezado, $letra]): ?>
                <?php $items = $bloque->lista($clave); ?>
                <?php if ($items === []) { continue; } ?>

                <div>
                    <h3 class="flex items-baseline gap-3 font-mono text-eyebrow font-medium uppercase tracking-[0.16em] text-acero">
                        <span class="text-sello"><?= $e($letra) ?></span>
                        <?= $e($encabezado) ?>
                    </h3>

                    <ul class="mt-4 border-b border-acero/20">
                        <?php foreach ($items as $item): ?>
                        <li class="indice-fila"><?= $e(is_string($item) ? $item : '') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-12">
            <?= \App\Soporte\Vista::botonWhatsapp($waBase, 'Cuénteme su caso por WhatsApp') ?>
        </div>
    </div>
</section>
