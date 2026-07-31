<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Cierre.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var string $waBase
 * @var callable $e
 */
?>
<section class="border-t border-acero/15 bg-papel-puro py-16 md:py-24">
    <div class="mx-auto max-w-3xl px-5 text-center md:px-8">

        <h2 class="titular-seccion text-[1.75rem] md:text-4xl">
            <?= $e($bloque->titulo) ?>
        </h2>

        <hr class="filete mx-auto mt-6">

        <?php if ($bloque->subtitulo !== null): ?>
        <p class="mx-auto mt-6 max-w-xl text-[1.0625rem] text-acero">
            <?= $e($bloque->subtitulo) ?>
        </p>
        <?php endif; ?>

        <div class="mt-9">
            <?= Vista::botonWhatsapp($waBase, $bloque->texto('cta_texto', 'Escribir por WhatsApp')) ?>
        </div>
    </div>
</section>
