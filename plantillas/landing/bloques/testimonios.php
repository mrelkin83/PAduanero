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

    // `autorizado` estricto: solo el booleano verdadero abre la puerta. Un
    // `"si"`, un `1` o una cadena vacía no cuentan — si el permiso llegó
    // como texto suelto desde algún formulario, quien lo cargó tiene que
    // volver y marcarlo bien, que es exactamente el momento de detenerse a
    // comprobar si el permiso existe de verdad.
    if (($item['autorizado'] ?? null) !== true) {
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

    $items[] = $item + ['texto' => $texto, 'autor' => $autor];
}

if ($items === []) {
    return;
}
?>
<section id="testimonios" class="bg-tinta py-20 md:py-[5rem]">
    <div class="mx-auto max-w-[78rem] px-6 md:px-20">

        <p class="rotulo">Quienes ya pasaron por esto</p>

        <h2 class="titular-seccion mt-8 max-w-3xl">
            <?= $e($bloque->titulo) ?>
        </h2>

        <?php if ($bloque->subtitulo !== null): ?>
        <p class="entrada mt-7 max-w-2xl"><?= $e($bloque->subtitulo) ?></p>
        <?php endif; ?>

        <div class="mt-16 grid gap-6 md:mt-20 md:grid-cols-2">
            <?php foreach ($items as $item): ?>
                <?php
                $cargo = trim((string) ($item['cargo'] ?? ''));
                $empresa = trim((string) ($item['empresa'] ?? ''));
                $pie = implode(' · ', array_filter([$cargo, $empresa]));
                ?>
                <figure class="tarjeta flex flex-col p-8 md:p-10">
                    <blockquote class="entrada text-[1.0625rem]">
                        <?= $e($item['texto']) ?>
                    </blockquote>

                    <figcaption class="mt-8 border-t border-linea pt-6">
                        <p class="titular-menor text-[1.0625rem]"><?= $e($item['autor']) ?></p>

                        <?php if ($pie !== ''): ?>
                        <p class="rotulo mt-2 text-acero"><?= $e($pie) ?></p>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
