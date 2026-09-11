<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Ayudantes de plantilla. PHP plano, sin motor de plantillas
 * (docs/CONTRATOS.md).
 *
 * Existe sobre todo por `e()`: en una plantilla, olvidar un `htmlspecialchars`
 * sobre texto que edita un usuario del panel es un XSS almacenado. Tener el
 * escape a tres letras de distancia hace que no se olvide.
 */
final class Vista
{
    /** Escape para contexto HTML. El nombre es corto a propósito. */
    public static function e(?string $texto): string
    {
        return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Texto plano → párrafos escapados. Los saltos de línea dobles separan
     * párrafo; los simples se mantienen dentro del mismo `<p>` como `<br>`.
     * El texto en sí SIEMPRE pasa por `e()` primero — nunca se interpreta
     * como HTML, sin importar quién lo escribió (ver ADR de contenido de
     * lecciones, spec del sub-proyecto 3).
     */
    public static function parrafos(?string $texto): string
    {
        $texto = trim($texto ?? '');
        if ($texto === '') {
            return '';
        }

        $bloques = preg_split('/\n{2,}/', $texto) ?: [$texto];
        $html = '';
        foreach ($bloques as $bloque) {
            $html .= '<p>' . nl2br(self::e(trim($bloque)), false) . '</p>';
        }

        return $html;
    }

    /** Escape para incrustar en un atributo de JavaScript o un data-*. */
    public static function json(mixed $valor): string
    {
        $codificado = json_encode(
            $valor,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return $codificado === false ? '{}' : $codificado;
    }

    /**
     * Botón de WhatsApp.
     *
     * El `href` que se emite no lleva UTMs: es el que funciona sin
     * JavaScript. `public/js/landing.js` lo reescribe al vuelo añadiendo la
     * campaña, que es lo que permite atribuir el caso a su anuncio. La clase
     * `js-wa` es el enganche.
     *
     * Va en verde de WhatsApp a propósito: la gente reconoce el color y sabe
     * qué aplicación va a abrir. Eso pesa más que la coherencia cromática en
     * el único botón que tiene que pulsarse.
     */
    public static function botonWhatsapp(string $href, string $texto, string $clases = ''): string
    {
        $glifo = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
            . '<path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 '
            . '1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3'
            . '-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 '
            . '0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.06 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63'
            . '.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.42-.07-.13-.27-.2-.57-.35M12.05 '
            . '21.8h-.02a9.8 9.8 0 0 1-4.99-1.37l-.36-.21-3.71.97.99-3.62-.23-.37a9.79 9.79 0 0 1-1.5-5.23c0-5.4 4.4-9.8 '
            . '9.82-9.8 2.62 0 5.08 1.03 6.93 2.88a9.74 9.74 0 0 1 2.87 6.93c0 5.41-4.4 9.82-9.8 9.82M20.52 3.45A11.7 '
            . '11.7 0 0 0 12.05 0C5.6 0 .36 5.24.36 11.68c0 2.06.54 4.07 1.56 5.85L.26 24l6.62-1.74a11.66 11.66 0 0 0 '
            . '5.17 1.24h.01c6.44 0 11.69-5.24 11.69-11.68a11.6 11.6 0 0 0-3.43-8.27"/></svg>';

        // Botón dentro de botón: el glifo no va suelto al lado del texto,
        // va en su propio disco al ras del borde interior. En el hover el
        // disco se desplaza en diagonal mientras el botón entero se
        // comprime — la pieza se siente pulsable antes de pulsarla.
        //
        // El orden es texto-luego-disco y no al revés porque el disco hace
        // de flecha: señala hacia dónde lleva.
        return sprintf(
            '<a href="%s" class="boton-wa js-wa %s" data-evento="click_whatsapp" rel="noopener">'
            . '<span>%s</span><span class="disco">%s</span></a>',
            self::e($href),
            self::e($clases),
            self::e($texto),
            $glifo,
        );
    }

    /**
     * La URL de un archivo de `public/`, con la marca de tiempo detrás.
     *
     * Existe por un fallo del despliegue del 2026-09-11, que no dio ningún
     * error y casi pasa desapercibido: se desplegó `perfil.js` nuevo, el
     * origen lo servía bien, y **Cloudflare siguió entregando el del 26 de
     * agosto** — nginx marca los estáticos `max-age=2592000, immutable`, así
     * que el borde tiene permiso para no volver a preguntar en treinta días.
     * El HTML sí se renovó (la caché de páginas se borra en cada despliegue),
     * de modo que la página traía los `data-*` nuevos y un script viejo que
     * no sabía leerlos: la mitad de una función, sin una sola señal de error.
     *
     * Con el `?v=<mtime>` la URL cambia cuando cambia el archivo, así que un
     * archivo nuevo es una entrada nueva para cualquier caché y no hay nada
     * que purgar a mano. Es la misma idea que ya usa `CachePagina` con el
     * mtime del CSS; lo que faltaba era aplicarla a lo que sirve nginx.
     *
     * Si el archivo no existe se devuelve la ruta tal cual: un `filemtime()`
     * fallido no puede tumbar la página por un parámetro de caché.
     *
     * OJO, el `?v=` queda escrito dentro del HTML cacheado, y `CachePagina`
     * solo mira el centinela y el mtime del CSS — no el del JavaScript. Un
     * despliegue que cambie únicamente un `.js` regenera el archivo pero no
     * la página que lo enlaza. Lo que cierra ese hueco es el
     * `rm -f storage/cache/*.html` del RUNBOOK §5, que por eso no es
     * opcional.
     */
    public static function activo(string $ruta): string
    {
        $disco = dirname(__DIR__, 2) . '/public' . $ruta;
        $marca = @filemtime($disco);

        return $marca === false ? $ruta : $ruta . '?v=' . $marca;
    }

    /**
     * `<picture>` con AVIF, WebP y el JPEG original de reserva.
     *
     * Las dimensiones van explícitas siempre: sin `width` y `height` el
     * navegador no reserva el hueco, la imagen empuja el texto al cargar y el
     * CLS se dispara por encima del 0.1 que exige `docs/PANEL_ADMIN.md` §5.
     *
     * @param list<int> $anchos variantes generadas por bin/optimizar-imagenes.php
     */
    public static function imagen(
        string $archivo,
        string $alt,
        int $ancho,
        int $alto,
        string $clases = '',
        bool $prioritaria = false,
        string $sizes = '100vw',
        array $anchos = [400, 640, 890],
    ): string {
        $base = pathinfo($archivo, PATHINFO_FILENAME);
        $e = self::e(...);

        // Cada variante va versionada por la misma razón que los scripts
        // (ver `activo()`): nginx las sirve `immutable` durante 30 días, así
        // que regenerar una imagen y desplegarla NO la cambia para nadie.
        // Pasó el 2026-09-11 con el hero y con el retrato de la consultora:
        // archivos nuevos en el origen, los viejos en el navegador.
        $fuente = static function (string $formato, string $mime) use ($base, $anchos, $sizes, $e): string {
            $conjunto = [];
            foreach ($anchos as $w) {
                $conjunto[] = self::activo("/img/{$base}-{$w}.{$formato}") . " {$w}w";
            }

            return sprintf(
                '<source type="%s" srcset="%s" sizes="%s">',
                $mime,
                $e(implode(', ', $conjunto)),
                $e($sizes),
            );
        };

        // La LCP se precarga y se decodifica de forma síncrona; el resto va
        // perezoso para no competir con ella por el ancho de banda.
        $carga = $prioritaria
            ? 'loading="eager" fetchpriority="high" decoding="sync"'
            : 'loading="lazy" decoding="async"';

        return '<picture>'
            . $fuente('avif', 'image/avif')
            . $fuente('webp', 'image/webp')
            . sprintf(
                '<img src="%s" alt="%s" width="%d" height="%d" class="%s" %s>',
                $e(self::activo('/img/' . $archivo)),
                $e($alt),
                $ancho,
                $alto,
                $e($clases),
                $carga,
            )
            . '</picture>';
    }
}