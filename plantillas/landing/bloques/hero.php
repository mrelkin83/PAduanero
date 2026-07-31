<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Hero.
 *
 * Decisión deliberada: NO abre con la foto del abogado. Quien llega aquí
 * acaba de recibir un acta de aprehensión y le importa su problema, no la
 * cara de nadie. Abre con la pregunta y el botón; la foto entra justo
 * después. De paso, que la LCP sea texto y no una imagen es lo que permite
 * cumplir el LCP < 2 s del criterio de cierre.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var string $waBase
 * @var callable $e
 */

$imagen = $bloque->texto('imagen', '/img/pedro-hero.jpg');
$alt = $bloque->texto('alt', 'Pedro, abogado especialista en derecho aduanero y tributario');
?>
<section class="sobre-tinta bg-tinta text-papel">
    <div class="mx-auto grid max-w-6xl gap-10 px-5 pt-14 pb-0 md:grid-cols-[1fr_auto] md:items-end md:gap-12 md:px-8 md:pt-24">

        <div class="md:pb-24">
            <p class="rotulo">Aduanero · Tributario · DIAN</p>

            <h1 class="titular mt-5 text-[2.125rem] sm:text-5xl md:text-[3.5rem]">
                <?= $e($bloque->titulo) ?>
            </h1>

            <hr class="filete mt-7">

            <?php if ($bloque->subtitulo !== null): ?>
            <p class="mt-6 max-w-xl text-[1.0625rem] text-tinta-suave md:text-lg">
                <?= $e($bloque->subtitulo) ?>
            </p>
            <?php endif; ?>

            <div class="mt-9 flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:gap-5">
                <?= Vista::botonWhatsapp($waBase, $bloque->texto('cta_texto', 'Escribir por WhatsApp')) ?>

                <?php if ($bloque->texto('cta_secundario') !== ''): ?>
                <a href="#proceso" class="boton-fantasma self-center sm:self-auto">
                    <?= $e($bloque->texto('cta_secundario')) ?>
                    <span aria-hidden="true">↓</span>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php
        /* El cielo de la foto es del mismo azul de tinta que el fondo, así
           que la imagen entra sin costura por abajo: no hace falta ni marco
           ni degradado. */
        echo Vista::imagen(
            basename($imagen),
            $alt,
            886,
            1176,
            'w-full max-w-md mx-auto md:max-w-none md:w-[26rem] h-auto select-none',
            prioritaria: true,
            sizes: '(min-width: 768px) 26rem, 100vw',
        );
        ?>
    </div>
</section>
