<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\ConfigMysql;
use App\Servicios\Landing;
use App\Servicios\Seo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class LandingTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private Landing $landing;
    private ConfigMysql $config;
    private string $cache;
    private string $sentinela;
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $this->cache = sys_get_temp_dir() . "/pedro-landing-{$sufijo}.html";
        $this->sentinela = sys_get_temp_dir() . "/pedro-landing-{$sufijo}.sentinel";
        $this->css = dirname(__DIR__, 2) . '/public/css/app.css';

        $this->config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pedro-sent-{$sufijo}",
            sys_get_temp_dir() . "/pedro-cfg-{$sufijo}.json",
        );

        $this->landing = $this->construir();
    }

    protected function tearDown(): void
    {
        @unlink($this->cache);
        @unlink($this->sentinela);
        parent::tearDown();
    }

    private function construir(): Landing
    {
        return new Landing(
            $this->bd,
            $this->config,
            new Seo($this->bd, $this->config, self::URL),
            self::URL,
            $this->cache,
            $this->sentinela,
            $this->css,
        );
    }

    #[Test]
    public function pintaLosCincoBloquesSembrados(): void
    {
        $html = $this->landing->render();

        self::assertStringContainsString('¿La DIAN le aprehendió', $html);
        self::assertStringContainsString('Situaciones que atiende', $html);
        self::assertStringContainsString('Especialización, no generalidad', $html);
        self::assertStringContainsString('Cómo funciona', $html);
        self::assertStringContainsString('Entre más temprano, más opciones', $html);
    }

    #[Test]
    public function elBotonLlevaElNumeroYElMensajeConfigurados(): void
    {
        $html = $this->landing->render();

        self::assertStringContainsString('https://wa.me/573159923676', $html);
        self::assertStringContainsString(rawurlencode('Hola, necesito asesoría jurídica'), $html);
    }

    #[Test]
    public function elEnlaceDeWhatsappFuncionaSinJavascript(): void
    {
        // El JS añade los UTM, pero si no corre el enlace tiene que servir
        // igual: la analítica nunca puede estorbar a la conversión.
        $html = $this->landing->render();

        self::assertMatchesRegularExpression(
            '#<a href="https://wa\.me/573159923676\?text=[^"]+" class="boton-wa js-wa#u',
            $html,
        );
    }

    #[Test]
    public function conCacheCalienteNoSeConsultaLaBase(): void
    {
        $this->landing->htmlCacheado();          // calienta

        // Se borran los bloques: si la segunda llamada consultara MySQL,
        // devolvería una página vacía. Es la forma directa de comprobar la
        // promesa de docs/PANEL_ADMIN.md §5.
        $this->bd->pdo()->exec('DELETE FROM landing_bloques');

        self::assertStringContainsString(
            'Situaciones que atiende',
            $this->construir()->htmlCacheado(),
        );
    }

    #[Test]
    public function invalidarLaCacheObligaARegenerar(): void
    {
        $this->landing->htmlCacheado();
        $this->bd->pdo()->exec("UPDATE landing_bloques SET titulo = 'Titular nuevo' WHERE clave = 'hero'");

        $this->landing->invalidarCache();

        self::assertStringContainsString('Titular nuevo', $this->construir()->htmlCacheado());
    }

    #[Test]
    public function elCentinelaInvalidaLaCacheDeOtroProceso(): void
    {
        $this->landing->htmlCacheado();
        $this->bd->pdo()->exec("UPDATE landing_bloques SET titulo = 'Cambiado desde el panel' WHERE clave = 'hero'");

        // El panel de la Etapa 3 correrá en otro proceso de PHP-FPM: lo único
        // que los sincroniza es el centinela. `touch` tiene resolución de
        // segundo, de ahí la espera.
        sleep(1);
        touch($this->sentinela);

        self::assertStringContainsString('Cambiado desde el panel', $this->construir()->htmlCacheado());
    }

    #[Test]
    public function recompilarElCssTambienInvalidaLaCache(): void
    {
        // El CSS va incrustado en el HTML cacheado, así que un
        // `npm run build:css` tiene que verse sin esperar al TTL.
        $this->landing->htmlCacheado();
        $this->bd->pdo()->exec("UPDATE landing_bloques SET titulo = 'Tras recompilar' WHERE clave = 'hero'");

        sleep(1);
        touch($this->css);

        self::assertStringContainsString('Tras recompilar', $this->construir()->htmlCacheado());
    }

    #[Test]
    public function elWidgetDeChatwootNoSeEmiteSinTokenConfigurado(): void
    {
        // Chatwoot se despliega en la Etapa 2. Cargar su SDK antes costaría
        // una petición fallida en cada visita.
        $html = $this->landing->render();

        self::assertStringNotContainsString('chatwootSDK', $html);
        self::assertStringNotContainsString('sdk.js', $html);
    }

    #[Test]
    public function elWidgetAparaceCuandoSeConfigura(): void
    {
        $this->config->set('chatwoot_widget_token', 'tok_abc123', 'u');
        $this->config->set('chatwoot_widget_url', 'https://chat.ejemplo.com', 'u');

        $html = $this->construir()->render();

        self::assertStringContainsString('chatwootSDK', $html);
        self::assertStringContainsString('tok_abc123', $html);
    }

    #[Test]
    public function unBloqueOcultoNoSePinta(): void
    {
        $this->bd->pdo()->exec("UPDATE landing_bloques SET visible = 0 WHERE clave = 'credenciales'");

        self::assertStringNotContainsString(
            'Especialización, no generalidad',
            $this->construir()->render(),
        );
    }

    #[Test]
    public function elContenidoDeLosBloquesVaEscapado(): void
    {
        // Los bloques los edita un usuario del panel: sin escape, esto es un
        // XSS almacenado servido a todo el tráfico de la landing.
        $this->bd->pdo()->exec(
            "UPDATE landing_bloques SET titulo = '<script>alert(1)</script>' WHERE clave = 'hero'"
        );

        $html = $this->construir()->render();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function laPaginaEmiteNoindexCuandoNoEsIndexable(): void
    {
        $this->config->set('landing_indexable', false, 'u');

        self::assertStringContainsString('noindex', $this->construir()->render());
    }

    // ── Confianza y testimonios ──────────────────────────────────────────
    //
    // Estas cinco prueban una sola idea: que la página **no pueda afirmar
    // sobre sí misma nada que no sea cierto**. Es la sección que existe para
    // desmentir el miedo a una estafa, así que un dato inventado ahí hace más
    // daño que en cualquier otro sitio del proyecto.

    /** @param array<string,mixed> $contenido */
    private function ponerBloque(string $clave, array $contenido): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE landing_bloques SET contenido = ? WHERE clave = ?')
            ->execute([json_encode($contenido, JSON_UNESCAPED_UNICODE), $clave]);
    }

    #[Test]
    public function laSeccionDeConfianzaNoSePintaSinDatosComprobables(): void
    {
        // Vaciar es explícito porque la migración 0015 dejó relleno sembrado:
        // lo que se prueba aquí es el estado sin NADA, ni real ni provisional.
        // Media sección, con rayas donde deberían ir los datos, confirma la
        // sospecha en vez de desmentirla.
        $this->ponerBloque('confianza', ['verificables' => [], 'sedes' => []]);

        self::assertStringNotContainsString('id="confianza"', $this->construir()->render());
    }

    #[Test]
    public function elRellenoProvisionalSeVeComoRellenoYNoComoDato(): void
    {
        // El relleno se sembró para poder ver la sección antes de tener los
        // datos. La condición para que eso sea inofensivo es esta: que no
        // pueda leerse como un dato verdadero. Un número de tarjeta
        // profesional inventado con aspecto real, en la página de un abogado,
        // no es un pendiente — es una constancia falsa.
        $this->ponerBloque('confianza', [
            'verificables' => [[
                'etiqueta' => 'Tarjeta profesional',
                'valor' => 'Pendiente de cargar',
                'pendiente' => true,
                'enlace_texto' => 'Registro Nacional de Abogados',
                'url' => 'https://ejemplo.invalido/',
            ]],
            'sedes' => [],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('id="confianza"', $html, 'debe verse para poder trabajarla');
        self::assertStringContainsString('marca-pendiente', $html, 'y verse marcada');

        // Sin enlace: mandar a alguien a un registro donde no hay nada que
        // consultar es peor que no ofrecerle el camino.
        self::assertStringNotContainsString('https://ejemplo.invalido/', $html);
    }

    #[Test]
    public function laSeccionDeConfianzaAparaceEnCuantoHayUnDatoReal(): void
    {
        $this->ponerBloque('confianza', [
            'verificables' => [
                ['etiqueta' => 'Tarjeta profesional', 'valor' => '000000', 'nota' => '', 'url' => ''],
            ],
            'sedes' => [],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('id="confianza"', $html);
        self::assertStringContainsString('000000', $html);
    }

    #[Test]
    public function laInvitacionAVisitarNoSaleSinDireccionADondeIr(): void
    {
        // «Puede venir a comprobar que existimos» sin dirección es justo la
        // clase de promesa vacía que esta sección vino a desmentir.
        $this->ponerBloque('confianza', [
            'verificables' => [['etiqueta' => 'NIT', 'valor' => '900000000-0']],
            'sedes' => [['nombre' => 'Zona Franca de Bogotá', 'direccion' => '']],
            'invitacion' => 'Puede venir a comprobar que existimos.',
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('id="confianza"', $html, 'el NIT sí debe pintarse');
        self::assertStringNotContainsString('Puede venir a comprobar', $html);
    }

    #[Test]
    public function unTestimonioSinAutorizacionNoSePublica(): void
    {
        // La puerta que impide que una cita llegue a producción sin permiso
        // escrito del cliente. Publicarla revelaría que esa empresa tuvo un
        // problema con la DIAN — información suya, no del despacho.
        $this->ponerBloque('testimonios', [
            'items' => [
                [
                    'texto' => 'Contestó el mismo día y explicó todo en español.',
                    'autor' => 'Nombre Apellido',
                    'empresa' => 'Importadora Ejemplo',
                    // sin `autorizado`
                ],
                [
                    'texto' => 'Dijo desde el principio qué no se podía hacer.',
                    'autor' => 'Otro Nombre',
                    'autorizado' => 'si',   // texto, no booleano: no cuenta
                ],
            ],
        ]);

        $html = $this->construir()->render();

        self::assertStringNotContainsString('id="testimonios"', $html);
        self::assertStringNotContainsString('Contestó el mismo día', $html);
        self::assertStringNotContainsString('Dijo desde el principio', $html);
    }

    #[Test]
    public function elMenuNoOfreceUnaSeccionQueNoSePinto(): void
    {
        // Un enlace que no lleva a ninguna parte, justo en la página cuyo
        // trabajo es demostrar que no es una fachada. El menú se deriva del
        // cuerpo ya compuesto para que esto no pueda ocurrir con ningún
        // bloque, ni con los que se añadan después.
        $this->ponerBloque('confianza', ['verificables' => [], 'sedes' => []]);

        $html = $this->construir()->render();

        self::assertStringNotContainsString('href="#confianza"', $html);
        self::assertStringContainsString('href="#situaciones"', $html);

        $this->ponerBloque('confianza', [
            'verificables' => [['etiqueta' => 'NIT', 'valor' => '900000000-0']],
            'sedes' => [],
        ]);

        self::assertStringContainsString('href="#confianza"', $this->construir()->render());
    }

    #[Test]
    public function unaDireccionDeRellenoNoInvitaAVisitarNiSaleEnElMarcado(): void
    {
        // Los dos sitios donde el relleno sí haría daño. «Puede venir a
        // comprobar que existimos» bajo un renglón que dice «dirección
        // pendiente» es la única frase del bloque que alguien podría llegar a
        // intentar; y `PostalAddress` no tiene forma de decir «esto todavía
        // no es cierto» — es una afirmación a secas.
        $this->ponerBloque('confianza', [
            'verificables' => [],
            'sedes' => [[
                'nombre' => 'Zona Franca de Bogotá',
                'direccion' => 'Dirección pendiente de cargar',
                'pendiente' => true,
            ]],
            'invitacion' => 'Puede venir a comprobar que existimos.',
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('Dirección pendiente', $html, 'la sede sí se ve');
        self::assertStringNotContainsString('Puede venir a comprobar', $html);
        self::assertStringNotContainsString('PostalAddress', $html);
    }

    #[Test]
    public function unTestimonioDeEjemploSePintaMarcadoYSinAutorizacion(): void
    {
        // El relleno se salta la puerta del `autorizado` y puede hacerlo sin
        // abrirla para nadie más: lo que esa puerta protege es el secreto
        // profesional de alguien que existe, y detrás de un ejemplo no hay
        // nadie. Lo que no puede es parecer una reseña real.
        $this->ponerBloque('testimonios', [
            'items' => [[
                'texto' => 'Contestó el mismo día.',
                'autor' => 'Nombre pendiente',
                'pendiente' => true,
                // sin `autorizado`
            ]],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('id="testimonios"', $html);
        self::assertStringContainsString('Ejemplo · sin publicar', $html);
    }

    #[Test]
    public function elLogoDeUnTestimonioAutorizadoEnlazaASuUrl(): void
    {
        // El logo es un botón hacia el sitio de la empresa (0029): con
        // testimonio autorizado y url presentes, tiene que ser un enlace de
        // verdad, no un adorno.
        $this->ponerBloque('testimonios', [
            'items' => [[
                'texto' => 'Contestó el mismo día y explicó todo en español.',
                'autor' => 'Nombre Apellido',
                'empresa' => 'Importadora Ejemplo',
                'autorizado' => true,
                'logo' => 'importadora-ejemplo.png',
                'url' => 'https://importadora-ejemplo.com',
            ]],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString(
            '<a href="https://importadora-ejemplo.com"',
            $html,
        );
        self::assertStringContainsString('src="/img/importadora-ejemplo.png"', $html);
    }

    #[Test]
    public function elLogoSinUrlSePintaSinEnlace(): void
    {
        // «Un botón que no lleva a ninguna parte es peor que no ponerlo»
        // (0029): sin url el logo se pinta, pero no dentro de un <a>.
        $this->ponerBloque('testimonios', [
            'items' => [[
                'texto' => 'Contestó el mismo día y explicó todo en español.',
                'autor' => 'Nombre Apellido',
                'empresa' => 'Importadora Ejemplo',
                'autorizado' => true,
                'logo' => 'importadora-ejemplo.png',
                // sin `url`
            ]],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('src="/img/importadora-ejemplo.png"', $html);
        self::assertDoesNotMatchRegularExpression(
            '#<a[^>]*>\s*<img[^>]*src="/img/importadora-ejemplo\.png"#',
            $html,
        );
    }

    #[Test]
    public function elLogoDeUnEjemploNuncaEnlazaAunqueTengaUrl(): void
    {
        // La misma regla que protege los testimonios de relleno: un ejemplo
        // no puede parecer una reseña real, y un logo enlazado sí lo
        // parecería.
        $this->ponerBloque('testimonios', [
            'items' => [[
                'texto' => 'Contestó el mismo día.',
                'autor' => 'Nombre pendiente',
                'pendiente' => true,
                'logo' => 'ejemplo.png',
                'url' => 'https://no-deberia-salir.example',
                // sin `autorizado`
            ]],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('src="/img/ejemplo.png"', $html);
        self::assertStringNotContainsString('no-deberia-salir.example', $html);
    }

    #[Test]
    public function elNombreDeArchivoDelLogoSeSaneaConBasename(): void
    {
        // Defensa contra traversal: si el campo `logo` trae una ruta con
        // directorios, solo el nombre de archivo llega al `src`.
        $this->ponerBloque('testimonios', [
            'items' => [[
                'texto' => 'Contestó el mismo día y explicó todo en español.',
                'autor' => 'Nombre Apellido',
                'autorizado' => true,
                'logo' => '../../etc/passwd',
            ]],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('src="/img/passwd"', $html);
        self::assertStringNotContainsString('../', $html);
    }

    // ── El pie (0019) ────────────────────────────────────────────────────
    //
    // El contacto del pie sale del bloque `pie` y sigue la regla de 0014:
    // lo que no tiene dato se omite en silencio, sin dejar huecos.

    #[Test]
    public function elPiePintaElContactoDelBloque(): void
    {
        $this->ponerBloque('pie', [
            'correo' => 'info@pedroabogadoaduanero.com',
            'telefonos' => [
                ['numero' => '+57 601 555 5555', 'icono' => 'telefono'],
                ['numero' => '+57 601 555 5556', 'icono' => 'whatsapp'],
                ['numero' => '', 'icono' => 'telefono'],
            ],
            'redes' => [
                ['nombre' => 'LinkedIn', 'url' => 'https://www.linkedin.com/in/ejemplo'],
            ],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('mailto:info@pedroabogadoaduanero.com', $html);
        self::assertStringContainsString('tel:+576015555555', $html);
        self::assertStringContainsString('tel:+576015555556', $html);
        self::assertStringContainsString('https://www.linkedin.com/in/ejemplo', $html);
    }

    #[Test]
    public function elPiePintaUnIconoPorTipoDeContacto(): void
    {
        // Cada categoría trae su glifo (App\Soporte\Iconos): la dirección
        // uno fijo, el teléfono el que el panel eligió por número, y la red
        // el que se deduce de su nombre — sin campo nuevo para eso último.
        $this->ponerBloque('pie', [
            'correo' => 'info@pedroabogadoaduanero.com',
            'direccion' => 'Bogotá, Colombia',
            'telefonos' => [
                ['numero' => '+57 601 555 5555', 'icono' => 'telefono'],
                ['numero' => '+57 300 123 4567', 'icono' => 'whatsapp'],
                ['numero' => '', 'icono' => 'telefono'],
            ],
            'redes' => [
                ['nombre' => 'Instagram', 'url' => 'https://instagram.com/ejemplo'],
            ],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('viewBox="0 0 384 512"', $html); // ubicación
        self::assertStringContainsString('viewBox="0 0 512 512"', $html); // correo + teléfono comparten viewBox
        self::assertStringContainsString('viewBox="0 0 448 512"', $html); // whatsapp / instagram
    }

    #[Test]
    public function unaRedSinUrlNoDejaUnEnlaceHueco(): void
    {
        // La semilla trae los nombres usuales con la URL vacía para que el
        // panel tenga de dónde clonar. Ninguno puede pintarse como enlace
        // muerto, y una URL que no sea https tampoco entra: ese campo lo
        // escribe una persona y `javascript:` también es una URL.
        $this->ponerBloque('pie', [
            'correo' => '',
            'telefonos' => ['', '', ''],
            'redes' => [
                ['nombre' => 'LinkedIn', 'url' => ''],
                ['nombre' => 'Instagram', 'url' => 'javascript:alert(1)'],
            ],
        ]);

        $html = $this->construir()->render();

        self::assertStringNotContainsString('>Contacto<', $html);
        self::assertStringNotContainsString('javascript:alert(1)', $html);
    }

    #[Test]
    public function elEnlaceDeCursosSoloApareceConElInterruptorEncendido(): void
    {
        self::assertStringNotContainsString('href="/cursos"', $this->landing->render());

        $this->config->set('cursos_activo', true, 'tester');

        self::assertStringContainsString('href="/cursos"', $this->landing->render());
    }

    #[Test]
    public function unTestimonioAnonimoNoSePublicaAunqueEsteAutorizado(): void
    {
        // Sin nombre no distingue a este despacho de uno inventado, que es
        // exactamente el problema que la sección vino a resolver.
        $this->ponerBloque('testimonios', [
            'items' => [
                ['texto' => 'Muy buen trato.', 'autor' => '', 'autorizado' => true],
                ['texto' => 'Contestó el mismo día.', 'autor' => 'Nombre Real', 'autorizado' => true],
            ],
        ]);

        $html = $this->construir()->render();

        self::assertStringContainsString('id="testimonios"', $html);
        self::assertStringContainsString('Contestó el mismo día', $html);
        self::assertStringNotContainsString('Muy buen trato', $html);
    }
}
