<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Landing pública. Se renderiza una vez y se sirve desde caché
 * (App\Servicios\Landing): con caché caliente, una visita no toca MySQL.
 *
 * @var array<string,\App\Modelos\Bloque> $bloques
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string,jsonLd:array<string,mixed>} $meta
 * @var array{numero:string,mensaje:string} $whatsapp
 * @var array{token:string,url:string} $chatwoot
 * @var int $topeEventos
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';

// Enlace de reserva, sin UTMs: es el que sirve si el JavaScript no corre.
// El JS lo reescribe al vuelo añadiendo la campaña (ver public/js/landing.js).
$waBase = 'https://wa.me/' . rawurlencode($whatsapp['numero'])
    . '?text=' . rawurlencode($whatsapp['mensaje']);
?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($meta['titulo']) ?></title>
<meta name="description" content="<?= $e($meta['descripcion']) ?>">
<?php if (!$meta['indexable']): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="canonical" href="<?= $e($meta['url']) ?>/">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= $e($meta['titulo']) ?>">
<meta property="og:description" content="<?= $e($meta['descripcion']) ?>">
<meta property="og:image" content="<?= $e($meta['url']) ?>/img/pedro-hero.jpg">
<meta property="og:locale" content="es_CO">

<link rel="icon" href="/img/icono.svg" type="image/svg+xml">

<?php /* La LCP es el titular, no una imagen: renderiza en cuanto se parsea
         el CSS, que va aquí mismo. Se precarga la foto del hero igualmente,
         porque entra en cuadro al primer scroll.

         El `imagesizes` tiene que ser IDÉNTICO al `sizes` del <img> de
         hero.php. Si difieren, el navegador elige un ancho para la precarga
         y otro para la etiqueta, y descarga la foto dos veces. */ ?>
<link rel="preload" as="image" fetchpriority="high"
      href="/img/pedro-hero-400.avif"
      imagesrcset="/img/pedro-hero-400.avif 400w, /img/pedro-hero-640.avif 640w, /img/pedro-hero-890.avif 890w"
      imagesizes="(min-width: 768px) 56vw, 100vw"
      type="image/avif">

<?php /* La serif de los titulares se precarga y las otras dos no. El titular
         del hero ES la LCP: sin `preload` el navegador no descubre la fuente
         hasta parsear el CSS, pinta con la de reserva y la cambia después —el
         salto se ve y además cuenta como desplazamiento de diseño. Geist y
         Geist Mono entran con el cuerpo, donde el intercambio no se nota.

         `crossorigin` es obligatorio aunque la fuente sea del mismo origen:
         las peticiones de fuentes viajan en modo CORS, y sin el atributo el
         navegador descarga el archivo dos veces —una para la precarga y otra
         para usarlo—, que es peor que no precargar. */ ?>
<link rel="preload" as="font" type="font/woff2" href="/fonts/instrument-serif.woff2" crossorigin>

<?php /* CSS incrustado: son unos pocos KB y evita un viaje de ida y vuelta
         bloqueante justo en el camino crítico del render. */ ?>
<style><?= $css ?></style>

<script type="application/ld+json"><?= Vista::json($meta['jsonLd']) ?></script>

<?php /* La red del revelado. `public/js/landing.js` la desarma nada más
         cargar —hace `clearTimeout(window.__paRedRevelado)`—, así que en el
         camino normal esto no llega a dispararse nunca.

         Existe para el camino anormal: `.revelar` arranca en opacidad 0 y
         quien la sube es el IntersectionObserver de ese archivo. Si el
         archivo no llega —un 404 tras un despliegue a medias, la red que se
         corta, una extensión que lo bloquea— el bloque del diagnóstico
         completo se queda invisible, y la página no da ningún error: se ve
         una franja oscura vacía. Con JavaScript desactivado del todo el
         caso lo cubre el CSS (`html:not([data-js]) .revelar`); esta red es
         para el otro, que el CSS no puede distinguir.

         Va en el `<head>` y no al final del `<body>` a propósito: si se
         emitiera después, un fallo de red que corte el HTML a la mitad se
         llevaría por delante justo la red que debía cubrirlo. */ ?>
<script>
document.documentElement.dataset.js = '1';
window.__paRedRevelado = setTimeout(function () {
    var ocultos = document.querySelectorAll('.revelar:not([data-visible])');
    for (var i = 0; i < ocultos.length; i++) { ocultos[i].setAttribute('data-visible', ''); }
}, 2500);
</script>
</head>

<body>
<a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:bg-tinta focus:px-4 focus:py-2 focus:text-papel">
    Saltar al contenido
</a>

<?php
/* Cabecera.
 *
 * Los tres enlaces son anclas a secciones de esta misma página, no rutas: la
 * landing es un documento único y un menú que llevara a otras páginas
 * repartiría en cuatro sitios la atención que tiene que terminar en un solo
 * botón. La única salida real es `/perfil`, y por eso es la que va nombrada
 * como diagnóstico.
 *
 * En móvil los enlaces desaparecen y quedan la firma y el botón. No hay
 * hamburguesa a propósito: abrir un panel exige JavaScript, y §13.5 dice que
 * la conversión no depende de un script. Con la página completa a un scroll
 * de distancia, un menú plegable resolvería un problema que no existe.
 */
?>
<header class="barra-sitio sobre-tinta text-papel">
    <div class="mx-auto flex max-w-6xl items-center gap-6 px-5 py-3 md:px-8">
        <a href="#contenido" class="marca" aria-label="Pedro, abogado aduanero y tributario">Pedro</a>

        <nav aria-label="Secciones" class="ml-auto hidden items-center gap-8 md:flex">
            <a href="#situaciones" class="menu-enlace">Situaciones</a>
            <a href="#diagnostico" class="menu-enlace">Diagnóstico</a>
            <a href="#proceso" class="menu-enlace">Cómo funciona</a>
        </nav>

        <div class="ml-auto md:ml-0">
            <?= Vista::botonWhatsapp($waBase, 'Escribir', 'compacto') ?>
        </div>
    </div>
</header>

<main id="contenido">
<?php
// El orden de esta lista es el que manda; `landing_bloques.orden` solo
// ordena la edición en el panel. `perfil` va detrás del índice de
// situaciones a propósito: ver `bloques/perfil.php`.
foreach (['hero', 'casos', 'perfil', 'credenciales', 'proceso', 'cta_final'] as $clave) {
    if (!isset($bloques[$clave])) {
        continue;
    }

    $bloque = $bloques[$clave];
    $parcial = __DIR__ . '/bloques/' . $clave . '.php';

    if (is_readable($parcial)) {
        require $parcial;
    }
}
?>
</main>

<?php require __DIR__ . '/bloques/pie.php'; ?>

<script src="/js/landing.js" defer></script>

<?php if ($chatwoot['token'] !== '' && $chatwoot['url'] !== ''): ?>
<?php /* El widget solo se emite cuando Chatwoot existe (Etapa 2). Cargarlo
         apuntando a un servidor que todavía no está desplegado costaría una
         petición fallida en cada visita. */ ?>
<script defer>
window.chatwootSettings = { position: 'right', type: 'expanded_bubble', launcherTitle: 'Escríbanos' };
(function (d, t) {
    var s = d.createElement(t), x = d.getElementsByTagName(t)[0];
    s.src = <?= Vista::json($chatwoot['url'] . '/packs/js/sdk.js') ?>;
    s.defer = true; s.async = true;
    s.onload = function () {
        window.chatwootSDK.run({
            websiteToken: <?= Vista::json($chatwoot['token']) ?>,
            baseUrl: <?= Vista::json($chatwoot['url']) ?>
        });
    };
    x.parentNode.insertBefore(s, x);
})(document, 'script');
</script>
<?php endif; ?>
</body>
</html>
