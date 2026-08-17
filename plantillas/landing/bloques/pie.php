<?php

declare(strict_types=1);

/**
 * Pie.
 *
 * El aviso de tratamiento de datos todavía no existe: `texto_aviso_habeas_data`
 * y la política están pendientes de redacción de Pedro (CLAUDE.md §11). El
 * enlace se emitirá cuando la haya; poner ahora un «Política de privacidad»
 * que lleve a una página vacía sería peor que no ponerlo.
 *
 * Lo requieren las dos páginas públicas —`landing/pagina.php` y
 * `perfil/pagina.php`—, así que no puede depender de ninguna variable que
 * solo una de ellas prepare. Hoy no usa ninguna: todo su texto es fijo.
 */
?>
<footer class="bg-tinta-alta py-16 md:py-20">
    <div class="mx-auto max-w-[78rem] px-6 md:px-20">
        <hr class="border-linea">

        <div class="mt-12 grid gap-12 md:grid-cols-[1.4fr_1fr] md:gap-20">
            <div>
                <p class="marca">Pedro</p>
                <p class="rotulo mt-4 text-acero">
                    Abogado aduanero y tributario
                </p>
                <p class="cuerpo mt-8 max-w-md text-[0.8125rem]">
                    Esta página informa sobre los servicios del despacho. No constituye
                    asesoría jurídica ni crea una relación abogado&#8209;cliente.
                </p>
            </div>

            <?php /* Las anclas van con `/` delante y no sueltas: este pie lo
                     comparten la landing y `/perfil`, y `#situaciones` desde el
                     diagnóstico no llevaría a ninguna parte — esa sección no
                     existe en esa página. */ ?>
            <nav aria-label="Secciones del sitio" class="md:justify-self-end">
                <ul class="space-y-4">
                    <li><a href="/#situaciones" class="menu-enlace">Situaciones</a></li>
                    <li><a href="/#diagnostico" class="menu-enlace">Diagnóstico</a></li>
                    <li><a href="/#proceso" class="menu-enlace">Metodología</a></li>
                </ul>
            </nav>
        </div>

        <p class="rotulo mt-16 text-acero">
            © <?= date('Y') ?> · Colombia
        </p>
    </div>
</footer>
