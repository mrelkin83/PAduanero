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
}
