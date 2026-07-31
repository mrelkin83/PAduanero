<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\ConfigMysql;
use App\Servicios\Seo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class SeoTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private Seo $seo;
    private ConfigMysql $config;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $this->config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pedro-sent-{$sufijo}",
            sys_get_temp_dir() . "/pedro-cfg-{$sufijo}.json",
        );

        $this->seo = new Seo($this->bd, $this->config, self::URL);
    }

    #[Test]
    public function robotsPermiteLaHomeYBloqueaElPanel(): void
    {
        $cuerpo = $this->seo->robots()->cuerpo;

        self::assertStringContainsString('Allow: /', $cuerpo);
        self::assertStringContainsString('Disallow: /panel', $cuerpo);
        self::assertStringContainsString('Disallow: /api/', $cuerpo);
        self::assertStringContainsString(self::URL . '/sitemap.xml', $cuerpo);
    }

    #[Test]
    public function robotsBloqueaTodoSiLaLandingNoEsIndexable(): void
    {
        // El interruptor existe para mientras el copy no esté revisado bajo
        // la Ley 1123 de 2007.
        $this->config->set('landing_indexable', false, 'u');

        $cuerpo = (new Seo($this->bd, $this->config, self::URL))->robots()->cuerpo;

        self::assertStringContainsString('Disallow: /', $cuerpo);
        self::assertStringNotContainsString('Allow: /', $cuerpo);
    }

    #[Test]
    public function elSitemapListaLaHome(): void
    {
        $respuesta = $this->seo->sitemap();

        self::assertSame('application/xml; charset=utf-8', $respuesta->cabeceras['Content-Type']);
        self::assertStringContainsString('<loc>' . self::URL . '/</loc>', $respuesta->cuerpo);

        // Que sea XML válido, no una cadena que lo parezca.
        self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($respuesta->cuerpo));
    }

    #[Test]
    public function elSitemapNoListaArticulosSinRevisionDelAbogado(): void
    {
        // El gate de `revisado_por_abogado` no es decorativo: publicar un
        // artículo jurídico sin revisar compromete a Pedro.
        $this->bd->pdo()->exec(
            "INSERT INTO articulos (slug, titulo, contenido_md, publicado, revisado_por_abogado, publicado_en)
             VALUES ('sin-revisar', 'Borrador', '…', 1, 0, UTC_TIMESTAMP()),
                    ('revisado', 'Publicable', '…', 1, 1, UTC_TIMESTAMP())"
        );

        $cuerpo = $this->seo->sitemap()->cuerpo;

        self::assertStringNotContainsString('sin-revisar', $cuerpo);
        self::assertStringContainsString('/articulos/revisado', $cuerpo);
    }

    #[Test]
    public function losDatosEstructuradosNoInventanNada(): void
    {
        $datos = $this->seo->datosEstructurados();

        self::assertSame('LegalService', $datos['@type']);
        self::assertSame('+573159923676', $datos['telephone']);

        // Nada de dirección postal que nadie dio, ni reseñas que nadie dejó.
        // Google penaliza el marcado falso, pero el motivo de fondo es que
        // son afirmaciones públicas de un abogado sobre su propio despacho.
        self::assertArrayNotHasKey('address', $datos);
        self::assertArrayNotHasKey('aggregateRating', $datos);
        self::assertArrayNotHasKey('review', $datos);
        self::assertArrayNotHasKey('priceRange', $datos);
    }

    #[Test]
    public function losDatosEstructuradosSonJsonValido(): void
    {
        $json = json_encode($this->seo->datosEstructurados());

        self::assertIsString($json);
        self::assertIsArray(json_decode($json, true));
    }
}
