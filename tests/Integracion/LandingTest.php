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
        // Es como nace de la migración 0014: sin tarjeta profesional, sin NIT
        // y sin dirección. Media sección, con rayas donde deberían ir los
        // datos, confirma la sospecha en vez de desmentirla.
        self::assertStringNotContainsString('id="confianza"', $this->construir()->render());
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
