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
<section class="hero bg-tinta">
    <div class="hero-luz" aria-hidden="true"></div>
    <div class="hero-barrida" aria-hidden="true"></div>

    <?php
    /* La foto va de fondo, a sangre por la derecha. El degradado lo pone
       `.hero-foto::after`, no un contenedor intermedio: ver el CSS. Lleva
       además un desaturado parcial, que no es un filtro de moda: la foto
       es de un puerto al atardecer y sus naranjas compiten de frente con
       el oro, que en este sistema es el único color que significa algo. */
    ?>
    <div class="hero-foto">
        <?= Vista::imagen(
            basename($imagen),
            $alt,
            886,
            1176,
            '',
            prioritaria: true,
            sizes: '(min-width: 768px) 58vw, 100vw',
        ) ?>
    </div>

    <div class="relative z-10 mx-auto w-full max-w-[78rem] px-6 pt-28 md:px-20 md:pt-24">

        <div class="max-w-4xl">
            <p class="rotulo">Aduanero · Tributario · DIAN</p>

            <h1 class="titular mt-7">
                <?= $e($bloque->titulo) ?>
            </h1>

            <?php if ($bloque->subtitulo !== null): ?>
            <p class="entrada mt-7 max-w-xl">
                <?= $e($bloque->subtitulo) ?>
            </p>
            <?php endif; ?>
        </div>

        <?php /* La fila de acciones sale del `max-w-3xl` del titular. Ese
                 ancho está puesto para que la pregunta rompa en tres líneas
                 largas, que es como se lee mejor; aplicado también a los
                 botones obligaría a partirlos en dos filas sin que haga
                 falta. */ ?>
        <div class="mt-9 flex flex-col items-stretch gap-4 sm:flex-row sm:flex-wrap sm:items-center">
            <?= Vista::botonWhatsapp($waBase, $bloque->texto('cta_texto', 'Analizar mi caso')) ?>

            <?php if ($bloque->texto('cta_secundario') !== ''): ?>
            <a href="#proceso" class="boton-fantasma justify-center">
                <?= $e($bloque->texto('cta_secundario')) ?>
                <span aria-hidden="true">↓</span>
            </a>
            <?php endif; ?>
        </div>

        <?php if ($cifras !== []): ?>
        <?php /* La franja va MONTADA sobre la foto, no debajo del texto. Es
                 el único sitio de la página donde dos capas se solapan a
                 propósito, y ese solape es lo que da la sensación de que hay
                 planos a distintas distancias en vez de bloques apilados.
                 Aquí el vidrio sí tiene detrás lo que necesita: la foto. */ ?>
        <dl class="tira-cifras mt-12 grid sm:grid-cols-3 md:mt-14">
            <?php foreach ($cifras as $i => $dato): ?>
                <?php
                if (!is_array($dato)) {
                    continue;
                }
                $valor = is_string($dato['cifra'] ?? null) ? $dato['cifra'] : '';
                $nota = is_string($dato['nota'] ?? null) ? $dato['nota'] : '';
                ?>
                <div class="border-linea px-7 py-7 <?= $i > 0 ? 'border-t sm:border-t-0 sm:border-l' : '' ?>">
                    <dt class="contador"><?= $e($valor) ?></dt>
                    <dd class="contador-nota mt-3"><?= $e($nota) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
    </div>
</section>
