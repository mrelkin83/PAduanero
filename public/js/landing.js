/**
 * Instrumentación de la landing. JavaScript vanilla, sin dependencias.
 *
 * Hace dos cosas:
 *   1. Arrastra los UTM al mensaje prellenado de WhatsApp, para poder
 *      atribuir cada caso a la campaña que lo trajo. Sin esto no hay forma
 *      de saber qué anuncio de Meta produce clientes que pagan, que es la
 *      métrica que decide dónde va el presupuesto.
 *   2. Registra vista, scroll_50 y click_whatsapp en `eventos_landing`.
 *
 * Todo lo que hace es prescindible a propósito: el enlace de WhatsApp ya
 * funciona sin JavaScript, y si este archivo falla la página sigue
 * convirtiendo. La analítica nunca puede estorbar a la conversión.
 */
(function () {
    'use strict';

    var CLAVES_UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];
    var ALMACEN = 'pa_atribucion';

    // ── Atribución ──────────────────────────────────────────────────────
    //
    // Los UTM se guardan en sessionStorage la primera vez. Si el visitante
    // navega a otra sección y vuelve, o recarga sin los parámetros, la
    // campaña se conserva: perderla en el segundo paso es como se pierde
    // la mitad de la atribución.
    function atribucion() {
        var parametros = new URLSearchParams(window.location.search);
        var actual = {};
        var traeUtm = false;

        CLAVES_UTM.forEach(function (clave) {
            var valor = parametros.get(clave);
            if (valor) {
                actual[clave] = valor.slice(0, 100);
                traeUtm = true;
            }
        });

        try {
            if (traeUtm) {
                sessionStorage.setItem(ALMACEN, JSON.stringify(actual));
                return actual;
            }
            var guardado = sessionStorage.getItem(ALMACEN);
            return guardado ? JSON.parse(guardado) : {};
        } catch (e) {
            // Modo incógnito o almacenamiento bloqueado. Sin drama: se
            // pierde la persistencia, no la página.
            return actual;
        }
    }

    function sesion() {
        try {
            var id = sessionStorage.getItem('pa_sesion');
            if (!id) {
                id = crypto.randomUUID().replace(/-/g, '');
                sessionStorage.setItem('pa_sesion', id);
            }
            return id;
        } catch (e) {
            return crypto.randomUUID().replace(/-/g, '');
        }
    }

    function dispositivo() {
        var ancho = window.innerWidth;
        if (ancho < 640) { return 'movil'; }
        return ancho < 1024 ? 'tablet' : 'escritorio';
    }

    var UTM = atribucion();
    var SESION = sesion();

    // ── Registro de eventos ─────────────────────────────────────────────
    function registrar(tipo) {
        var carga = {
            tipo: tipo,
            sesion: SESION,
            ruta: window.location.pathname,
            dispositivo: dispositivo()
        };

        CLAVES_UTM.forEach(function (clave) {
            if (UTM[clave]) { carga[clave] = UTM[clave]; }
        });

        var cuerpo = JSON.stringify(carga);

        // sendBeacon sobrevive a que la página se descargue, que es
        // exactamente lo que pasa al pulsar el botón de WhatsApp: con fetch
        // normal, el navegador cancela la petición al navegar y el clic —el
        // evento que más importa— se pierde.
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/api/evento', new Blob([cuerpo], { type: 'application/json' }));
            return;
        }

        fetch('/api/evento', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: cuerpo,
            keepalive: true
        }).catch(function () { /* la analítica nunca rompe la página */ });
    }

    // ── Enlaces de WhatsApp ─────────────────────────────────────────────
    //
    // El HTML sale de una caché compartida por todos los visitantes, así que
    // los UTM no pueden venir incrustados: se añaden aquí, en el navegador.
    function prepararEnlaces() {
        var referencia = UTM.utm_campaign || UTM.utm_source;
        if (!referencia) { return; }

        document.querySelectorAll('a.js-wa').forEach(function (enlace) {
            var url = new URL(enlace.href);
            var texto = url.searchParams.get('text') || '';

            // La referencia va al final y en una línea aparte: el mensaje lo
            // ve el cliente antes de enviarlo, así que tiene que leerse como
            // una nota al pie y no como ruido.
            url.searchParams.set('text', texto + '\n\n[ref: ' + referencia + ']');
            enlace.href = url.toString();
        });
    }

    prepararEnlaces();
    registrar('vista');

    document.addEventListener('click', function (evento) {
        var enlace = evento.target.closest('a[data-evento]');
        if (enlace) { registrar(enlace.dataset.evento); }
    });

    // ── Scroll al 50 % ──────────────────────────────────────────────────
    var marcado = false;
    function alDesplazar() {
        if (marcado) { return; }

        var alcanzado = window.scrollY + window.innerHeight;
        var total = document.documentElement.scrollHeight;

        if (total > 0 && alcanzado / total >= 0.5) {
            marcado = true;
            registrar('scroll_50');
            window.removeEventListener('scroll', alDesplazar);
        }
    }

    window.addEventListener('scroll', alDesplazar, { passive: true });
    alDesplazar();
})();
