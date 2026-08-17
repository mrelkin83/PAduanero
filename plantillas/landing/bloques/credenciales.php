<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Credenciales.
 *
 * Aquí sí va el retrato: después de que el lector se ha reconocido en el
 * índice y quiere saber quién responde.
 *
 * Los títulos pasaron de mono a serif y el índice numerado se quedó en mono,
 * que es el reparto correcto: «Especialista en Derecho Tributario» es el
 * nombre de un título, no un código, y componerlo en monoespaciada lo
 * disfrazaba de número de referencia. El código es el `01`.
 *
 * Lo que no cambia es que se presentan como datos y no como argumento de
 * venta: son los títulos que Pedro tiene, sin adjetivo alrededor. Eso es lo
 * que exige el marco de publicidad del abogado.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var callable $e
 */

$imagen = $bloque->texto('imagen', '/img/pedro-perfil.jpg');
$items = $bloque->lista('items');
?>
<section class="border-t border-acero/15 bg-papel-puro py-20 md:py-28">
    <div class="mx-auto grid max-w-6xl items-center gap-12 px-5 md:grid-cols-[19rem_1fr] md:gap-20 md:px-8">

        <div class="marco-retrato">
            <?= Vista::imagen(
                basename($imagen),
                'Retrato de Pedro, abogado titular del despacho',
                892,
                1196,
                'w-44 md:w-full h-auto rounded-sm',
                sizes: '(min-width: 768px) 19rem, 11rem',
            ) ?>
        </div>

        <div>
            <p class="rotulo">Quién responde</p>

            <h2 class="titular-seccion mt-5 text-[2rem] md:text-[2.75rem]">
                <?= $e($bloque->titulo) ?>
            </h2>

            <hr class="filete mt-7">

            <ul class="mt-10">
                <?php foreach ($items as $i => $item): ?>
                    <?php
                    if (!is_array($item)) {
                        continue;
                    }
                    $titulo = is_string($item['titulo'] ?? null) ? $item['titulo'] : '';
                    $detalle = is_string($item['detalle'] ?? null) ? $item['detalle'] : '';
                    ?>
                    <li class="grid grid-cols-[2.5rem_1fr] items-baseline gap-x-4 border-t border-acero/20 py-5">
                        <span class="credencial-num" aria-hidden="true">
                            <?= $e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?>
                        </span>

                        <div>
                            <p class="titular-menor text-[1.1875rem] md:text-[1.375rem]">
                                <?= $e($titulo) ?>
                            </p>
                            <?php if ($detalle !== ''): ?>
                            <p class="mt-1.5 text-[0.9375rem] text-acero"><?= $e($detalle) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</section>
