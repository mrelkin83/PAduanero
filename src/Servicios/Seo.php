<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Core\Respuesta;
use App\Soporte\Fechas;

/**
 * robots.txt, sitemap.xml y los datos estructurados de `LegalService`.
 *
 * Regla que gobierna esta clase: **solo datos que existen**. Nada de
 * dirección postal inventada, nada de `aggregateRating` con reseñas que nadie
 * dejó, nada de `priceRange` adornado. Google penaliza el marcado falso, pero
 * el motivo de fondo es otro: son afirmaciones públicas de un abogado sobre
 * su propio despacho, y la Ley 1123 de 2007 regula esa publicidad.
 */
final class Seo
{
    /**
     * @param string $urlBase de `APP_URL`. Va en el .env y no en
     *        `configuraciones` porque cambia con el entorno, igual que
     *        `DB_HOST`: en desarrollo es localhost y en el VPS el dominio
     *        real. No es un parámetro operativo del negocio.
     */
    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
        private readonly string $urlBase,
    ) {
    }

    public function robots(): Respuesta
    {
        $base = $this->urlBase();

        if (!$this->indexable()) {
            // Mientras el copy no esté revisado, no se invita a nadie a
            // indexarlo. Lo controla `landing_indexable` desde el panel.
            return Respuesta::texto("User-agent: *\nDisallow: /\n");
        }

        $lineas = [
            'User-agent: *',
            'Allow: /',
            '',
            // Nada de esto debe acabar en un índice de búsqueda.
            'Disallow: /panel',
            'Disallow: /api/',
            '',
            "Sitemap: {$base}/sitemap.xml",
            '',
        ];

        return Respuesta::texto(implode("\n", $lineas));
    }

    public function sitemap(): Respuesta
    {
        $base = $this->urlBase();

        $urls = [['loc' => $base . '/', 'prioridad' => '1.0', 'cambio' => 'weekly']];

        // Los artículos llegan en la Etapa 8; la consulta ya los recoge para
        // que el sitemap no haya que tocarlo entonces.
        $filas = $this->bd->pdo()->query(
            'SELECT slug, publicado_en, actualizado_en FROM articulos
              WHERE publicado = 1 AND revisado_por_abogado = 1
              ORDER BY publicado_en DESC'
        )->fetchAll();

        foreach ($filas as $fila) {
            $urls[] = [
                'loc' => $base . '/articulos/' . $fila['slug'],
                'lastmod' => substr((string) $fila['actualizado_en'], 0, 10),
                'prioridad' => '0.7',
                'cambio' => 'monthly',
            ];
        }

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($urls as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url['loc']);

            if (isset($url['lastmod'])) {
                $xml->writeElement('lastmod', $url['lastmod']);
            }

            $xml->writeElement('changefreq', $url['cambio']);
            $xml->writeElement('priority', $url['prioridad']);
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return new Respuesta(
            $xml->outputMemory(),
            200,
            ['Content-Type' => 'application/xml; charset=utf-8'],
        );
    }

    /**
     * JSON-LD de `LegalService`.
     *
     * @return array<string,mixed>
     */
    public function datosEstructurados(): array
    {
        $base = $this->urlBase();
        $telefono = (string) $this->config->get('whatsapp_numero_negocio', '');

        $datos = [
            '@context' => 'https://schema.org',
            '@type' => 'LegalService',
            'name' => 'Pedro · Abogado aduanero y tributario',
            'description' => (string) $this->config->get('landing_meta_descripcion', ''),
            'url' => $base . '/',
            'image' => $base . '/img/pedro-perfil.jpg',
            'areaServed' => ['@type' => 'Country', 'name' => 'Colombia'],
            'availableLanguage' => 'es',
            'knowsAbout' => [
                'Derecho aduanero',
                'Comercio exterior',
                'Derecho tributario',
                'Procedimiento administrativo ante la DIAN',
            ],
        ];

        if ($telefono !== '') {
            $datos['telephone'] = '+' . $telefono;
        }

        // Sin `address`: el despacho no ha dado una dirección postal y
        // schema.org no es el sitio para improvisarla. Sin `aggregateRating`
        // ni `review`: no hay reseñas reales que declarar.
        // Sin `priceRange`: la tarifa está en el bloque `proceso`, en pesos y
        // sin adornos.

        return $datos;
    }

    private function indexable(): bool
    {
        return (bool) $this->config->get('landing_indexable', true);
    }

    private function urlBase(): string
    {
        return rtrim($this->urlBase, '/');
    }

    public function fechaHoy(): string
    {
        return Fechas::hoy();
    }
}
