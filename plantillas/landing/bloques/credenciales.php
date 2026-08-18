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
<section class="py-20 md:py-[5rem]">
    <div class="mx-auto grid max-w-[78rem] items-center gap-12 px-6 md:grid-cols-[1fr_1.15fr] md:gap-20 md:px-20">

        <?php /* El retrato va dentro de una tarjeta de grafito y no suelto
                 sobre el fondo. Sobre negro puro, un JPEG recortado se ve
                 pegado; con el escalón de grafito debajo, la foto se apoya
                 en algo y el conjunto recupera profundidad sin una sola
                 sombra. */ ?>
        <div class="tarjeta overflow-hidden p-3">
            <?= Vista::imagen(
                basename($imagen),
                'Retrato de Pedro, abogado titular del despacho',
                892,
                1196,
                'w-full h-auto rounded-[0.125rem]',
                sizes: '(min-width: 768px) 34rem, 100vw',
            ) ?>
        </div>

        <div>
            <p class="rotulo">Quién responde</p>

            <h2 class="titular-seccion mt-8">
                <?= $e($bloque->titulo) ?>
            </h2>

            <ul class="mt-12 space-y-4">
                <?php foreach ($items as $i => $item): ?>
                    <?php
                    if (!is_array($item)) {
                        continue;
                    }
                    $titulo = is_string($item['titulo'] ?? null) ? $item['titulo'] : '';
                    $detalle = is_string($item['detalle'] ?? null) ? $item['detalle'] : '';
                    ?>
                    <li class="tarjeta flex items-start gap-6 p-6 md:p-7">
                        <span class="cifra-oro mt-0.5" aria-hidden="true">
                            <?= $e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?>
                        </span>

                        <div>
                            <p class="titular-menor"><?= $e($titulo) ?></p>
                            <?php if ($detalle !== ''): ?>
                            <p class="cuerpo mt-2 text-[0.9375rem]"><?= $e($detalle) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</section>
