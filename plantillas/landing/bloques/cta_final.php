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
<section class="py-20 md:py-[5rem]">
    <div class="mx-auto max-w-[78rem] px-6 md:px-20">

    <?php /* El cierre es una pieza, no una franja: una sola tarjeta grande y
             centrada, aislada por el vacío de arriba y de abajo. Es lo que la
             especificación llama dejar la llamada a la acción «en
             aislamiento», y aquí funciona porque es la última cosa que se lee.

             La foto vive DENTRO de la tarjeta y muy velada. En la maqueta esta
             pieza no lleva imagen, pero el bloque trae una sembrada desde la
             Etapa 1 y tirarla sería perder contenido; velada hace de textura,
             que es justo lo que la maqueta pinta ahí. */ ?>
    <div class="tarjeta relative isolate overflow-hidden px-6 py-20 text-center md:px-20 md:py-28">

        <?php if ($imagen !== ''): ?>
        <div class="hero-foto">
            <?= Vista::imagen(
                basename($imagen),
                $bloque->texto('alt', 'Pedro, especialista en derecho aduanero y comercio exterior'),
                886,
                1176,
                '',
                sizes: '100vw',
            ) ?>
        </div>
        <?php endif; ?>

        <div class="relative z-10">

        <p class="rotulo rotulo-capsula">
            <span class="punto" aria-hidden="true"></span>
            El tiempo
        </p>

        <h2 class="titular-seccion mx-auto mt-8 max-w-2xl">
            <?= $e($bloque->titulo) ?>
        </h2>

        <?php if ($bloque->subtitulo !== null): ?>
        <p class="entrada mx-auto mt-7 max-w-xl">
            <?= $e($bloque->subtitulo) ?>
        </p>
        <?php endif; ?>

        <div class="mt-12 flex flex-col items-stretch gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-center">
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
            <a href="/perfil" class="boton-fantasma justify-center">
                <?= $e($secundario) ?>
                <span aria-hidden="true">→</span>
            </a>
            <?php endif; ?>
        </div>

        </div>
    </div>
    </div>
</section>
