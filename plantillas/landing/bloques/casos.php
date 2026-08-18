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
<section id="situaciones" class="py-20 md:py-[5rem]">
    <div class="mx-auto max-w-[78rem] px-6 md:px-20">

        <?php /* Esta sección va centrada y las demás no. Es deliberado: es la
                 única que el lector no lee, sino que RECORRE buscándose. Un
                 encabezado centrado sobre dos columnas simétricas dice
                 «catálogo»; alineado a la izquierda diría «argumento», y
                 entonces se leería en vez de buscarse. */ ?>
        <div class="text-center">
            <p class="rotulo rotulo-capsula">
                <span class="punto" aria-hidden="true"></span>
                Índice de situaciones
            </p>

            <h2 class="titular-seccion mx-auto mt-8 max-w-2xl">
                <?= $e($bloque->titulo) ?>
            </h2>

            <?php if ($bloque->subtitulo !== null): ?>
            <p class="cuerpo mx-auto mt-5 max-w-xl"><?= $e($bloque->subtitulo) ?></p>
            <?php endif; ?>
        </div>

        <div class="mt-16 grid gap-6 md:mt-20 md:grid-cols-2">
            <?php foreach ($columnas as $clave => [$encabezado, $letra]): ?>
                <?php $items = $bloque->lista($clave); ?>
                <?php if ($items === []) { continue; } ?>

                <div class="tarjeta p-8 md:p-10">
                    <h3 class="flex items-center gap-3">
                        <span class="indice-num"><?= $e($letra) ?></span>
                        <span class="rotulo text-acero"><?= $e($encabezado) ?></span>
                    </h3>

                    <ul class="mt-6">
                        <?php foreach ($items as $item): ?>
                        <li class="indice-fila"><?= $e(is_string($item) ? $item : '') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-16 text-center">
            <?= \App\Soporte\Vista::botonWhatsapp($waBase, 'Cuénteme su caso') ?>
        </div>
    </div>
</section>
