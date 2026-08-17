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

        <div class="mt-9 flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-center sm:gap-5">
            <?= Vista::botonWhatsapp($waBase, $bloque->texto('cta_texto', 'Escribir por WhatsApp')) ?>

            <?php
            /* Salida al diagnóstico para quien llegó al final y todavía no
               sabe cómo se llama lo que le pasa: a $400.000 la hora, esa
               persona no escribe todavía, pero seis preguntas sí las
               contesta. El texto lleva valor por defecto porque el bloque
               `cta_final` no trae `cta_secundario` sembrado; el panel lo
               puede sobreescribir sin tocar la plantilla. */
            $secundario = $bloque->texto('cta_secundario', 'O diagnostique su caso primero');
            ?>
            <?php if ($secundario !== ''): ?>
            <a href="/perfil" class="boton-fantasma self-center sm:self-auto">
                <?= $e($secundario) ?>
                <span aria-hidden="true">→</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>
