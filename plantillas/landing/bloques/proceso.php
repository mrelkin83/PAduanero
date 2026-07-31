<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Proceso.
 *
 * Numerado, y aquí la numeración sí es información: el orden importa —
 * primero escribe, luego agenda, luego manda documentos, y solo entonces
 * recibe la hoja de ruta. Se compone como las casillas de un formulario,
 * con cero a la izquierda y filete superior.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var callable $e
 */

$pasos = $bloque->lista('pasos');
$imagen = $bloque->texto('imagen');
?>
<section id="proceso" class="sobre-tinta bg-tinta py-16 text-papel md:py-24">
    <div class="mx-auto max-w-6xl px-5 md:px-8">

        <p class="rotulo">Cómo funciona</p>

        <h2 class="titular-seccion mt-4 max-w-2xl text-[1.75rem] md:text-4xl">
            <?= $e($bloque->titulo) ?>
        </h2>

        <div class="mt-12 grid gap-10 md:grid-cols-[1fr_18rem] md:gap-16">

            <ol class="grid gap-x-10 gap-y-8 sm:grid-cols-2">
                <?php foreach ($pasos as $paso): ?>
                    <?php
                    if (!is_array($paso)) {
                        continue;
                    }
                    $n = is_int($paso['n'] ?? null) ? $paso['n'] : 0;
                    ?>
                    <li class="border-t border-papel/20 pt-4">
                        <span class="casilla-num"><?= $e(str_pad((string) $n, 2, '0', STR_PAD_LEFT)) ?></span>

                        <h3 class="mt-2 text-lg font-semibold leading-snug">
                            <?= $e(is_string($paso['titulo'] ?? null) ? $paso['titulo'] : '') ?>
                        </h3>

                        <p class="mt-1.5 text-[0.9375rem] text-tinta-suave">
                            <?= $e(is_string($paso['detalle'] ?? null) ? $paso['detalle'] : '') ?>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php if ($imagen !== ''): ?>
            <?= Vista::imagen(
                basename($imagen),
                $bloque->texto('alt', 'El abogado revisando una declaración de importación'),
                890,
                1198,
                'hidden md:block w-full h-auto rounded-sm',
                sizes: '18rem',
            ) ?>
            <?php endif; ?>
        </div>
    </div>
</section>
