<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * El diagnóstico, `/perfil`.
 *
 * Se emite ENTERO: los pasos salen en el HTML como un formulario de radios
 * corriente. El JavaScript solo esconde lo que no toca y anima el paso de uno
 * a otro. Consecuencias buscadas:
 *
 *  · Sin JavaScript la página es un documento legible que termina en el
 *    botón de WhatsApp. La conversión nunca depende de un script.
 *  · Las preguntas son indexables, y son literalmente las búsquedas por las
 *    que este despacho quiere aparecer.
 *  · Un solo sitio donde vive el texto. Emitir además un JSON para el script
 *    duplicaría el peso y crearía dos redacciones que se separan con el
 *    tiempo; el script lee lo que necesita de los `data-*`.
 *
 * Composición: Lex Aeterna. Tarjetas de grafito sobre negro, oro reservado a
 * la cifra y al estado elegido, y Geist para todo — la serif se retiró del
 * sitio entero. Comparte hoja de estilos, cabecera y pie con la landing, así
 * que lo único propio de aquí es la mecánica del cuestionario.
 *
 * @var list<array<string,mixed>> $pasos
 * @var array<string,int> $largos
 * @var array<string,\App\Modelos\Bloque> $bloques
 * @var array{precio:?int,duracion:?int,nombre:string} $asesoria
 * @var array{titulo:string,descripcion:string,indexable:bool,url:string,jsonLd:array<string,mixed>} $meta
 * @var array{numero:string,mensaje:string} $whatsapp
 */

$e = Vista::e(...);
$css = @file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css') ?: '';

$waBase = 'https://wa.me/' . rawurlencode($whatsapp['numero'])
    . '?text=' . rawurlencode($whatsapp['mensaje']);

$intro = $bloques['perfil_intro'] ?? null;
$resultado = $bloques['perfil_resultado'] ?? null;
$fuera = $bloques['perfil_fuera_alcance'] ?? null;

$precio = $asesoria['precio'] !== null
    ? '$' . number_format($asesoria['precio'], 0, ',', '.')
    : null;
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
<link rel="canonical" href="<?= $e($meta['url']) ?>/perfil">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= $e($meta['titulo']) ?>">
<meta property="og:description" content="<?= $e($meta['descripcion']) ?>">
<meta property="og:image" content="<?= $e($meta['url']) ?>/img/pedro-hero.jpg">
<meta property="og:locale" content="es_CO">

<link rel="icon" href="/img/icono.svg" type="image/svg+xml">

<?php /* Sin imágenes: la LCP es el titular de la primera pregunta. Esta
         página no necesita fotos y cargarlas costaría el presupuesto que ya
         defiende bin/auditar-landing.mjs. Se precarga Geist, que es con la
         que se compone ese titular. */ ?>
<link rel="preload" as="font" type="font/woff2" crossorigin
      href="/fonts/geist.woff2">

<style><?= $css ?></style>

<?php /* Síncrono y en el head a propósito. El CSS esconde los pasos
         inactivos solo cuando existe este atributo; sin él la página se
         pinta con los seis pasos visibles, que es exactamente lo que debe
         ver quien no tiene JavaScript. Ponerlo desde el script diferido
         haría que todos vieran ese destello primero. */ ?>
<?php /* El mismo `data-js` y, detrás, la misma red del revelado que la
         landing: esta página carga `landing.js` además de `perfil.js`, y es
         ese archivo —no `perfil.js`, que no toca `.revelar`— el que sube la
         opacidad del rótulo, del titular y de la entrada. Si no llega, lo
         que desaparece aquí es el `<h1>`, que además es la LCP. */ ?>
<script>
document.documentElement.dataset.js = '1';
window.__paRedRevelado = setTimeout(function () {
    var ocultos = document.querySelectorAll('.revelar:not([data-visible])');
    for (var i = 0; i < ocultos.length; i++) { ocultos[i].setAttribute('data-visible', ''); }
}, 2500);
</script>

<script type="application/ld+json"><?= Vista::json($meta['jsonLd']) ?></script>
</head>

<?php /* Ya no hace falta marcar «superficie oscura»: en Lex Aeterna no hay
         otra. Todo el sistema se define directamente sobre negro, así que la
         variante clara —y con ella la clase que la conmutaba— desaparece.

         Vale la pena recordar por qué existía: mientras hubo dos superficies,
         olvidar esa clase dejaba esta página pintada con los colores del
         papel sobre un fondo casi negro, y el enlace «Inicio» quedaba tinta
         sobre tinta, invisible, siendo la única salida. No se veía revisando
         el CSS, porque cada regla por separado era correcta; lo que fallaba
         es que ninguna aplicaba. Una paleta sola no puede repetirlo. */ ?>
<body class="perfil-cuerpo">
<a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:bg-oro focus:px-4 focus:py-2 focus:text-tinta">
    Saltar al contenido
</a>

<header class="barra-perfil">
    <div class="mx-auto flex items-center gap-6 px-5 py-3 md:px-7">
        <a href="/" class="boton-fantasma compacto shrink-0">
            <span aria-hidden="true">&larr;</span> Inicio
        </a>

        <div class="ml-auto flex items-center gap-4">
            <p class="font-mono text-eyebrow tracking-[0.16em] text-tinta-suave" id="contador" aria-hidden="true">
                Paso 1 de <?= (int) max($largos) ?>
            </p>
            <div class="progreso" role="presentation">
                <span id="progreso-barra"></span>
            </div>
        </div>
    </div>
</header>

<main id="contenido" class="mx-auto max-w-4xl px-6 pb-32 md:px-10">

<?php /* ── Portada del cuestionario ────────────────────────────────── */ ?>
<section class="pt-16 md:pt-24" id="intro">
    <p class="rotulo revelar">Diagnóstico gratuito</p>

    <h1 class="titular revelar mt-6 text-[2.25rem] md:text-[3.25rem]" style="--retardo:80ms">
        <?= $e($intro?->titulo ?? 'Veamos qué tiene entre manos') ?>
    </h1>

    <?php if ($intro?->subtitulo !== null): ?>
    <p class="entrada revelar mt-8 max-w-[46ch]" style="--retardo:150ms">
        <?= $e($intro->subtitulo) ?>
    </p>
    <?php endif; ?>

    <?php /* Lo que la persona necesita saber ANTES de contestar: cuánto
             tarda, que no se le piden datos y que al final no hay un cobro
             sorpresa. Sin esto, la primera pregunta se lee como el principio
             de un formulario de captura y se abandona. */ ?>
    <ul class="revelar mt-12 grid gap-x-14 border-b border-linea sm:grid-cols-2 sm:border-b-0"
        style="--retardo:210ms">
        <li class="indice-fila">Menos de dos minutos</li>
        <li class="indice-fila">Sin nombre, sin correo, sin teléfono</li>
        <li class="indice-fila">Nada se guarda: queda en su pantalla</li>
        <li class="indice-fila">Al final usted decide si escribe</li>
    </ul>
</section>

<?php /* ── El cuestionario ──────────────────────────────────────────── */ ?>
<form id="diagnostico" class="mt-20 md:mt-28" novalidate
      data-largo-aduanero="<?= (int) ($largos['aduanero'] ?? 0) ?>">

<?php foreach ($pasos as $indice => $paso): ?>
    <?php
    $id = (string) $paso['id'];
    $rama = is_string($paso['rama']) ? $paso['rama'] : '';
    $numero = $indice + 1;
    ?>
    <fieldset class="paso"
              id="paso-<?= $e($id) ?>"
              data-paso="<?= $e($id) ?>"
              data-rama="<?= $e($rama) ?>"
              data-resumen="<?= $e((string) $paso['resumen']) ?>"
              <?= $indice === 0 ? 'data-activo="1"' : '' ?>>

        <?php /* El `<legend>` solo admite contenido de frase, así que el
                 titular no puede ir dentro: iría un `<p>` donde el HTML no
                 lo permite y el navegador lo sacaría del grupo. Va aquí,
                 oculto, cumpliendo su función real —es el nombre accesible
                 del grupo de radios, lo que hace que un lector de pantalla
                 anuncie la pregunta al llegar a la primera opción— y el
                 titular visible va fuera, como encabezado normal. */ ?>
        <legend class="sr-only">
            Paso <?= $numero ?>. <?= $e((string) $paso['pregunta']) ?>
        </legend>

        <div class="doble-bisel">
            <div class="doble-bisel-interior p-6 md:p-10">
                <div class="flex items-center justify-between gap-4">
                    <span class="inline-flex items-center gap-2 rounded-full border border-oro/25 bg-oro/10 px-3 py-1 font-mono text-[10px] font-medium uppercase tracking-wider text-oro">
                        <?= $e((string) $paso['rotulo']) ?>
                    </span>
                    <span class="font-mono text-xs text-tinta-suave">Paso <?= $numero ?> de <?= count($pasos) ?></span>
                </div>

                <?php /* tabindex −1: al cambiar de paso el foco viene aquí. Sin
                         esto, quien navega con teclado o lector de pantalla se queda
                         con el foco en un botón que acaba de desaparecer. */ ?>
                <h2 class="text-2xl md:text-[2rem] text-papel mt-5" tabindex="-1" id="titulo-<?= $e($id) ?>">
                    <?= $e((string) $paso['pregunta']) ?>
                </h2>

                <?php if (is_string($paso['ayuda']) && $paso['ayuda'] !== ''): ?>
                <p class="mt-3 text-sm md:text-[0.9375rem] text-tinta-suave leading-relaxed">
                    <?= $e($paso['ayuda']) ?>
                </p>
                <?php endif; ?>

                <div class="mt-8 space-y-3">
                    <?php foreach ($paso['opciones'] as $j => $opcion): ?>
                    <?php
                    $valor = (string) $opcion['valor'];
                    $detalle = (string) $opcion['detalle'];
                    ?>
                    <label class="opcion">
                        <input type="radio"
                               name="<?= $e($id) ?>"
                               value="<?= $e($valor) ?>"
                               data-mensaje="<?= $e((string) $opcion['mensaje']) ?>"
                               <?= is_string($opcion['tecnico']) ? 'data-tecnico="' . $e($opcion['tecnico']) . '"' : '' ?>
                               <?= is_string($opcion['rama']) ? 'data-rama="' . $e($opcion['rama']) . '"' : '' ?>
                               <?= is_string($opcion['salida']) ? 'data-salida="' . $e($opcion['salida']) . '"' : '' ?>>

                        <?php /* Letra de orden, no casilla. Una casilla vacía es el
                                 lenguaje del trámite; una letra es el del índice. */ ?>
                        <span class="opcion-marca" aria-hidden="true"><?= chr(65 + $j) ?></span>

                        <span>
                            <span class="opcion-etiqueta"><?= $e((string) $opcion['etiqueta']) ?></span>
                            <?php if ($detalle !== ''): ?>
                            <span class="opcion-detalle"><?= $e($detalle) ?></span>
                            <?php endif; ?>
                        </span>

                        <span class="opcion-flecha" aria-hidden="true">&rarr;</span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <?php /* «Atrás» y «Continuar» existen aunque al tocar una opción se
                         avance solo. Con teclado, las flechas cambian la selección
                         sin emitir un clic —y avanzar con cada flecha haría
                         imposible comparar las opciones—, así que ahí «Continuar»
                         es el único camino. Para quien usa el dedo es un botón que
                         nunca pulsa; a cambio, nadie queda atrapado. */ ?>
                <div class="mt-8 pt-6 border-t border-linea flex items-center justify-between gap-4 paso-pie">
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 font-mono text-xs uppercase tracking-wider text-tinta-suave hover:text-papel disabled:opacity-30 disabled:cursor-not-allowed transition-colors js-atras">
                        <span aria-hidden="true">&larr;</span> Anterior
                    </button>

                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-5 py-2.5 font-mono text-xs uppercase tracking-wider bg-papel/10 hover:bg-papel/15 text-papel disabled:opacity-25 disabled:cursor-not-allowed transition-colors js-continuar" disabled>
                        <span>Continuar</span>
                        <span aria-hidden="true">&rarr;</span>
                    </button>
                </div>
            </div>
        </div>
    </fieldset>
<?php endforeach; ?>
</form>

<?php /* ── Salida crítica (regla 5) ────────────────────────────────── */ ?>
<section id="salida-urgente" class="bloque-salida" hidden>
    <?php /* El filete superior es rojo y no oro: es el único sitio del sitio
             entero donde eso está permitido. Aquí no se está señalando
             autoridad sino urgencia —operativo, captura, allanamiento— y el
             oro, que en esta paleta significa «esto manda», leería como una
             sección destacada más. El distintivo vive en `.marca-urgente`
             para que ese rojo tenga nombre y no se copie a otro sitio. */ ?>
    <div class="doble-bisel" style="border-top-color: var(--color-alerta);">
        <div class="doble-bisel-interior p-8 md:p-10">
            <span class="marca-urgente">
                <span class="pulso animate-ping" aria-hidden="true"></span>
                Atención urgente
            </span>

            <h2 class="titular-seccion mt-6" tabindex="-1">
                Escriba ahora mismo.
            </h2>

            <p class="entrada mt-5 max-w-[52ch]">
                Un operativo en curso, una captura o un allanamiento no se
                atienden con preguntas de opción múltiple. Escriba por WhatsApp
                y cuente qué está pasando: es lo único sensato que puede hacer
                esta página.
            </p>

            <div class="mt-8">
                <?= Vista::botonWhatsapp($waBase, 'Escribir ahora', 'js-wa js-wa-urgente') ?>
            </div>
        </div>
    </div>
</section>

<?php /* ── Fuera de alcance ───────────────────────────────────────────
         El despacho atiende procesos correctivos: cosas que ya están
         abiertas. Decirlo de frente en el paso 1 es mejor que arrastrar a
         alguien por seis pantallas para negarle al final. No lleva botón
         verde: sería vender lo que se acaba de decir que no se vende. */ ?>
<section id="salida-fuera" class="bloque-salida" hidden>
    <div class="doble-bisel">
        <div class="doble-bisel-interior p-8 md:p-10">
            <span class="inline-flex items-center gap-2 rounded-full border border-linea bg-papel/10 px-3 py-1 font-mono text-[10px] font-medium uppercase tracking-wider text-tinta-suave">
                Ámbito de Práctica
            </span>

            <h2 class="text-2xl md:text-3xl text-papel mt-5" tabindex="-1">
                <?= $e($fuera?->titulo ?? 'Este despacho entra cuando el problema ya existe') ?>
            </h2>

            <p class="entrada mt-5 max-w-[52ch]">
                <?= $e($fuera?->subtitulo ?? 'Lo que se atiende aquí son procedimientos ya abiertos: '
                    . 'una mercancía aprehendida, un requerimiento notificado, una sanción en curso. '
                    . 'Si todavía no hay nada de eso, lo que necesita es acompañamiento en la operación, '
                    . 'y ese no es el trabajo de este despacho.') ?>
            </p>

            <p class="mt-5 max-w-[52ch] text-[0.9375rem] leading-relaxed text-tinta-suave">
                Guarde la página. El día que llegue algo de la DIAN por escrito, vuelva y
                empiece por aquí.
            </p>

            <div class="mt-8 pt-6 border-t border-linea flex flex-wrap items-center gap-x-8 gap-y-4">
                <button type="button" class="boton-fantasma js-reiniciar">
                    <span aria-hidden="true">&larr;</span> Volver a empezar
                </button>

                <a href="/#situaciones" class="boton-fantasma">
                    Ver qué situaciones sí se atienden
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </div>
    </div>
</section>

<?php /* ── Resultado ──────────────────────────────────────────────────
         Sin JavaScript esta sección se ve tal cual y funciona como el
         cierre normal de la página: qué incluye la asesoría, cuánto vale y
         el botón. Con JavaScript, el script rellena `#resumen` y el
         titular técnico antes de mostrarla.

         Lo que NO aparece nunca: el puntaje. Es interno (§3.2) y mide, en
         parte, capacidad de pago. No puede llegar a quien acaba de perder
         su mercancía. */ ?>
<section id="salida-resultado" class="bloque-salida">
    <div class="doble-bisel">
        <div class="doble-bisel-interior p-8 md:p-10">
            <div class="flex items-center justify-between gap-4">
                <span class="inline-flex items-center gap-2 rounded-full border border-linea bg-papel/10 px-3 py-1 font-mono text-[10px] font-medium uppercase tracking-wider text-papel">
                    <span class="w-1.5 h-1.5 rounded-full bg-oro"></span>
                    Diagnóstico Completado
                </span>
                <span class="font-mono text-xs text-oro">Resultado Técnico</span>
            </div>

            <h2 class="text-3xl md:text-4xl text-papel mt-5" tabindex="-1" id="resultado-titulo">
                <?= $e($resultado?->titulo ?? 'Lo que usted tiene tiene nombre') ?>
            </h2>

            <?php /* El nombre técnico de la situación. Es la pieza que hace que la
                     página valga: alguien que escribió «me quitaron la mercancía»
                     lee «Aprehensión de mercancía» y entiende que del otro lado hay
                     alguien que ya sabe de qué se trata. */ ?>
            <p id="resultado-tecnico"
               class="mt-6 inline-flex items-center gap-2.5 rounded-full border border-oro/40 bg-oro/10 px-4 py-2 font-mono text-sm font-semibold tracking-wide text-oro"
               hidden></p>

            <div class="mt-8 pt-6 border-t border-linea">
                <h4 class="font-mono text-xs uppercase tracking-widest text-tinta-suave mb-4">Resumen de las respuestas:</h4>
                <dl id="resumen" class="space-y-1"></dl>
            </div>

            <?php /* Regla 2, en el sitio donde más tienta romperla. Un diagnóstico
                     que dice «le quedan diez días» es exactamente lo que puede
                     costar un caso, y es también lo que la gente vino a buscar. Se
                     nombra la existencia de los términos sin nombrar ninguno, y se
                     dice quién los dice. */ ?>
            <p class="entrada mt-8 max-w-[54ch]">
                Los procedimientos ante la DIAN corren con términos propios y empiezan a
                contar desde la notificación. Cuáles aplican a su caso y qué se puede
                hacer con ellos se lo dice el abogado mirando sus documentos, no un
                formulario.
            </p>

            <div class="ficha-asesoria mt-8">
                <div class="flex flex-wrap items-baseline justify-between gap-4">
                    <p class="rotulo"><?= $e($asesoria['nombre']) ?></p>

                    <?php if ($precio !== null): ?>
                    <p class="flex items-baseline gap-3">
                        <span class="text-2xl text-oro"><?= $e($precio) ?></span>
                        <span class="dato">
                            <?= $asesoria['duracion'] !== null ? (int) $asesoria['duracion'] . ' min · ' : '' ?>virtual
                        </span>
                    </p>
                    <?php endif; ?>
                </div>

                <ul class="mt-8">
                    <?php foreach ($resultado?->lista('incluye') ?: [
                        'Revisión de los documentos que envíe antes de la sesión',
                        'Una hora por videollamada con el abogado',
                        'Hoja de ruta: qué se puede hacer y en qué orden',
                    ] as $item): ?>
                    <li class="indice-fila"><?= $e(is_string($item) ? $item : '') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="mt-8">
                <?= Vista::botonWhatsapp($waBase, 'Enviar mi caso por WhatsApp', 'js-wa js-wa-resultado') ?>
            </div>

            <p class="mt-4 text-[0.875rem] text-tinta-suave">
                Se abre WhatsApp con el resumen ya escrito. Puede leerlo y cambiarlo antes
                de enviarlo.
            </p>

            <div class="mt-8 pt-6 border-t border-linea">
                <button type="button" class="boton-fantasma js-reiniciar">
                    <span aria-hidden="true">&larr;</span> Corregir una respuesta
                </button>
            </div>
        </div>
    </div>
</section>

<?php /* Región viva: anuncia el cambio de paso a los lectores de pantalla,
         que no se enteran de que el DOM se reordenó. */ ?>
<p id="anuncio" class="sr-only" role="status" aria-live="polite"></p>

</main>

<?php require dirname(__DIR__) . '/landing/bloques/pie.php'; ?>

<?php /* landing.js primero: perfil.js le pide la referencia de campaña y el
         registro de eventos en vez de duplicar sessionStorage y sendBeacon.
         Con `defer` se ejecutan en orden de documento, así que para cuando
         corre el segundo, `window.PA` ya existe. */ ?>
<script src="/js/landing.js" defer></script>
<script src="/js/perfil.js" defer></script>
</body>
</html>