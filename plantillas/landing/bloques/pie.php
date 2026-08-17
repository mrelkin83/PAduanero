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
<footer class="sobre-tinta bg-tinta-alta py-14 text-tinta-suave">
    <div class="mx-auto max-w-6xl px-5 md:px-8">
        <hr class="border-papel/10">

        <div class="mt-10 grid gap-8 md:grid-cols-[1fr_2fr] md:gap-16">
            <div>
                <p class="marca">Pedro</p>
                <p class="mt-2 font-mono text-[0.75rem] tracking-[0.14em] uppercase">
                    Abogado aduanero y tributario
                </p>
            </div>

            <div class="text-[0.8125rem] leading-relaxed">
                <p>
                    Esta página informa sobre los servicios del despacho. No constituye
                    asesoría jurídica ni crea una relación abogado&#8209;cliente.
                </p>
                <p class="mt-3 font-mono text-[0.75rem] tracking-wide">
                    © <?= date('Y') ?> · Colombia
                </p>
            </div>
        </div>
    </div>
</footer>
