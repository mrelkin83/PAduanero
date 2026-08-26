<?php

declare(strict_types=1);

/**
 * Testimonios.
 *
 * Va DESPUÉS de `confianza` y no antes, porque hace un trabajo distinto y más
 * débil. La sección de arriba responde «¿esto existe?» con datos que el
 * visitante comprueba solo; esta responde «¿cómo trata a la gente?», y para
 * eso hay que creerle a alguien. En ese orden funciona; al revés, no: un
 * testimonio leído antes de cualquier prueba de existencia se lee como el
 * decorado de la estafa que el visitante teme.
 *
 * ---------------------------------------------------------------------
 * LA PUERTA DEL `autorizado`, Y POR QUÉ ESTÁ AQUÍ Y NO EN UN COMENTARIO
 * ---------------------------------------------------------------------
 * Un elemento sin `autorizado` en verdadero **no se pinta**, aunque tenga
 * texto y autor. Tampoco se pinta uno sin autor identificado.
 *
 * La comprobación vive en la plantilla, que es el último sitio por el que
 * pasa el dato antes de ser público, y no en quien carga el contenido. Es la
 * misma disciplina que el resto del proyecto aplica donde una regla se puede
 * hacer imposible de violar en vez de recordable: aquí lo que está en juego
 * no es un fallo de programación, son dos cosas que no se pueden deshacer una
 * vez publicadas.
 *
 * **Secreto profesional.** Un testimonio identificado revela que esa empresa
 * tuvo mercancía aprehendida o una sanción de la DIAN. Es información
 * comercial sensible del cliente, no del despacho. El permiso tiene que ser
 * escrito y sobre el texto exacto que va a salir — no sobre la idea de
 * aparecer, porque nadie autoriza en abstracto una frase que todavía no ha
 * leído.
 *
 * **Ley 1123 de 2007.** Regula la publicidad del abogado. Un testimonio que
 * insinúe resultados garantizados —«me devolvieron todo», «ganamos el
 * caso»— entra en el mismo terreno que las reglas 1 a 3 le prohíben al resto
 * de la página. Lo que sí sirve, y además es lo que de verdad tranquiliza a
 * quien está asustado, describe el TRATO: que contestó el mismo día, que
 * explicó en español, que dijo desde el principio qué no se podía hacer.
 *
 * Y el anonimato no es la salida: un testimonio sin nombre es
 * indistinguible de uno inventado, y quien teme una estafa lo sabe. Si no se
 * puede publicar con nombre y con permiso, no se publica. La sección
 * desaparece entera y la página no pierde nada — el trabajo de dar confianza
 * ya lo hizo `confianza`, que es comprobable.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var callable $e
 */

$items = [];

foreach ($bloque->lista('items') as $item) {
    if (!is_array($item)) {
        continue;
    }

    $pendiente = ($item['pendiente'] ?? null) === true;

    // `autorizado` estricto: solo el booleano verdadero abre la puerta. Un
    // `"si"`, un `1` o una cadena vacía no cuentan — si el permiso llegó
    // como texto suelto desde algún formulario, quien lo cargó tiene que
    // volver y marcarlo bien, que es exactamente el momento de detenerse a
    // comprobar si el permiso existe de verdad.
    //
    // Un ejemplo de relleno se salta esa puerta, y puede hacerlo sin abrirla
    // para nadie más: se pinta con la marca «ejemplo» encima y el nombre en
    // gris, así que no dice nada sobre ningún cliente real. Lo que la puerta
    // protege —el secreto profesional de alguien que existe— no está en
    // juego mientras no haya alguien que exista detrás.
    if (!$pendiente && ($item['autorizado'] ?? null) !== true) {
        continue;
    }

    $texto = trim((string) ($item['texto'] ?? ''));
    $autor = trim((string) ($item['autor'] ?? ''));

    // Sin autor no se pinta. Un testimonio anónimo no distingue a este
    // despacho de uno inventado, que es justo el problema que vino a
    // resolver.
    if ($texto === '' || $autor === '') {
        continue;
    }

    $items[] = $item + ['texto' => $texto, 'autor' => $autor, 'pendiente' => $pendiente];
}

if ($items === []) {
    return;
}
?>
<section id="testimonios" class="py-20 md:py-[8rem] relative isolate">
    <div class="absolute inset-0 bg-gradient-to-b from-transparent via-white/2 to-transparent pointer-events-none"></div>
    <div class="mx-auto max-w-[84rem] px-6 md:px-12 relative z-10">

        <div class="revelar text-center">
            <p class="rotulo rotulo-capsula mb-4 md:mb-6 mx-auto">
                <span class="punto" aria-hidden="true"></span>
                Quienes ya pasaron por esto
            </p>

            <h2 class="titular-seccion mt-6 md:mt-8 max-w-4xl mx-auto">
                <?= $e($bloque->titulo) ?>
            </h2>

            <?php if ($bloque->subtitulo !== null): ?>
            <p class="mt-6 md:mt-8 text-[1.1rem] md:text-xl text-acero max-w-2xl mx-auto leading-relaxed font-light"><?= $e($bloque->subtitulo) ?></p>
            <?php endif; ?>
        </div>

        <div class="mt-16 md:mt-24 grid gap-6 md:gap-8 md:grid-cols-2">
            <?php foreach ($items as $i => $item): ?>
                <?php
                $cargo = trim((string) ($item['cargo'] ?? ''));
                $empresa = trim((string) ($item['empresa'] ?? ''));
                $pie = implode(' · ', array_filter([$cargo, $empresa]));
                $logo = trim((string) ($item['logo'] ?? ''));
                $logoUrl = trim((string) ($item['url'] ?? ''));
                $delay = 100 + ($i * 100);
                ?>
                <?php $pendiente = $item['pendiente'] === true; ?>
                <figure class="tarjeta flex flex-col p-6 md:p-12 relative overflow-hidden group revelar" style="--retardo: <?= $delay ?>ms">
                    <div class="absolute inset-0 bg-gradient-to-br from-white/5 to-transparent opacity-0 md:group-hover:opacity-100 transition-opacity duration-700 pointer-events-none"></div>
                    
                    <?php if ($pendiente): ?>
                    <p class="mb-6 md:mb-8 relative z-10"><span class="marca-pendiente ml-0">Ejemplo · sin publicar</span></p>
                    <?php endif; ?>

                    <span class="text-6xl md:text-7xl font-serif text-oro/10 absolute top-4 left-4 md:top-6 md:left-6 leading-none md:group-hover:text-oro/20 transition-colors duration-500 pointer-events-none">"</span>

                    <blockquote class="relative z-10 text-[1.05rem] md:text-[1.2rem] leading-relaxed text-papel mt-4 <?= $pendiente ? 'pendiente' : '' ?>">
                        <?= $e($item['texto']) ?>
                    </blockquote>

                    <figcaption class="mt-8 md:mt-12 relative z-10 border-t border-linea/30 pt-6 md:pt-8 flex items-end justify-between gap-6">
                        <div class="flex flex-col min-w-0">
                            <p class="text-[1.1rem] md:text-xl font-medium tracking-tight text-white md:group-hover:text-oro-claro transition-colors duration-500 <?= $pendiente ? 'pendiente' : '' ?>">
                                <?= $e($item['autor']) ?>
                            </p>

                            <?php if ($pie !== ''): ?>
                            <p class="rotulo text-acero mt-3 md:mt-4"><?= $e($pie) ?></p>
                            <?php endif; ?>
                        </div>

                        <?php if ($logo !== ''): ?>
                        <?php
                        /* El logo enlaza al sitio o red de la empresa, nunca en un
                           ejemplo (0015) ni sin url: un botón que no lleva a
                           ninguna parte es peor que no ponerlo. */
                        $etiquetaLogo = $empresa !== '' ? 'Sitio de ' . $empresa : 'Sitio de la empresa';
                        ?>
                        <?php if (!$pendiente && $logoUrl !== ''): ?>
                        <a href="<?= $e($logoUrl) ?>" target="_blank" rel="noopener noreferrer"
                           class="shrink-0 opacity-80 md:hover:opacity-100 transition-opacity duration-300" aria-label="<?= $e($etiquetaLogo) ?>">
                            <img src="/img/<?= $e(basename($logo)) ?>" alt="<?= $e($empresa) ?>" width="120" height="40"
                                 class="h-9 md:h-10 w-auto max-w-[8rem] object-contain" loading="lazy" decoding="async">
                        </a>
                        <?php else: ?>
                        <img src="/img/<?= $e(basename($logo)) ?>" alt="<?= $e($empresa) ?>" width="120" height="40"
                             class="shrink-0 h-9 md:h-10 w-auto max-w-[8rem] object-contain opacity-80" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
