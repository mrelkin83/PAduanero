/**
 * El diagnóstico de `/perfil`.
 *
 * La página llega del servidor con los seis pasos y las dos ramas ya
 * escritas, como un formulario de radios corriente. Este archivo hace tres
 * cosas y ninguna más: esconde lo que no toca, anima el paso de uno a otro,
 * y al final compone el mensaje de WhatsApp. Si falla, el CSS deja de
 * esconder nada y la página sigue siendo un documento legible que termina
 * en el botón verde de siempre.
 *
 * ── Sobre el movimiento ────────────────────────────────────────────────
 *
 * Los muelles están escritos a mano. No es purismo: este proyecto no tiene
 * una sola dependencia de front y la página entera cabe en 300 KB, presupuesto
 * que `bin/auditar-landing.mjs` mide en cada cierre de etapa. Traer una
 * librería de animación costaría más que todo lo demás junto.
 *
 * Lo que se toma prestado del oficio de Apple es el comportamiento:
 *
 *  · **Respuesta antes que animación.** La opción se marca en el
 *    `pointerdown` (lo hace el CSS con `:active`), no al soltar.
 *  · **Muelles, no duraciones.** Amortiguación crítica (1.0) para todo, que
 *    es lo que corresponde a una interfaz que no se lanza: aquí nadie
 *    arroja nada, se elige. El rebote se reserva para el único gesto con
 *    inercia, el arrastre hacia atrás.
 *  · **El movimiento apunta a dónde va.** La barra de progreso avanza
 *    DURANTE la transición y no al terminarla.
 *  · **Rutas simétricas.** Si se entró por la derecha, se sale por la
 *    derecha.
 *  · **Velocidad, no posición, decide.** Al soltar el arrastre se proyecta
 *    dónde acabaría el movimiento y se decide con eso, igual que hace la
 *    deceleración del scroll.
 *  · **`prefers-reduced-motion` no significa «sin respuesta»**, significa
 *    un equivalente que no marea: aquí, cambio directo sin desplazamiento.
 *
 * Lo que NO se toma: la piel. Nada de traslucidez, profundidad ni esquinas
 * redondeadas. El sistema visual de esta marca es tinta sobre papel de
 * oficio y una casilla de formulario tiene el borde recto.
 */
(function () {
    'use strict';

    var form = document.getElementById('diagnostico');
    if (!form) { return; }

    var pasos = Array.prototype.slice.call(form.querySelectorAll('.paso'));
    if (pasos.length === 0) { return; }

    var barra = document.getElementById('progreso-barra');
    var contador = document.getElementById('contador');
    var anuncio = document.getElementById('anuncio');

    var salidas = {
        resultado: document.getElementById('salida-resultado'),
        urgente: document.getElementById('salida-urgente'),
        fuera_alcance: document.getElementById('salida-fuera')
    };

    var DESPLAZAMIENTO = 28;   // px que recorre un paso al entrar o salir
    var UMBRAL_GESTO = 10;     // px antes de decidir que es un arrastre y no un toque

    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

    // ── Registro de eventos ─────────────────────────────────────────────
    //
    // Se apoya en landing.js (window.PA) en vez de duplicar sessionStorage,
    // el identificador de sesión y sendBeacon. Se comprueba que exista: el
    // orden de dos scripts diferidos lo garantiza el navegador, no nosotros.
    function registrar(tipo, ruta) {
        if (window.PA && typeof window.PA.registrar === 'function') {
            window.PA.registrar(tipo, ruta);
        }
    }

    function referencia() {
        return (window.PA && typeof window.PA.referencia === 'function')
            ? window.PA.referencia()
            : '';
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Muelles
    // ═══════════════════════════════════════════════════════════════════
    //
    // Integración semi-implícita de Euler en subpasos fijos de 4 ms. El
    // subpaso fijo es lo que impide que el muelle explote cuando el
    // navegador entrega un cuadro tardío —al volver de otra pestaña llegan
    // saltos de medio segundo— y lo que hace que el movimiento se vea igual
    // a 60 y a 120 Hz.
    //
    // `respuesta` es tiempo en segundos hasta alcanzar el objetivo, no
    // duración: un muelle no tiene duración. `amortiguacion` 1.0 es crítica
    // (llega y para); por debajo, rebota.
    var vivos = [];
    var ultimoCuadro = 0;
    var corriendo = false;

    function Muelle(inicial, respuesta, amortiguacion, aplicar) {
        this.x = inicial;
        this.v = 0;
        this.objetivo = inicial;
        this.omega = (2 * Math.PI) / respuesta;
        this.zeta = amortiguacion;
        this.aplicar = aplicar;
        this.activo = false;
        this.alParar = null;
    }

    /**
     * Retargeting: NO se reinicia la posición ni la velocidad.
     *
     * Es lo que permite interrumpir. El muelle sigue desde donde está y con
     * la velocidad que lleva, así que cambiar de objetivo a mitad de camino
     * no produce el salto que produciría reiniciar desde el valor lógico.
     */
    Muelle.prototype.hacia = function (objetivo, velocidadInicial) {
        this.objetivo = objetivo;

        if (typeof velocidadInicial === 'number') {
            this.v = velocidadInicial;
        }

        if (!this.activo) {
            this.activo = true;
            vivos.push(this);
            arrancar();
        }
    };

    /** Salta al objetivo sin animar. `reduced-motion` y las interrupciones. */
    Muelle.prototype.fijar = function (valor) {
        this.x = valor;
        this.objetivo = valor;
        this.v = 0;
        this.aplicar(valor);
    };

    Muelle.prototype.paso = function (dt) {
        var restante = dt;

        while (restante > 0) {
            var h = Math.min(0.004, restante);
            restante -= h;

            var fuerza = -this.omega * this.omega * (this.x - this.objetivo);
            var freno = -2 * this.zeta * this.omega * this.v;

            this.v += (fuerza + freno) * h;
            this.x += this.v * h;
        }

        // Asentado: la distancia y la velocidad son imperceptibles. Sin este
        // corte el muelle nunca termina del todo y el rAF no para nunca.
        if (Math.abs(this.x - this.objetivo) < 0.0005 && Math.abs(this.v) < 0.0005) {
            this.x = this.objetivo;
            this.v = 0;
            this.activo = false;
        }

        this.aplicar(this.x);

        if (!this.activo && this.alParar) {
            var fin = this.alParar;
            this.alParar = null;
            fin();
        }
    };

    function arrancar() {
        if (corriendo) { return; }
        corriendo = true;
        ultimoCuadro = 0;
        requestAnimationFrame(cuadro);
    }

    function cuadro(ahora) {
        var dt = ultimoCuadro ? (ahora - ultimoCuadro) / 1000 : 1 / 60;
        ultimoCuadro = ahora;

        // Tope duro: al volver de otra pestaña el delta puede ser de varios
        // segundos, y sin esto el muelle recorrería su trayecto entero en
        // un cuadro — que se ve como un salto, no como un movimiento.
        dt = Math.min(dt, 1 / 20);

        for (var i = vivos.length - 1; i >= 0; i--) {
            vivos[i].paso(dt);

            if (!vivos[i].activo) {
                vivos.splice(i, 1);
            }
        }

        if (vivos.length > 0) {
            requestAnimationFrame(cuadro);
            return;
        }

        corriendo = false;
    }

    // ── Los tres muelles de la página ───────────────────────────────────
    //
    // Respuesta 0.4 para el progreso (acompaña) y 0.34 para el paso y el
    // alto (mandan). Amortiguación crítica en los tres: nada de esto se
    // lanza con un gesto, así que un rebote se leería como capricho.
    var mProgreso = new Muelle(0, 0.4, 1.0, function (v) {
        if (barra) { barra.style.setProperty('--avance', v.toFixed(4)); }
    });

    var mAlto = new Muelle(0, 0.34, 1.0, function (v) {
        form.style.height = v > 0 ? v.toFixed(1) + 'px' : '';
    });

    // Progreso normalizado de la transición en curso, de 0 a 1.
    var transicion = null;
    var mPaso = new Muelle(1, 0.34, 1.0, function (t) {
        if (!transicion) { return; }

        var d = transicion.direccion;

        // `desde` es dónde estaba el paso saliente al empezar. Vale cero en
        // una transición por clic y vale el arrastre acumulado cuando el
        // gesto acaba de decidir que se retrocede: sin esto el paso salta de
        // donde lo dejó el dedo a cero antes de empezar a salir, que es
        // justo la costura que separa «fluido» de «correcto».
        pintar(transicion.saliente, transicion.desde + (-t * d * DESPLAZAMIENTO), 1 - t);
        pintar(transicion.entrante, (1 - t) * d * DESPLAZAMIENTO, t);
    });

    function pintar(el, x, opacidad) {
        el.style.transform = 'translate3d(' + x.toFixed(2) + 'px,0,0)';
        el.style.opacity = opacidad.toFixed(3);
    }

    function limpiar(el) {
        el.style.transform = '';
        el.style.opacity = '';
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Estado del cuestionario
    // ═══════════════════════════════════════════════════════════════════
    var rama = '';
    var indice = 0;
    var terminado = false;

    /** Los pasos que le tocan a la rama elegida, en orden. */
    function visibles() {
        return pasos.filter(function (p) {
            var r = p.dataset.rama;
            return !r || r === rama;
        });
    }

    /**
     * Cuántos pasos tiene la rama.
     *
     * Antes de elegir rama se usa la más larga: decir «paso 1 de 4» y que
     * al contestar se convierta en «de 6» se lee como que el formulario
     * creció mientras lo llenaba.
     */
    function largo() {
        if (rama) { return visibles().length; }

        return Math.max(
            parseInt(form.dataset.largoAduanero, 10) || 0,
            parseInt(form.dataset.largoTributario, 10) || 0
        );
    }

    function elegida(paso) {
        return paso.querySelector('input:checked');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Transición
    // ═══════════════════════════════════════════════════════════════════
    /**
     * @param {number} destino  índice dentro de visibles()
     * @param {number} direccion  +1 hacia adelante, −1 hacia atrás
     */
    function ir(destino, direccion) {
        var lista = visibles();
        destino = Math.max(0, Math.min(lista.length - 1, destino));

        var saliente = lista[indice];
        var entrante = lista[destino];

        // De dónde arranca el paso que sale. Casi siempre cero; distinto de
        // cero solo cuando venimos de un arrastre.
        var desde = arrastreActual;
        arrastreActual = 0;

        if (entrante === saliente) {
            devolver();
            return;
        }

        // Una transición en vuelo se cierra antes de abrir la siguiente. El
        // caso real es pulsar dos opciones muy seguidas; dejar tres pasos
        // encadenados a la vez no mejora nada de lo que se ve y multiplica
        // los estados en los que este archivo puede quedarse.
        cerrarTransicion();

        var altoAntes = form.offsetHeight;

        saliente.removeAttribute('data-activo');
        saliente.setAttribute('data-saliendo', '1');

        entrante.setAttribute('data-activo', '1');
        indice = destino;

        actualizarPie(entrante);
        anunciar(entrante);

        var altoDespues = medirAlto();

        transicion = {
            saliente: saliente,
            entrante: entrante,
            direccion: direccion,
            desde: desde
        };

        // El progreso arranca AHORA, no al terminar: el movimiento tiene que
        // apuntar a dónde va antes de llegar.
        mProgreso.hacia((indice + 1) / largo());
        actualizarContador();

        if (reduce.matches) {
            // Sin desplazamiento y sin muelle. La respuesta sigue estando —
            // el paso cambia y el progreso avanza— pero nada se mueve por la
            // pantalla, que es lo que pide la preferencia.
            mProgreso.fijar((indice + 1) / largo());
            cerrarTransicion();
            enfocar(entrante);
            return;
        }

        form.style.overflow = 'hidden';
        mAlto.fijar(altoAntes);
        mAlto.hacia(altoDespues);

        mPaso.x = 0;
        mPaso.v = 0;
        mPaso.aplicar(0);
        mPaso.alParar = function () {
            cerrarTransicion();
            enfocar(entrante);
        };
        mPaso.hacia(1);
    }

    /** Alto que tendrá el formulario con el paso entrante ya montado. */
    function medirAlto() {
        var previo = form.style.height;
        form.style.height = 'auto';
        var alto = form.offsetHeight;
        form.style.height = previo;

        return alto;
    }

    function cerrarTransicion() {
        if (!transicion) { return; }

        transicion.saliente.removeAttribute('data-saliendo');
        limpiar(transicion.saliente);
        limpiar(transicion.entrante);

        transicion = null;

        mPaso.alParar = null;
        mAlto.fijar(0);
        form.style.overflow = '';
        form.style.height = '';
    }

    function enfocar(paso) {
        var titulo = paso.querySelector('h2[tabindex]');
        if (titulo) { titulo.focus({ preventScroll: true }); }
    }

    function anunciar(paso) {
        if (!anuncio) { return; }

        var titulo = paso.querySelector('h2');
        anuncio.textContent = 'Paso ' + (indice + 1) + ' de ' + largo()
            + '. ' + (titulo ? titulo.textContent.trim() : '');
    }

    function actualizarContador() {
        if (contador) {
            contador.textContent = 'Paso ' + (indice + 1) + ' de ' + largo();
        }
    }

    function actualizarPie(paso) {
        var atras = paso.querySelector('.js-atras');
        var seguir = paso.querySelector('.js-continuar');

        if (atras) { atras.disabled = indice === 0; }
        if (seguir) { seguir.disabled = !elegida(paso); }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Avance
    // ═══════════════════════════════════════════════════════════════════
    function avanzar(paso) {
        var input = elegida(paso);
        if (!input) { return; }

        registrar('perfil_paso', '/perfil/' + paso.dataset.paso);

        // El paso 1 fija la rama, y cambiarla invalida lo contestado en la
        // otra: no se puede llegar al resultado con la mitad de las
        // respuestas de aduanero y la mitad de tributario.
        if (input.dataset.rama !== undefined) {
            if (rama && rama !== input.dataset.rama) { limpiarDesde(1); }
            rama = input.dataset.rama;
        }

        var salida = input.dataset.salida;
        if (salida) {
            mostrarSalida(salida);
            return;
        }

        var lista = visibles();

        if (indice >= lista.length - 1) {
            mostrarSalida('resultado');
            return;
        }

        ir(indice + 1, 1);
    }

    function retroceder() {
        if (indice === 0) { devolver(); return; }
        ir(indice - 1, -1);
    }

    function limpiarDesde(desde) {
        pasos.forEach(function (paso, i) {
            if (i < desde) { return; }

            var marcado = elegida(paso);
            if (marcado) { marcado.checked = false; }
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Salidas
    // ═══════════════════════════════════════════════════════════════════
    function mostrarSalida(clave) {
        var seccion = salidas[clave];
        if (!seccion) { return; }

        terminado = true;

        if (clave === 'resultado') { componerResultado(); }
        if (clave === 'urgente') { componerUrgente(); }

        form.hidden = true;
        Object.keys(salidas).forEach(function (k) {
            if (salidas[k]) { salidas[k].hidden = k !== clave; }
        });

        mProgreso.hacia(1);
        if (contador) { contador.textContent = 'Listo'; }

        registrar('perfil_resultado', '/perfil/' + clave);

        var titulo = seccion.querySelector('h2[tabindex]');
        if (titulo) { titulo.focus({ preventScroll: true }); }

        seccion.scrollIntoView({
            behavior: reduce.matches ? 'auto' : 'smooth',
            block: 'start'
        });
    }

    function reiniciar() {
        terminado = false;

        Object.keys(salidas).forEach(function (k) {
            if (salidas[k] && k !== 'resultado') { salidas[k].hidden = true; }
        });

        // `#salida-resultado` no se oculta con `hidden` sino que se saca de
        // la vista: sin JavaScript es el cierre normal de la página, y esa
        // es la razón de que no lleve el atributo en el HTML.
        if (salidas.resultado) { salidas.resultado.hidden = true; }

        form.hidden = false;

        var lista = visibles();
        lista.forEach(function (p, i) {
            if (i === indice) { p.setAttribute('data-activo', '1'); }
            else { p.removeAttribute('data-activo'); }
        });

        actualizarPie(lista[indice]);
        actualizarContador();
        mProgreso.hacia((indice + 1) / largo());
        enfocar(lista[indice]);
    }

    // ── El mensaje ──────────────────────────────────────────────────────
    //
    // Se compone aquí y no en el servidor porque aquí es donde están las
    // respuestas: nada de lo que la persona contesta sale del navegador. El
    // texto lo lee ella en WhatsApp antes de enviarlo, así que va en
    // castellano corriente y no en los códigos del catálogo.
    function respuestas() {
        var salida = [];

        visibles().forEach(function (paso) {
            var input = elegida(paso);
            if (!input) { return; }

            salida.push({
                etiqueta: paso.dataset.resumen || '',
                texto: input.dataset.mensaje || '',
                tecnico: input.dataset.tecnico || ''
            });
        });

        return salida;
    }

    function mayuscula(texto) {
        return texto.charAt(0).toUpperCase() + texto.slice(1);
    }

    function componerResultado() {
        var datos = respuestas();

        // El nombre técnico de la situación. Se toma el último que aparezca:
        // el estado del proceso —«ya hay un recurso presentado»— describe
        // mejor dónde está el caso que el documento con el que empezó.
        var tecnico = '';
        datos.forEach(function (d) { if (d.tecnico) { tecnico = d.tecnico; } });

        var etiquetaTecnica = document.getElementById('resultado-tecnico');
        if (etiquetaTecnica) {
            etiquetaTecnica.textContent = tecnico;
            etiquetaTecnica.hidden = tecnico === '';
        }

        var dl = document.getElementById('resumen');
        if (dl) {
            dl.textContent = '';

            datos.forEach(function (d) {
                var fila = document.createElement('div');
                var dt = document.createElement('dt');
                var dd = document.createElement('dd');

                dt.textContent = d.etiqueta;
                dd.textContent = mayuscula(d.texto);

                fila.appendChild(dt);
                fila.appendChild(dd);
                dl.appendChild(fila);
            });
        }

        var lineas = datos.map(function (d) { return '· ' + d.etiqueta + ': ' + mayuscula(d.texto); });

        escribirEnlace(
            '.js-wa-resultado',
            'Hola. Hice el diagnóstico en su página y este es mi caso:\n\n'
            + lineas.join('\n')
            + '\n\nQuisiera agendar la asesoría.'
        );
    }

    /**
     * Salida crítica (regla 5).
     *
     * El mensaje NO lleva ninguna de las respuestas. No es una omisión por
     * brevedad: quien marca «operativo en curso» puede estar en medio de un
     * asunto penal, y lo que esta página componga acaba en el historial de
     * WhatsApp de dos teléfonos para siempre. El motivo es una constante
     * nuestra, no algo que la persona escribió aquí.
     */
    function componerUrgente() {
        escribirEnlace(
            '.js-wa-urgente',
            'Hola. Tengo un operativo en curso y necesito hablar con el abogado ahora.'
        );
    }

    /**
     * La referencia de campaña va al final y en línea aparte, con el mismo
     * formato que usa landing.js para los demás botones: la persona lee este
     * texto antes de enviarlo, así que tiene que parecer una nota al pie y
     * no un código de sistema. Nada más se anexa por eso mismo — el resumen
     * ya se explica solo, y un marcador de versión sería ruido en el mensaje
     * de alguien que acaba de perder su mercancía.
     */
    function escribirEnlace(selector, texto) {
        var enlace = document.querySelector(selector);
        if (!enlace) { return; }

        var ref = referencia();
        var cuerpo = ref ? texto + '\n\n[ref: ' + ref + ']' : texto;

        var url = new URL(enlace.href);
        url.searchParams.set('text', cuerpo);
        enlace.href = url.toString();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Entrada del usuario
    // ═══════════════════════════════════════════════════════════════════
    //
    // Se avanza con `click` y NO con `change`. Las flechas del teclado
    // cambian la selección sin emitir un clic, y avanzar con cada flecha
    // haría imposible comparar las opciones antes de decidir. Para el dedo
    // y el ratón, `click` llega igual.
    form.addEventListener('click', function (evento) {
        // `matches` y no `closest`: al tocar la etiqueta el navegador emite
        // DOS clics que suben hasta aquí —el de la etiqueta y el que
        // reenvía al radio—, y `closest('.opcion input')` desde un `<span>`
        // ni siquiera encuentra el input, que es su hermano y no su padre.
        // Filtrar por el objetivo exacto atiende las dos rutas, una vez.
        if (evento.target.matches && evento.target.matches('.opcion input')) {
            var paso = evento.target.closest('.paso');

            // Pequeña espera deliberada: es el tiempo que tarda en verse la
            // casilla marcarse. Sin ella la opción desaparece antes de que
            // el ojo confirme cuál se eligió, y el paso siguiente se lee
            // como si la página hubiera decidido sola.
            setTimeout(function () { avanzar(paso); }, 130);
            return;
        }

        if (evento.target.closest('.js-atras')) { retroceder(); return; }

        if (evento.target.closest('.js-continuar')) {
            avanzar(evento.target.closest('.paso'));
        }
    });

    // Con teclado la selección llega por `change`; el botón «Continuar» es
    // el único camino hacia adelante y tiene que habilitarse al elegir.
    form.addEventListener('change', function (evento) {
        var paso = evento.target.closest('.paso');
        if (paso) { actualizarPie(paso); }
    });

    document.addEventListener('click', function (evento) {
        if (evento.target.closest('.js-reiniciar')) { reiniciar(); }
    });

    // ── Arrastre hacia atrás ────────────────────────────────────────────
    //
    // El paso sigue al dedo 1:1 mientras se arrastra, resiste hacia adelante
    // —no hay adonde ir sin contestar— y al soltar decide por VELOCIDAD
    // PROYECTADA y no por posición: un tirón corto y rápido tiene que
    // funcionar igual que un arrastre largo y lento, que es como funciona
    // la inercia del scroll y por tanto lo que el dedo espera.
    //
    // No se monta el paso anterior debajo durante el arrastre. En una pila
    // de tarjetas eso sería lo correcto; aquí la página es un documento, no
    // hay pila, y revelar media pregunta anterior por el borde se leería
    // como un fallo de pintado.
    var gesto = null;

    // Desplazamiento actual del paso, en píxeles. Lo escribe el arrastre y
    // lo leen la vuelta y la transición; nunca se deduce del DOM.
    var arrastreActual = 0;

    form.style.touchAction = 'pan-y';

    form.addEventListener('pointerdown', function (evento) {
        if (terminado || indice === 0 || evento.button !== 0 || reduce.matches) { return; }

        gesto = {
            id: evento.pointerId,
            x0: evento.clientX,
            y0: evento.clientY,
            dx: 0,
            decidido: false,
            historia: [{ x: evento.clientX, t: evento.timeStamp }]
        };
    });

    form.addEventListener('pointermove', function (evento) {
        if (!gesto || evento.pointerId !== gesto.id) { return; }

        var dx = evento.clientX - gesto.x0;
        var dy = evento.clientY - gesto.y0;

        if (!gesto.decidido) {
            if (Math.abs(dx) < UMBRAL_GESTO && Math.abs(dy) < UMBRAL_GESTO) { return; }

            // Se decide una sola vez y en cuanto hay intención: si el
            // movimiento es más vertical que horizontal, la página scrollea
            // y este gesto se retira sin pelear.
            if (Math.abs(dy) > Math.abs(dx)) { gesto = null; return; }

            gesto.decidido = true;
            form.setPointerCapture(evento.pointerId);
        }

        // Historia corta: la velocidad al soltar se calcula sobre los
        // últimos ~80 ms, no sobre el gesto entero. Un arrastre que se
        // detiene antes de soltar tiene velocidad cero, y eso es justo lo
        // que la persona quiso decir.
        gesto.historia.push({ x: evento.clientX, t: evento.timeStamp });
        if (gesto.historia.length > 6) { gesto.historia.shift(); }

        gesto.dx = dx > 0 ? dx : gomaElastica(dx);
        arrastreActual = gesto.dx;

        var actual = visibles()[indice];
        actual.style.transform = 'translate3d(' + gesto.dx.toFixed(2) + 'px,0,0)';

        evento.preventDefault();
    });

    /**
     * Resistencia progresiva en el borde.
     *
     * Un tope duro se lee como «se congeló»; una resistencia que crece se
     * lee como «responde, pero por aquí no hay nada».
     */
    function gomaElastica(desborde) {
        var ancho = form.offsetWidth || 1;
        var k = 0.55;

        return (desborde * ancho * k) / (ancho + k * Math.abs(desborde));
    }

    function velocidad() {
        var h = gesto.historia;
        if (h.length < 2) { return 0; }

        var a = h[0];
        var b = h[h.length - 1];
        var dt = (b.t - a.t) / 1000;

        return dt > 0 ? (b.x - a.x) / dt : 0;   // px/s
    }

    /**
     * Dónde acabaría el movimiento si se dejara decelerar.
     *
     * Es la forma exponencial que usa el scroll de iOS, no la de
     * `v²/(2·a)`: con esta, un tirón rápido y corto proyecta lejos, que es
     * lo que hace que un gesto pequeño produzca un resultado grande.
     */
    function proyectar(v) {
        var d = 0.998;

        return (v / 1000) * d / (1 - d);
    }

    function soltar(evento) {
        if (!gesto || evento.pointerId !== gesto.id) { return; }

        var arrastre = gesto;
        gesto = null;

        if (!arrastre.decidido) { return; }

        if (form.hasPointerCapture(evento.pointerId)) {
            form.releasePointerCapture(evento.pointerId);
        }

        var v = velocidad();
        var proyectado = arrastre.dx + proyectar(v);

        // Un tercio del ancho, o la proyección de la velocidad. Cualquiera
        // de las dos basta: así funcionan el arrastre lento y el tirón.
        if (proyectado > form.offsetWidth / 3) {
            retroceder();
            return;
        }

        devolverCon(v);
    }

    form.addEventListener('pointerup', soltar);
    form.addEventListener('pointercancel', soltar);

    /**
     * Vuelve el paso a su sitio, heredando la velocidad del dedo.
     *
     * El punto de partida se toma de `arrastreActual` y NO se lee del
     * `style.transform`: quitarle a `translate3d(12.34px,0,0)` todo lo que
     * no es dígito deja `312.34` — el `3` de `translate3d` pegado delante—
     * y el paso arrancaría el rebote a 300 px de donde está.
     */
    function devolverCon(v) {
        var actual = visibles()[indice];
        var inicio = arrastreActual;
        arrastreActual = 0;

        var mVuelta = new Muelle(
            inicio,
            0.36,
            // El único sitio con rebote de toda la página, y solo porque
            // aquí SÍ hubo inercia: el dedo venía moviéndose. Un rebote sin
            // gesto previo se lee como capricho; con gesto previo, como
            // materia.
            0.8,
            function (x) { actual.style.transform = 'translate3d(' + x.toFixed(2) + 'px,0,0)'; }
        );

        mVuelta.alParar = function () { actual.style.transform = ''; };
        mVuelta.hacia(0, v);
    }

    function devolver() { devolverCon(0); }

    // ═══════════════════════════════════════════════════════════════════
    //  Arranque
    // ═══════════════════════════════════════════════════════════════════
    //
    // `#salida-resultado` se emite VISIBLE para que quien no tenga
    // JavaScript la vea como cierre de la página. Aquí, que sí lo hay, se
    // esconde hasta que haya algo que poner dentro.
    if (salidas.resultado) { salidas.resultado.hidden = true; }

    pasos.forEach(function (paso, i) {
        if (i === 0) { paso.setAttribute('data-activo', '1'); }
        else { paso.removeAttribute('data-activo'); }
    });

    actualizarPie(pasos[0]);
    actualizarContador();
    mProgreso.fijar(1 / largo());
})();
