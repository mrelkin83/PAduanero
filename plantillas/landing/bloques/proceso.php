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
<section id="proceso" class="sobre-tinta bg-tinta py-20 text-papel md:py-28">
    <div class="mx-auto grid max-w-6xl gap-12 px-5 md:grid-cols-[20rem_1fr] md:gap-20 md:px-8">

        <div>
            <p class="rotulo">Cómo funciona</p>

            <h2 class="titular-seccion mt-5 text-[2rem] md:text-[2.75rem]">
                <?= $e($bloque->titulo) ?>
            </h2>

            <?php if ($imagen !== ''): ?>
            <div class="mt-10 hidden md:block">
                <?= Vista::imagen(
                    basename($imagen),
                    $bloque->texto('alt', 'El abogado revisando una declaración de importación'),
                    890,
                    1198,
                    'w-full h-auto rounded-sm',
                    sizes: '20rem',
                ) ?>
            </div>
            <?php endif; ?>
        </div>

        <ol class="md:pt-2">
            <?php foreach ($pasos as $paso): ?>
                <?php
                if (!is_array($paso)) {
                    continue;
                }
                $n = is_int($paso['n'] ?? null) ? $paso['n'] : 0;
                ?>
                <li class="paso-fila">
                    <span class="paso-cifra" aria-hidden="true">
                        <?= $e(str_pad((string) $n, 2, '0', STR_PAD_LEFT)) ?>
                    </span>

                    <div>
                        <h3 class="titular-menor text-[1.375rem] md:text-2xl">
                            <?= $e(is_string($paso['titulo'] ?? null) ? $paso['titulo'] : '') ?>
                        </h3>

                        <p class="mt-2 text-[0.9375rem] leading-relaxed text-tinta-suave">
                            <?= $e(is_string($paso['detalle'] ?? null) ? $paso['detalle'] : '') ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
