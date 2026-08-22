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
<section id="situaciones" class="py-20 md:py-[8rem] relative isolate">
    <div class="absolute inset-0 bg-gradient-to-b from-transparent via-oro/5 to-transparent pointer-events-none"></div>
    <div class="mx-auto max-w-[84rem] px-6 md:px-12 relative z-10">

        <div class="text-center revelar">
            <p class="rotulo rotulo-capsula mb-4 md:mb-6 mx-auto">
                <span class="punto" aria-hidden="true"></span>
                Índice de situaciones
            </p>

            <h2 class="titular-seccion mx-auto max-w-3xl">
                <?= $e($bloque->titulo) ?>
            </h2>

            <?php if ($bloque->subtitulo !== null): ?>
            <p class="mt-6 md:mt-8 text-[1.1rem] md:text-xl text-acero max-w-2xl mx-auto leading-relaxed font-light"><?= $e($bloque->subtitulo) ?></p>
            <?php endif; ?>
        </div>

        <div class="mt-12 md:mt-24 grid gap-6 md:gap-8 md:grid-cols-2 items-start">
            <?php foreach ($columnas as $clave => [$encabezado, $letra]): ?>
                <?php $items = $bloque->lista($clave); ?>
                <?php if ($items === []) { continue; } ?>

                <div class="doble-bisel p-6 md:p-12 md:hover:bg-white/5 transition-colors duration-500 revelar group" style="--retardo: <?= $clave === 'aduanero' ? '100ms' : '200ms' ?>">
                    <h3 class="flex flex-col gap-2 md:gap-4 mb-8 md:mb-10 pb-6 md:pb-8 border-b border-linea/50 relative">
                        <span class="text-5xl md:text-7xl font-light text-oro/20 md:group-hover:text-oro/40 transition-colors duration-500 absolute -top-2 md:-top-4 right-0 pointer-events-none"><?= $e($letra) ?></span>
                        <span class="text-xl md:text-2xl font-medium tracking-tight text-white pr-12"><?= $e($encabezado) ?></span>
                    </h3>

                    <ul class="space-y-0.5 md:space-y-1">
                        <?php foreach ($items as $item): ?>
                        <li class="indice-fila group/item text-[0.95rem] md:text-lg py-3 md:py-4 border-t border-linea/30 first:border-0 relative overflow-hidden transition-all duration-500">
                            <span class="relative z-10 block md:group-hover/item:pl-4 transition-all duration-500 md:group-hover/item:text-oro-claro"><?= $e(is_string($item) ? $item : '') ?></span>
                            <div class="absolute inset-0 bg-gradient-to-r from-oro/10 to-transparent opacity-0 md:group-hover/item:opacity-100 transition-opacity duration-500 -z-10"></div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-16 md:mt-24 text-center revelar" style="--retardo: 300ms">
            <?= \App\Soporte\Vista::botonWhatsapp($waBase, 'Cuénteme su caso') ?>
        </div>
    </div>
</section>
