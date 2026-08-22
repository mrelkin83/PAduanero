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
<section id="proceso" class="py-20 md:py-[8rem] relative isolate">
    <div class="mx-auto grid max-w-[84rem] gap-12 px-6 md:grid-cols-[1fr_1.15fr] md:gap-24 md:px-12 items-start">

        <div class="md:sticky md:top-32 revelar relative z-20">
            <p class="rotulo mb-4 md:mb-6">Metodología</p>

            <h2 class="titular-seccion">
                <?= $e($bloque->titulo) ?>
            </h2>
            
            <p class="mt-6 text-[1.1rem] md:text-xl text-acero max-w-md leading-relaxed font-light">
                Diseñado para dar respuestas precisas. Sin esperas. Una ruta directa a la resolución de su caso.
            </p>

            <?php if ($imagen !== ''): ?>
            <div class="tarjeta mt-10 md:mt-14 overflow-hidden relative group">
                <div class="absolute inset-0 bg-gradient-to-tr from-oro/20 to-transparent opacity-0 md:group-hover:opacity-100 transition-opacity duration-700 z-10 pointer-events-none"></div>
                <?= Vista::imagen(
                    basename($imagen),
                    $bloque->texto('alt', 'El abogado revisando una declaración de importación'),
                    890,
                    1198,
                    'w-full h-auto object-cover transform transition-transform duration-[3s] md:group-hover:scale-105',
                    sizes: '(min-width: 768px) 28rem, 100vw',
                ) ?>
            </div>
            <?php endif; ?>
        </div>

        <ol class="space-y-4 md:space-y-6 relative z-10">
            <?php foreach ($pasos as $i => $paso): ?>
                <?php
                if (!is_array($paso)) { continue; }
                $n = is_int($paso['n'] ?? null) ? $paso['n'] : 0;
                $delay = 100 + ($i * 100);
                ?>
                <li class="doble-bisel flex flex-row items-start gap-5 md:gap-8 p-6 md:p-10 relative overflow-hidden group revelar" style="--retardo: <?= $delay ?>ms">
                    
                    <div class="absolute inset-0 bg-gradient-to-r from-oro/5 to-transparent opacity-0 md:group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>
                    
                    <span class="text-4xl md:text-6xl font-medium tracking-tighter text-oro/30 md:group-hover:text-oro transition-colors duration-500 shrink-0 mt-1 md:mt-0" aria-hidden="true">
                        <?= $e(str_pad((string) $n, 2, '0', STR_PAD_LEFT)) ?>
                    </span>

                    <div class="relative z-10">
                        <h3 class="text-xl md:text-2xl font-medium text-white mb-2 md:mb-3 md:group-hover:text-oro-claro transition-colors duration-500 leading-tight">
                            <?= $e(is_string($paso['titulo'] ?? null) ? $paso['titulo'] : '') ?>
                        </h3>

                        <p class="text-acero text-[0.95rem] md:text-[1.05rem] leading-relaxed md:group-hover:text-papel transition-colors duration-500">
                            <?= $e(is_string($paso['detalle'] ?? null) ? $paso['detalle'] : '') ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
