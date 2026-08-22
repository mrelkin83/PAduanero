<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Hero.
 *
 * La foto pasó a ser el fondo, a sangre por la derecha, y eso mueve una
 * decisión que la Etapa 1 había tomado al revés. Entonces la foto iba en una
 * columna al lado del texto con este argumento: quien llega aquí acaba de
 * recibir un acta de aprehensión y le importa su problema, no la cara de
 * nadie.
 *
 * Sigue siendo cierto, y por eso la foto es fondo y no sujeto: el degradado
 * la deja en penumbra sobre la mitad donde está el texto, y lo primero que
 * se lee sigue siendo la pregunta. Lo que se gana es que la persona a la que
 * se le va a pagar $400.000 tenga cara desde el primer segundo.
 *
 * Lo que NO cambia es cuál es la LCP: sigue siendo el titular, no la imagen.
 * Por eso el titular no espera a la foto —el degradado se pinta con CSS, no
 * con el archivo— y por eso la serif se precarga en `pagina.php`. Si algún
 * día la LCP pasara a ser la imagen, el criterio de cierre de < 2 s se cae.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var string $waBase
 * @var callable $e
 */

$imagen = $bloque->texto('imagen', '/img/pedro-hero.jpg');
$alt = $bloque->texto('alt', 'Pedro, abogado especialista en derecho aduanero y tributario');

/* Las cifras se siembran en `landing_bloques` (migración 0013) para que se
   editen desde el panel; el valor por defecto está aquí para que el bloque
   no se rompa en un entorno sin esa migración aplicada. Son datos
   verificables —años, especializaciones, cobertura— y ninguno es una
   promesa de resultado: eso es lo que les permite ir a este tamaño sin
   chocar con el marco de publicidad del abogado (regla 4). */
$cifras = $bloque->lista('cifras');
if ($cifras === []) {
    $cifras = [
        ['cifra' => '15+', 'nota' => 'años ante la DIAN'],
        ['cifra' => '02', 'nota' => 'especializaciones'],
        ['cifra' => 'CO', 'nota' => 'todo el territorio'],
    ];
}
?>
<section class="relative isolate overflow-hidden bg-tinta flex flex-col md:block md:min-h-screen">

    <?php
    /* Ahora en móvil la foto es un bloque superior fijo (no absoluto),
       mientras que en escritorio vuelve a ser absoluto al lado derecho.
       Con esto GARANTIZAMOS que en móvil jamás el texto toque la cara. */
    ?>
    <div class="hero-foto">
        <?= Vista::imagen(
            basename($imagen),
            $alt,
            886,
            1176,
            '',
            prioritaria: true,
            sizes: '(min-width: 768px) 54vw, 100vw',
        ) ?>
    </div>

    <?php /* El contenedor del texto. En móvil tiene margen negativo (-mt-12) para
             montarse sobre el gradiente oscuro final de la imagen, sin llegar al rostro. */ ?>
    <div class="relative z-10 w-full mx-auto max-w-[78rem] px-6 -mt-16 pb-16 md:mt-0 md:px-20 md:pt-40 md:pb-0">

        <div class="max-w-3xl">
            <p class="rotulo mb-4 md:mb-6">Aduanero · Tributario · DIAN</p>

            <h1 class="titular mt-2 md:mt-8">
                <?= $e($bloque->titulo) ?>
            </h1>

            <?php if ($bloque->subtitulo !== null): ?>
            <p class="entrada mt-6 md:mt-8 max-w-xl text-[1.15rem] leading-[1.6]">
                <?= $e($bloque->subtitulo) ?>
            </p>
            <?php endif; ?>
        </div>

        <div class="mt-8 md:mt-12 flex flex-col items-stretch gap-4 sm:flex-row sm:flex-wrap sm:items-center">
            <?= Vista::botonWhatsapp($waBase, $bloque->texto('cta_texto', 'Analizar mi caso')) ?>

            <?php if ($bloque->texto('cta_secundario') !== ''): ?>
            <a href="#proceso" class="boton-fantasma justify-center">
                <?= $e($bloque->texto('cta_secundario')) ?>
                <span aria-hidden="true" class="ml-2">↓</span>
            </a>
            <?php endif; ?>
        </div>

        <?php if ($cifras !== []): ?>
        <dl class="mt-16 md:mt-32 mb-4 md:mb-0 grid panel-os p-2 sm:grid-cols-3 max-w-3xl overflow-hidden divide-y sm:divide-y-0 sm:divide-x divide-white/5">
            <?php foreach ($cifras as $i => $dato): ?>
                <?php
                if (!is_array($dato)) { continue; }
                $valor = is_string($dato['cifra'] ?? null) ? $dato['cifra'] : '';
                $nota = is_string($dato['nota'] ?? null) ? $dato['nota'] : '';
                ?>
                <div class="p-6 md:p-8 hover:bg-white/5 transition-colors duration-300 rounded-xl">
                    <dt class="contador"><?= $e($valor) ?></dt>
                    <dd class="contador-nota mt-2 md:mt-3"><?= $e($nota) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
    </div>
</section>
