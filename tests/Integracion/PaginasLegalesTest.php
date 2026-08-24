<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\ConfigMysql;
use App\Servicios\PaginaLegal;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * `/privacidad` y `/condiciones`.
 *
 * Lo que se defiende aquí no es el copy —ese lo aprueba Pedro— sino los
 * compromisos estructurales: que las páginas respondan, que la política diga
 * la verdad sobre lo que el sitio no guarda, que las condiciones no citen
 * normas con número (regla 2 del CLAUDE.md; la política es la única exenta y
 * su plantilla explica por qué), y que el interruptor de indexado les aplique
 * igual que al resto del sitio.
 */
final class PaginasLegalesTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private ConfigMysql $config;
    private PaginaLegal $paginas;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $this->config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pedro-sent-{$sufijo}",
            sys_get_temp_dir() . "/pedro-cfg-{$sufijo}.json",
        );

        $this->paginas = new PaginaLegal($this->config, self::URL);
    }

    #[Test]
    public function laPoliticaRespondeYDiceLoQueElSitioNoGuarda(): void
    {
        $respuesta = $this->paginas->privacidad();

        self::assertSame(200, $respuesta->estado);
        self::assertStringContainsString('Política de tratamiento de datos personales', $respuesta->cuerpo);

        // El compromiso central del §4 del CLAUDE.md, dicho al público: ni la
        // landing ni el diagnóstico guardan nada. Si algún día eso deja de
        // ser cierto, esta afirmación pública tiene que cambiar a la vez.
        self::assertStringContainsString('no recoge datos personales', $respuesta->cuerpo);
        self::assertStringContainsString('pedroabogadoaduanero@gmail.com', $respuesta->cuerpo);
    }

    #[Test]
    public function lasCondicionesRespondenYNieganLaAsesoriaPorNavegar(): void
    {
        $respuesta = $this->paginas->condiciones();

        self::assertSame(200, $respuesta->estado);
        self::assertStringContainsString('Condiciones del servicio', $respuesta->cuerpo);
        self::assertStringContainsString('no constituye asesoría', $respuesta->cuerpo);
    }

    #[Test]
    public function lasCondicionesNoCitanNormasConNumeroNiPrometenResultados(): void
    {
        // Las reglas 1–3 del CLAUDE.md aplican de lleno a esta página. La de
        // privacidad queda exenta solo para la Ley 1581, y su plantilla
        // documenta por qué.
        $cuerpo = $this->paginas->condiciones()->cuerpo;

        self::assertDoesNotMatchRegularExpression(
            '/\b(?:ley|decreto|resoluci[oó]n|art[ií]culo)\s+\d/iu',
            $cuerpo,
            'Las condiciones citan una norma con número.',
        );
        self::assertStringContainsString('promete ni garantiza resultados', $cuerpo);
    }

    #[Test]
    public function seEnlazanEntreSiYConElInicio(): void
    {
        // Google valida que la política sea alcanzable; el pie compartido la
        // enlaza desde todas las páginas públicas, estas incluidas.
        $condiciones = $this->paginas->condiciones()->cuerpo;
        $privacidad = $this->paginas->privacidad()->cuerpo;

        self::assertStringContainsString('href="/privacidad"', $condiciones);
        self::assertStringContainsString('href="/condiciones"', $privacidad);
        self::assertStringContainsString('href="/"', $privacidad);
    }

    #[Test]
    public function conBaseDeDatosElPieTraeElContactoDelBloque(): void
    {
        // La semilla de 0019 trae el correo real del dominio. Sin BD (el
        // constructor de arriba no la pasa) el pie sale sin contacto y las
        // demás pruebas lo demuestran al no romperse; con BD, aparece.
        $conBd = new PaginaLegal($this->config, self::URL, $this->bd);

        self::assertStringContainsString(
            'mailto:info@pedroabogadoaduanero.com',
            $conBd->privacidad()->cuerpo,
        );
    }

    #[Test]
    public function elInterruptorDeIndexadoLesAplica(): void
    {
        self::assertStringNotContainsString('noindex', $this->paginas->privacidad()->cuerpo);

        $this->config->set('landing_indexable', false, 'u');
        $sinIndexar = new PaginaLegal($this->config, self::URL);

        self::assertStringContainsString('noindex', $sinIndexar->privacidad()->cuerpo);
        self::assertStringContainsString('noindex', $sinIndexar->condiciones()->cuerpo);
    }
}
