<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Proceso.
 *
 * Numerado, y aquí la numeración sí es información: el orden importa —
 * primero escribe, luego agenda, luego manda documentos, y solo entonces
 * recibe la hoja de ruta.
 *
 * Una sola columna, no una rejilla de dos por dos. En 2×2 el ojo puede
 * recorrer 01→02 bajando o cruzando, y con cuatro pasos que son una
 * secuencia esa ambigüedad se paga: alguien lee «agende la asesoría» como
 * segundo paso o como tercero según por dónde entre. Una columna solo se
 * recorre en un sentido. El precio es una sección más alta, y se paga.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var callable $e
 */

$pasos = $bloque->lista('pasos');
$imagen = $bloque->texto('imagen');
?>
<section id="proceso" class="bg-tinta py-20 md:py-[5rem]">
    <div class="mx-auto grid max-w-[78rem] gap-12 px-6 md:grid-cols-[1fr_1.25fr] md:gap-20 md:px-20">

        <div>
            <p class="rotulo">Metodología</p>

            <h2 class="titular-seccion mt-8">
                <?= $e($bloque->titulo) ?>
            </h2>

            <?php if ($imagen !== ''): ?>
            <div class="tarjeta mt-12 hidden overflow-hidden p-3 md:block">
                <?= Vista::imagen(
                    basename($imagen),
                    $bloque->texto('alt', 'El abogado revisando una declaración de importación'),
                    890,
                    1198,
                    'w-full h-auto rounded-[0.125rem]',
                    sizes: '(min-width: 768px) 28rem, 100vw',
                ) ?>
            </div>
            <?php endif; ?>
        </div>

        <ol class="space-y-4 md:pt-2">
            <?php foreach ($pasos as $paso): ?>
                <?php
                if (!is_array($paso)) {
                    continue;
                }
                $n = is_int($paso['n'] ?? null) ? $paso['n'] : 0;
                ?>
                <li class="tarjeta flex items-start gap-6 p-7 md:gap-8 md:p-8">
                    <span class="cifra-oro mt-0.5" aria-hidden="true">
                        <?= $e(str_pad((string) $n, 2, '0', STR_PAD_LEFT)) ?>
                    </span>

                    <div>
                        <h3 class="titular-menor">
                            <?= $e(is_string($paso['titulo'] ?? null) ? $paso['titulo'] : '') ?>
                        </h3>

                        <p class="cuerpo mt-2 text-[0.9375rem]">
                            <?= $e(is_string($paso['detalle'] ?? null) ? $paso['detalle'] : '') ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
