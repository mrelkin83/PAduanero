<?php

declare(strict_types=1);

use App\Soporte\Vista;

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
 * solo una de ellas prepare. La única que acepta es OPCIONAL: `$pie`, el
 * bloque `pie` de `landing_bloques` (migración 0019), con el correo, el
 * teléfono y las redes que Pedro edita desde el panel de contenido. Si no
 * llega —una página que no lo cargue, el bloque oculto— el pie se pinta
 * igual, solo que sin la columna de contacto: la regla de 0014, omitir en
 * silencio lo que no tiene dato, aplicada al pie entero.
 *
 * `telefonos` (0023) es una lista de hasta tres, no un solo campo: cada
 * número es su propio enlace `tel:`, y los vacíos se omiten uno por uno.
 *
 * @var \App\Modelos\Bloque|null $pie
 */

$e = Vista::e(...);
$pie ??= null;

$correo = $pie?->texto('correo') ?? '';
$direccion = $pie?->texto('direccion') ?? '';

// Cada teléfono es su propio enlace `tel:`: meter dos números en un solo
// campo de texto libre producía un `tel:` con los dos pegados y sin sentido.
$telefonos = [];
foreach ($pie?->lista('telefonos') ?? [] as $telefono) {
    $telefono = is_string($telefono) ? trim($telefono) : '';
    if ($telefono !== '') {
        $telefonos[] = $telefono;
    }
}

// Solo las redes con nombre Y url: una URL sin rótulo o un rótulo sin
// destino son huecos, no enlaces.
$redes = [];
foreach ($pie?->lista('redes') ?? [] as $red) {
    if (!is_array($red)) {
        continue;
    }
    $nombre = is_string($red['nombre'] ?? null) ? trim($red['nombre']) : '';
    $url = is_string($red['url'] ?? null) ? trim($red['url']) : '';
    if ($nombre !== '' && preg_match('#^https://#i', $url) === 1) {
        $redes[] = ['nombre' => $nombre, 'url' => $url];
    }
}

$hayContacto = $correo !== '' || $telefonos !== [] || $direccion !== '' || $redes !== [];
?>
<footer class="border-t border-linea py-16 md:py-20">
    <div class="mx-auto max-w-[78rem] px-6 md:px-20">
        <hr class="border-linea">

        <div class="mt-12 grid gap-12 <?= $hayContacto
            ? 'md:grid-cols-[1.4fr_1fr_1fr]'
            : 'md:grid-cols-[1.4fr_1fr]' ?> md:gap-20">
            <div>
                <?php /* La insignia cierra todas las páginas públicas: este pie
                         lo comparten la landing, `/perfil` y las legales. Va
                         sobre la firma, no en vez de ella. */ ?>
                <img src="/img/logo-pedro.png" alt="" width="72" height="72" class="mb-5 h-18 w-18" loading="lazy" decoding="async">
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
            <nav aria-label="Secciones del sitio" class="<?= $hayContacto ? '' : 'md:justify-self-end' ?>">
                <ul class="space-y-4">
                    <li><a href="/#situaciones" class="menu-enlace">Situaciones</a></li>
                    <li><a href="/#diagnostico" class="menu-enlace">Diagnóstico</a></li>
                    <li><a href="/#proceso" class="menu-enlace">Metodología</a></li>
                    <li><a href="/privacidad" class="menu-enlace">Tratamiento de datos</a></li>
                    <li><a href="/condiciones" class="menu-enlace">Condiciones del servicio</a></li>
                </ul>
            </nav>

            <?php if ($hayContacto): ?>
            <div class="md:justify-self-end">
                <p class="rotulo text-acero">Contacto</p>
                <ul class="mt-4 space-y-4">
                    <?php if ($correo !== ''): ?>
                    <li><a href="mailto:<?= $e($correo) ?>" class="menu-enlace"><?= $e($correo) ?></a></li>
                    <?php endif; ?>
                    <?php foreach ($telefonos as $telefono): ?>
                    <li><a href="tel:<?= $e(preg_replace('/[^+\d]/', '', $telefono) ?? '') ?>" class="menu-enlace"><?= $e($telefono) ?></a></li>
                    <?php endforeach; ?>
                    <?php if ($direccion !== ''): ?>
                    <li><address class="not-italic"><?= $e($direccion) ?></address></li>
                    <?php endif; ?>
                    <?php foreach ($redes as $red): ?>
                    <li>
                        <a href="<?= $e($red['url']) ?>" class="menu-enlace" rel="noopener" target="_blank">
                            <?= $e($red['nombre']) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <p class="rotulo mt-16 text-acero">
            © <?= date('Y') ?> · Colombia
        </p>
    </div>
</footer>
