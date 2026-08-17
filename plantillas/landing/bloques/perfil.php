<?php

declare(strict_types=1);

/**
 * Invitación al diagnóstico aduanero.
 *
 * Módulo dinámico inspirado en perfil de importador / triage interactivo.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var callable $e
 */

$promesas = $bloque->lista('promesas');
if ($promesas === []) {
    $promesas = [
        'Sin nombre, sin correo, sin teléfono para iniciar',
        'Nada se guarda en base de datos: queda en su pantalla',
        'Gratis y con el lenguaje técnico exacto de la DIAN',
        'Al final usted decide si envía el resumen al abogado',
    ];
}
?>
<section id="diagnostico" class="sobre-tinta bg-tinta py-24 text-papel md:py-36">
    <div class="mx-auto max-w-6xl px-6 md:px-10">
        <div class="grid gap-14 lg:grid-cols-12 lg:items-end lg:gap-16">

            <div class="lg:col-span-7">
                <p class="rotulo revelar">Diagnóstico gratuito</p>

                <h2 class="titular-seccion revelar mt-6" style="--retardo:80ms">
                    <?= $e($bloque->titulo) ?>
                </h2>

                <?php if ($bloque->subtitulo !== null): ?>
                <p class="entrada revelar mt-7 max-w-[42ch]" style="--retardo:140ms">
                    <?= $e($bloque->subtitulo) ?>
                </p>
                <?php endif; ?>

                <?php if ($promesas !== []): ?>
                <ul class="revelar mt-10 max-w-lg border-b border-papel/15" style="--retardo:200ms">
                    <?php foreach ($promesas as $paso): ?>
                    <li class="indice-fila"><?= $e(is_string($paso) ? $paso : '') ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <?php /* Tarjeta con micro-interacciones "Apple-esque" */ ?>
            <div class="revelar lg:col-span-5" style="--retardo:260ms">
                <a href="/perfil" data-evento="perfil_inicio" class="tarjeta-diagnostico group">
                    <span class="flex items-center justify-between">
                        <span class="casilla-num">01 &rarr; 06</span>
                        <span class="font-mono text-eyebrow uppercase tracking-[0.16em] text-tinta-suave">
                            2 min
                        </span>
                    </span>

                    <span class="titular-menor mt-8 block">
                        <?= $e($bloque->texto('cta_texto', 'Diagnosticar mi caso')) ?>
                    </span>

                    <span class="mt-3 block text-[0.9375rem] text-tinta-suave">
                        <?= $e($bloque->texto('cta_detalle', 'Menos de dos minutos. Sin dejar datos.')) ?>
                    </span>

                    <span class="mt-9 flex items-center gap-3 font-mono text-eyebrow uppercase tracking-[0.16em] text-ambar">
                        Empezar
                        <span aria-hidden="true"
                              class="transition-transform duration-[400ms] ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:translate-x-1.5">
                            &rarr;
                        </span>
                    </span>
                </a>
            </div>

        </div>
    </div>
</section>