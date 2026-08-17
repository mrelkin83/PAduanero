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
      imagesizes="(min-width: 768px) 26rem, 100vw"
      type="image/avif">

<?php /* CSS incrustado: son unos pocos KB y evita un viaje de ida y vuelta
         bloqueante justo en el camino crítico del render. */ ?>
<style><?= $css ?></style>

<script type="application/ld+json"><?= Vista::json($meta['jsonLd']) ?></script>
</head>

<body>
<a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:bg-tinta focus:px-4 focus:py-2 focus:text-papel">
    Saltar al contenido
</a>

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
