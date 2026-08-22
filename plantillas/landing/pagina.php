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
      imagesizes="(min-width: 768px) 54vw, 100vw"
      type="image/avif">

<?php /* Se precarga Geist y no Geist Mono. El titular del hero ES la LCP y
         ahora se compone en Geist: sin `preload` el navegador no descubre la
         fuente hasta parsear el CSS, pinta con la de reserva y la cambia
         después —el salto se ve y cuenta como desplazamiento de diseño—. La
         mono solo viste rótulos y cifras, donde el intercambio no se nota.

         `crossorigin` es obligatorio aunque la fuente sea del mismo origen:
         las peticiones de fuentes viajan en modo CORS, y sin el atributo el
         navegador descarga el archivo dos veces —una para la precarga y otra
         para usarlo—, que es peor que no precargar. */ ?>
<link rel="preload" as="font" type="font/woff2" href="/fonts/geist.woff2" crossorigin>

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
// El orden de esta lista es el que manda; `landing_bloques.orden` solo
// ordena la edición en el panel. `perfil` va detrás del índice de
// situaciones a propósito: ver `bloques/perfil.php`.
//
// `confianza` y `testimonios` van juntos y detrás de `credenciales`, que es
// donde la página termina de presentar a Pedro. La secuencia contesta tres
// preguntas en el orden en que se hacen: quién es (credenciales), si existe
// de verdad (confianza) y cómo trata a la gente (testimonios).
//
// El orden entre esos dos importa. `confianza` se comprueba sin creerle a
// nadie; `testimonios` exige creerle a alguien. Un testimonio leído antes de
// cualquier prueba de existencia se lee como el decorado de la estafa que el
// visitante teme, no como su desmentido.
//
// Y los tres van ANTES de `proceso`, que es donde aparece el precio. Nadie
// evalúa una tarifa de $400.000 hasta que decidió que el cobrador existe.
//
// Se compone a un búfer y no directamente a la salida porque el menú de la
// cabecera se deriva de esto — ver abajo—, y la cabecera va antes en el HTML.
ob_start();

foreach ([
    'hero',
    'casos',
    'perfil',
    'credenciales',
    'confianza',
    'testimonios',
    'proceso',
    'cta_final',
] as $clave) {
    if (!isset($bloques[$clave])) {
        continue;
    }

    $bloque = $bloques[$clave];
    $parcial = __DIR__ . '/bloques/' . $clave . '.php';

    if (is_readable($parcial)) {
        require $parcial;
    }
}

$cuerpo = (string) ob_get_clean();

/* El menú se deriva de lo que REALMENTE se pintó, no de una lista paralela.
 *
 * Un bloque puede no salir por tres motivos distintos —no está en
 * `landing_bloques`, está oculto, o se esconde solo por falta de datos, como
 * hacen `confianza` y `testimonios`— y ninguno de los tres es visible desde
 * aquí. Con una lista escrita a mano, el menú acabaría ofreciendo «Confianza»
 * mientras esa sección no existe: un enlace que no lleva a ninguna parte, en
 * la página cuyo trabajo es demostrar que no es una fachada.
 *
 * Se filtra por el ancla, que es lo único que el menú necesita saber. Así el
 * problema no puede volver con el próximo bloque que alguien añada.
 */
$menu = [
    ['#situaciones', 'Situaciones'],
    ['#diagnostico', 'Diagnóstico'],
    ['#confianza', 'Confianza'],
    ['#proceso', 'Metodología'],
];

$menu = array_values(array_filter(
    $menu,
    static fn (array $i): bool => str_contains($cuerpo, 'id="' . substr($i[0], 1) . '"'),
));
?>
<?php
/* Cabecera.
 *
 * Los enlaces son anclas a secciones de esta misma página, no rutas: la
 * landing es un documento único y un menú que llevara a otras páginas
 * repartiría en varios sitios la atención que tiene que terminar en un solo
 * botón. La única salida real es `/perfil`, y por eso es la que va nombrada
 * como diagnóstico.
 *
 * En móvil los enlaces desaparecen y quedan la firma y el botón. No hay
 * hamburguesa a propósito: abrir un panel exige JavaScript, y la conversión
 * no depende de un script. Con la página completa a un scroll de distancia,
 * un menú plegable resolvería un problema que no existe.
 */
?>
<header class="barra-sitio">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="#contenido" class="marca" aria-label="Pedro, abogado aduanero y tributario">Pedro</a>

        <?php if ($menu !== []): ?>
        <?php /* Los enlaces marcan la sección en curso con un punto de oro
                 que pone `landing.js` con un observador. Es adorno: sin
                 script los enlaces siguen llevando a su ancla. */ ?>
        <nav aria-label="Secciones" class="ml-auto hidden items-center gap-10 md:flex" data-menu>
            <?php foreach ($menu as [$ancla, $texto]): ?>
            <a href="<?= $e($ancla) ?>" class="menu-enlace"><?= $e($texto) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <div class="<?= $menu === [] ? 'ml-auto' : 'ml-auto md:ml-0' ?> flex items-center gap-2 md:gap-4">
            <a href="/perfil" data-evento="perfil_inicio" class="boton-diagnostico-global">
                Diagnóstico <span class="hidden sm:inline">&nbsp;Gratuito</span>
            </a>
            <div class="hidden sm:block">
                <?= Vista::botonWhatsapp($waBase, 'WhatsApp', 'compacto') ?>
            </div>
            <div class="sm:hidden">
                <a href="<?= $e($waBase) ?>" aria-label="Escribir por WhatsApp" class="flex h-9 w-9 items-center justify-center rounded-full border border-linea-fuerte bg-white/5 text-acero hover:bg-white/10 hover:text-papel transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.305-.88-.653-1.474-1.46-1.647-1.757-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51h-.57c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.333.158 11.893c0 2.096.549 4.14 1.595 5.945L0 24l6.335-1.652c1.746.943 3.71 1.444 5.714 1.447h.005c6.553 0 11.89-5.333 11.893-11.893a11.821 11.821 0 00-3.483-8.413z"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</header>

<main id="contenido">
<?= $cuerpo ?>
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
