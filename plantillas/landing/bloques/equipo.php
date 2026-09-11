<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * El equipo.
 *
 * Va justo detrás de `credenciales` y no en otro sitio: esa sección termina
 * de presentar a Pedro, y la pregunta que sigue de forma natural —«¿lo hace
 * todo él solo?»— es la que contesta esta. Antes de `credenciales` sería
 * presentar al apoyo antes que al titular; después de `proceso`, donde ya
 * está el precio, llegaría tarde.
 *
 * **Erika no es abogada, y esta plantilla no puede decir que lo es.** Es
 * Profesional en Negocios Internacionales con especialización en Derecho
 * Aduanero: un posgrado abierto a no abogados que no habilita el ejercicio
 * del derecho. Por eso el rol se pinta del campo `rol` del bloque y no hay
 * ni un rótulo escrito a mano aquí que pueda derivar hacia «abogada» con
 * una edición distraída; y por eso el bloque cierra con la `nota`, que dice
 * de quién es el análisis jurídico. Si alguien borra la nota desde el panel,
 * el párrafo desaparece — pero el rol nunca cambia solo.
 *
 * El retrato va a la DERECHA en pantalla ancha. En `credenciales` el de
 * Pedro va a la izquierda, y dos secciones seguidas con la foto en el mismo
 * borde se leen como la misma sección repetida. En móvil, donde no hay
 * izquierda ni derecha, va arriba.
 *
 * Si no hay integrantes el bloque no se pinta. No es defensa contra un
 * error: el menú de `pagina.php` se deriva de lo que realmente se emitió, y
 * una sección vacía con su ancla dentro le daría al menú un enlace que no
 * lleva a ninguna parte.
 *
 * Sin botón. `pagina.php` deja `$waBase` en el ámbito de todos los bloques,
 * pero esta sección no lo usa: presenta a quien trabaja el caso y la salida
 * está dos secciones más abajo. Un botón de más aquí no añade una
 * conversión: reparte la que ya había.
 *
 * @var \App\Modelos\Bloque $bloque
 * @var callable $e
 */

$integrantes = array_values(array_filter($bloque->lista('integrantes'), 'is_array'));

if ($integrantes === []) {
    return;
}

$nota = $bloque->texto('nota');
?>
<section id="equipo" class="py-20 md:py-[8rem] relative isolate">
    <div class="mx-auto max-w-[84rem] px-6 md:px-12">

        <div class="text-center mb-16 md:mb-20 max-w-3xl mx-auto revelar">
            <p class="rotulo rotulo-capsula mx-auto mb-4 md:mb-6">
                <span class="punto" aria-hidden="true"></span>
                Quién trabaja el expediente
            </p>
            <h2 class="titular-seccion">
                <?= $e($bloque->titulo) ?>
            </h2>
            <?php if ($bloque->subtitulo !== null && $bloque->subtitulo !== ''): ?>
            <p class="mt-6 md:mt-8 text-[1.1rem] md:text-xl text-acero leading-relaxed font-light">
                <?= $e($bloque->subtitulo) ?>
            </p>
            <?php endif; ?>
        </div>

        <?php foreach ($integrantes as $i => $integrante): ?>
        <?php
        $nombre = is_string($integrante['nombre'] ?? null) ? $integrante['nombre'] : '';
        $rol = is_string($integrante['rol'] ?? null) ? $integrante['rol'] : '';
        $imagen = is_string($integrante['imagen'] ?? null) ? $integrante['imagen'] : '';
        $alt = is_string($integrante['alt'] ?? null) ? $integrante['alt'] : $nombre;
        $resumen = is_string($integrante['resumen'] ?? null) ? $integrante['resumen'] : '';
        $titulos = array_values(array_filter(
            is_array($integrante['titulos'] ?? null) ? $integrante['titulos'] : [],
            'is_string',
        ));
        $areas = array_values(array_filter(
            is_array($integrante['areas'] ?? null) ? $integrante['areas'] : [],
            'is_string',
        ));

        if ($nombre === '') {
            continue;
        }
        ?>
        <div class="grid grid-cols-1 md:grid-cols-12 gap-6 md:gap-10 items-center <?= $i > 0 ? 'mt-12 md:mt-20' : '' ?>">

            <?php /* El texto va primero en el HTML —es lo que importa y lo que
                     leen los buscadores— pero en móvil se le adelanta el
                     retrato con `order-first`. Sin eso la foto quedaba
                     detrás de tres párrafos y dos listas, a pantalla y media
                     del titular, y una sección que se llama «un abogado y una
                     consultora» tiene que enseñar la cara antes de eso.
                     En pantalla ancha vuelve a la derecha. */ ?>
            <div class="md:col-span-7 md:order-1 revelar" style="--retardo: 100ms">
                <p class="text-3xl md:text-[2.5rem] font-medium tracking-tight text-white leading-tight">
                    <?= $e($nombre) ?>
                </p>

                <?php /* El rol, en mono y en oro: es el dato que no se puede
                         confundir. Sale del bloque, nunca de una constante de
                         esta plantilla. */ ?>
                <p class="mt-3 font-mono text-xs md:text-sm uppercase tracking-widest text-oro">
                    <?= $e($rol) ?>
                </p>

                <?php if ($resumen !== ''): ?>
                <p class="entrada mt-6 max-w-[54ch]"><?= $e($resumen) ?></p>
                <?php endif; ?>

                <?php if ($titulos !== []): ?>
                <ul class="mt-8 space-y-3">
                    <?php foreach ($titulos as $j => $titulo): ?>
                    <li class="flex items-baseline gap-4">
                        <span class="cifra-oro opacity-30 text-sm shrink-0" aria-hidden="true">
                            <?= $e(str_pad((string) ($j + 1), 2, '0', STR_PAD_LEFT)) ?>
                        </span>
                        <span class="text-[1rem] md:text-lg text-papel leading-snug"><?= $e($titulo) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if ($areas !== []): ?>
                <div class="mt-10 pt-8 border-t border-linea/50">
                    <p class="rotulo text-acero mb-5">Lo que revisa</p>
                    <ul class="indice-columnas">
                        <?php foreach ($areas as $area): ?>
                        <li class="indice-fila text-[0.95rem] md:text-[1rem] py-3 border-t border-linea/30 first:border-0">
                            <?= $e($area) ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($imagen !== ''): ?>
            <div class="tarjeta overflow-hidden order-first md:order-2 md:col-span-5 relative group revelar h-[420px] md:h-[34rem]" style="--retardo: 200ms">
                <?= Vista::imagen(
                    basename($imagen),
                    $alt,
                    892,
                    1196,
                    'absolute inset-0 w-full h-full object-cover object-top transition-transform duration-[2s] md:group-hover:scale-105',
                    sizes: '(min-width: 768px) 30rem, 100vw',
                ) ?>
                <?php /* Solo el degradado, sin rótulo encima. En
                         `credenciales` el nombre va sobre la foto porque es
                         el único sitio donde aparece; aquí el nombre y el rol
                         ya están al lado en grande, y repetirlos sobre la
                         imagen los convierte en decoración. El degradado sí
                         se queda: asienta el retrato sobre el negro en vez de
                         dejarlo flotando como un recorte. */ ?>
                <div class="absolute inset-0 bg-gradient-to-t from-tinta via-tinta/30 to-transparent"></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($nota !== ''): ?>
        <?php /* El reparto de funciones, dicho en la página y no solo en el
                 prompt del bot. Es lo que separa legítimamente los dos
                 trabajos, así que va visible y no en el pie. */ ?>
        <p class="mt-12 md:mt-16 max-w-[60ch] mx-auto text-center text-[0.95rem] md:text-[1rem] leading-relaxed text-acero revelar" style="--retardo: 300ms">
            <?= $e($nota) ?>
        </p>
        <?php endif; ?>
    </div>
</section>
