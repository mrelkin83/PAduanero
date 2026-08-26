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
<section class="py-20 md:py-[8rem] relative isolate">
    <div class="mx-auto max-w-[84rem] px-6 md:px-12">
        <div class="text-center mb-16 md:mb-20 max-w-3xl mx-auto revelar">
            <p class="rotulo rotulo-capsula mx-auto mb-4 md:mb-6">
                <span class="punto" aria-hidden="true"></span>
                Quién responde
            </p>
            <h2 class="titular-seccion">
                <?= $e($bloque->titulo) ?>
            </h2>
        </div>

        <?php /* Las filas parten de 24rem pero CRECEN con el contenido
                 (minmax, no altura fija): el detalle de una credencial lo
                 escribe Pedro desde el panel y puede ser un párrafo entero.
                 Con altura fija + overflow-hidden, el texto que no cupiera
                 desaparecería sin dar ningún error — ya pasó. */ ?>
        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 md:gap-6 md:auto-rows-[minmax(24rem,auto)]">

            <?php /* El retrato va como el bloque grande (Hero Bento). 
                     En móvil tiene altura fija para no comerse la pantalla. */ ?>
            <div class="tarjeta overflow-hidden md:col-span-5 md:row-span-2 relative group revelar h-[450px] md:h-auto" style="--retardo: 100ms">
                <?= Vista::imagen(
                    basename($imagen),
                    'Retrato de Pedro, abogado titular del despacho',
                    892,
                    1196,
                    'absolute inset-0 w-full h-full object-cover object-top transition-transform duration-[2s] md:group-hover:scale-105',
                    sizes: '(min-width: 768px) 34rem, 100vw',
                ) ?>
                <div class="absolute inset-0 bg-gradient-to-t from-tinta via-tinta/40 to-transparent"></div>
                <div class="absolute bottom-0 left-0 p-6 md:p-8 w-full flex flex-col items-start">
                    <p class="text-2xl md:text-3xl text-white font-medium mb-1 md:mb-2 tracking-tight">Pedro</p>
                    <p class="text-acero text-xs md:text-sm uppercase tracking-widest font-mono">Abogado Titular</p>
                </div>
            </div>

            <?php /* Los items se distribuyen asimétricamente en desktop, y apilan fluidos en móvil.
                     El retrato ocupa 5 columnas en 2 filas; a los items les quedan 7 columnas
                     por fila. El reparto depende de cuántos items hay para que siempre llenen
                     esas 7 columnas exactas: con menos tarjetas de las que el patrón de 3 espera
                     ("07+4+3") quedaba una franja vacía a la derecha de la última fila. */ ?>
            <?php
                $totalItems = count($items);
                $bentoClasses = match (true) {
                    $totalItems <= 1 => ['md:col-span-7 md:row-span-2'],
                    $totalItems === 2 => ['md:col-span-7 md:row-span-1', 'md:col-span-7 md:row-span-1'],
                    default => [
                        'md:col-span-7 md:row-span-1',
                        'md:col-span-4 md:row-span-1',
                        'md:col-span-3 md:row-span-1',
                    ],
                };
            ?>
            <?php foreach ($items as $i => $item): ?>
                <?php
                if (!is_array($item)) { continue; }
                $titulo = is_string($item['titulo'] ?? null) ? $item['titulo'] : '';
                $detalle = is_string($item['detalle'] ?? null) ? $item['detalle'] : '';
                $bentoClass = $bentoClasses[$i % count($bentoClasses)];
                $delay = 200 + ($i * 100);
                ?>
                <div class="tarjeta flex flex-col p-6 md:p-10 relative overflow-hidden group revelar <?= $bentoClass ?>" style="--retardo: <?= $delay ?>ms">
                    <div class="absolute -right-12 -top-12 w-48 h-48 bg-oro/5 rounded-full blur-3xl md:group-hover:bg-oro/15 transition-colors duration-700 pointer-events-none"></div>
                    <span class="cifra-oro opacity-30 text-3xl md:text-4xl mb-6 md:mb-auto" aria-hidden="true">
                        <?= $e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?>
                    </span>
                    <div class="mt-auto relative z-10">
                        <p class="text-xl md:text-2xl font-medium tracking-tight text-white md:group-hover:text-oro-claro transition-colors duration-500 mb-2 md:mb-3 leading-tight"><?= $e($titulo) ?></p>
                        <?php if ($detalle !== ''): ?>
                        <?php /* Sin line-clamp: el texto se muestra entero.
                                 Recortarlo a tres líneas escondía el grueso
                                 de la credencial que Pedro escribió. */ ?>
                        <p class="text-acero text-[0.95rem] md:text-[1rem] leading-relaxed md:group-hover:text-papel transition-colors duration-500"><?= $e($detalle) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
