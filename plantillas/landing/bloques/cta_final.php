<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Cierre.
 *
 * Vuelve a tinta y con foto a sangre, como el hero: la página abre y cierra
 * con la misma superficie, y entre las dos el recorrido pasa por papel. Es
 * lo que le da forma de documento —cubierta, cuerpo, contracubierta— en vez
 * de lista de secciones.
 *
 * El texto va a la izquierda y no centrado. Centrado se lee como cartel; a
 * la izquierda, alineado con todo lo anterior, se lee como la última línea
 * de lo que se venía diciendo.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var string $waBase
 * @var callable $e
 */

$imagen = $bloque->texto('imagen');
?>
<section class="sobre-tinta relative isolate overflow-hidden bg-tinta py-20 text-papel md:py-28">

    <?php if ($imagen !== ''): ?>
    <div class="hero-foto">
        <?= Vista::imagen(
            basename($imagen),
            $bloque->texto('alt', 'Pedro, especialista en derecho aduanero y comercio exterior'),
            886,
            1176,
            '',
            sizes: '(min-width: 768px) 56vw, 100vw',
        ) ?>
    </div>
    <?php endif; ?>

    <div class="relative z-10 mx-auto max-w-6xl px-5 md:px-8">

        <p class="rotulo">El tiempo</p>

        <h2 class="titular-seccion mt-5 max-w-xl text-[2rem] md:text-[2.75rem]">
            <?= $e($bloque->titulo) ?>
        </h2>

        <hr class="filete mt-7">

        <?php if ($bloque->subtitulo !== null): ?>
        <p class="entrada mt-7 max-w-lg">
            <?= $e($bloque->subtitulo) ?>
        </p>
        <?php endif; ?>

        <div class="mt-9 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:gap-6">
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
            <a href="/perfil" class="boton-fantasma self-start sm:self-auto">
                <?= $e($secundario) ?>
                <span aria-hidden="true">→</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>
