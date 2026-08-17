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
<section id="diagnostico" class="bg-tinta py-20 md:py-[5rem]">
    <div class="mx-auto max-w-[78rem] px-6 md:px-20">
        <div class="grid gap-12 lg:grid-cols-12 lg:items-center lg:gap-20">

            <div class="lg:col-span-7">
                <p class="rotulo revelar">Diagnóstico gratuito</p>

                <h2 class="titular-seccion revelar mt-8" style="--retardo:80ms">
                    <?= $e($bloque->titulo) ?>
                </h2>

                <?php if ($bloque->subtitulo !== null): ?>
                <p class="entrada revelar mt-7 max-w-[44ch]" style="--retardo:140ms">
                    <?= $e($bloque->subtitulo) ?>
                </p>
                <?php endif; ?>

                <?php if ($promesas !== []): ?>
                <ul class="revelar mt-12 max-w-lg border-b border-linea" style="--retardo:200ms">
                    <?php foreach ($promesas as $paso): ?>
                    <li class="indice-fila"><?= $e(is_string($paso) ? $paso : '') ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="revelar lg:col-span-5" style="--retardo:260ms">
                <a href="/perfil" data-evento="perfil_inicio" class="tarjeta-diagnostico group block">
                    <span class="flex items-center justify-between">
                        <span class="indice-num">01 &rarr; 06</span>
                        <span class="rotulo text-acero">2 min</span>
                    </span>

                    <span class="titular-menor mt-10 block text-[1.5rem]">
                        <?= $e($bloque->texto('cta_texto', 'Diagnosticar mi caso')) ?>
                    </span>

                    <span class="cuerpo mt-3 block text-[0.9375rem]">
                        <?= $e($bloque->texto('cta_detalle', 'Menos de dos minutos. Sin dejar datos.')) ?>
                    </span>

                    <span class="rotulo mt-12 flex items-center gap-3">
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