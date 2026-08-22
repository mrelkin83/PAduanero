<?php

declare(strict_types=1);

/**
 * Pie.
 *
 * Los enlaces legales llevan a `/privacidad` y `/condiciones` (2026-08-22).
 * El de privacidad no es cortesía: Google exige que la página principal
 * enlace la política para publicar la app OAuth del calendario, y el texto
 * mismo es requisito para encender el motor de WhatsApp. Ambos documentos
 * están pendientes de la aprobación de Pedro (ver sus plantillas).
 *
 * Lo requieren las páginas públicas —`landing/pagina.php`, `perfil/pagina.php`
 * y las de `legal/`—, así que no puede depender de ninguna variable que
 * solo una de ellas prepare. Hoy no usa ninguna: todo su texto es fijo.
 */
?>
<footer class="border-t border-linea py-16 md:py-20">
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
                    <li><a href="/privacidad" class="menu-enlace">Tratamiento de datos</a></li>
                    <li><a href="/condiciones" class="menu-enlace">Condiciones del servicio</a></li>
                </ul>
            </nav>
        </div>

        <p class="rotulo mt-16 text-acero">
            © <?= date('Y') ?> · Colombia
        </p>
    </div>
</footer>
