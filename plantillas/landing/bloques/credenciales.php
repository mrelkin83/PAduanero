<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Credenciales.
 *
 * Aquí sí va el retrato: después de que el lector se ha reconocido en el
 * índice y quiere saber quién responde. Las credenciales van en mono porque
 * son títulos formales, no adjetivos — y presentarlas como datos y no como
 * argumento de venta es lo que exige el marco de publicidad del abogado.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var callable $e
 */

$imagen = $bloque->texto('imagen', '/img/pedro-perfil.jpg');
$items = $bloque->lista('items');
?>
<section class="border-t border-acero/15 bg-papel-puro py-16 md:py-24">
    <div class="mx-auto grid max-w-6xl items-center gap-10 px-5 md:grid-cols-[20rem_1fr] md:gap-16 md:px-8">

        <?= Vista::imagen(
            basename($imagen),
            'Retrato de Pedro, abogado titular del despacho',
            892,
            1196,
            'w-44 md:w-full h-auto rounded-sm',
            sizes: '(min-width: 768px) 20rem, 11rem',
        ) ?>

        <div>
            <p class="rotulo">Quién responde</p>

            <h2 class="titular-seccion mt-4 text-[1.75rem] md:text-4xl">
                <?= $e($bloque->titulo) ?>
            </h2>

            <hr class="filete mt-6">

            <ul class="mt-8 space-y-5">
                <?php foreach ($items as $item): ?>
                    <?php
                    if (!is_array($item)) {
                        continue;
                    }
                    $titulo = is_string($item['titulo'] ?? null) ? $item['titulo'] : '';
                    $detalle = is_string($item['detalle'] ?? null) ? $item['detalle'] : '';
                    ?>
                    <li class="border-l-2 border-sello/35 pl-4">
                        <p class="font-mono text-[0.9375rem] font-medium leading-snug">
                            <?= $e($titulo) ?>
                        </p>
                        <?php if ($detalle !== ''): ?>
                        <p class="mt-1 text-[0.9375rem] text-acero"><?= $e($detalle) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</section>
