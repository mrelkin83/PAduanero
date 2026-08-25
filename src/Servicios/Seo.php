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

        $urls = [
            // Las imágenes solo van en `/`: son las que la landing sirve
            // fijas (hero, credenciales, proceso, cierre) y con alt
            // descriptivo real — lo que el sitemap de imágenes pide. Ganar
            // Google Imágenes no depende de una dirección física, así que es
            // terreno alcanzable ya, a diferencia del paquete de mapa.
            [
                'loc' => $base . '/', 'prioridad' => '1.0', 'cambio' => 'weekly',
                'imagenes' => [
                    $base . '/img/pedro-hero.jpg',
                    $base . '/img/pedro-perfil.jpg',
                    $base . '/img/pedro-documentos.jpg',
                    $base . '/img/pedro-comercio-exterior.jpg',
                ],
            ],
            // El diagnóstico. Prioridad alta y no la de una página
            // secundaria: sus preguntas son literalmente las búsquedas por
            // las que este despacho quiere aparecer («me llegó un acta de
            // aprehensión»), y es la única URL del sitio que las contiene.
            ['loc' => $base . '/perfil', 'prioridad' => '0.9', 'cambio' => 'monthly'],
            // Las legales, con la prioridad de lo que nadie busca pero debe
            // poder encontrarse. La de privacidad además la valida Google
            // para publicar la app OAuth del calendario.
            ['loc' => $base . '/privacidad', 'prioridad' => '0.3', 'cambio' => 'yearly'],
            ['loc' => $base . '/condiciones', 'prioridad' => '0.3', 'cambio' => 'yearly'],
        ];

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
        $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        foreach ($urls as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url['loc']);

            if (isset($url['lastmod'])) {
                $xml->writeElement('lastmod', $url['lastmod']);
            }

            $xml->writeElement('changefreq', $url['cambio']);
            $xml->writeElement('priority', $url['prioridad']);

            foreach ($url['imagenes'] ?? [] as $imagen) {
                $xml->startElement('image:image');
                $xml->writeElement('image:loc', $imagen);
                $xml->endElement();
            }

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

        // `address` sale del bloque `confianza`, y solo si allí hay una
        // dirección escrita de verdad. Es el mismo dato que el visitante ve
        // en la página: si alguna vez difirieran, el marcado sería una
        // afirmación pública distinta de la visible, que es peor que no
        // marcar nada.
        $sedes = $this->sedes();

        if ($sedes !== []) {
            // Una sede da un objeto; varias, una lista. Schema.org acepta
            // ambas formas y una lista de un solo elemento se lee peor en las
            // herramientas de validación.
            $datos['address'] = count($sedes) === 1 ? $sedes[0] : $sedes;
        }

        // Sin `aggregateRating` ni `review`: los testimonios del bloque
        // `testimonios` son citas autorizadas sobre el trato recibido, no
        // reseñas con estrella. Convertirlas en `Review` con `ratingValue`
        // sería inventarle una puntuación a alguien que nunca la dio, y
        // además es justo el marcado que hace a Google penalizar un sitio.
        // Sin `priceRange`: la tarifa está en el bloque `proceso`, en pesos y
        // sin adornos.

        $sameAs = $this->redesSociales();
        if ($sameAs !== []) {
            // Cada perfil verificado que Google puede cruzar con este mismo
            // nombre y teléfono es una señal de que la entidad es real — la
            // misma lógica que el bloque `confianza`, aplicada al grafo de
            // conocimiento en vez de al ojo del visitante. Hoy `pie.redes`
            // no tiene ninguna URL cargada: en cuanto Pedro cargue una desde
            // el panel, aparece aquí solo, sin otro cambio.
            $datos['sameAs'] = $sameAs;
        }

        return $datos;
    }

    /**
     * Las redes sociales con URL real, para `sameAs`. Misma validación que
     * `bloques/pie.php`: nombre y URL con `https://`, o no cuenta.
     *
     * @return list<string>
     */
    private function redesSociales(): array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT contenido FROM landing_bloques WHERE clave = 'pie' AND visible = 1"
        );
        $stmt->execute();
        $crudo = $stmt->fetchColumn();

        if (!is_string($crudo)) {
            return [];
        }

        $contenido = json_decode($crudo, true);
        $redes = is_array($contenido) && is_array($contenido['redes'] ?? null)
            ? $contenido['redes']
            : [];

        $salida = [];
        foreach ($redes as $red) {
            if (!is_array($red)) {
                continue;
            }
            $nombre = is_string($red['nombre'] ?? null) ? trim($red['nombre']) : '';
            $url = is_string($red['url'] ?? null) ? trim($red['url']) : '';
            if ($nombre !== '' && preg_match('#^https://#i', $url) === 1) {
                $salida[] = $url;
            }
        }

        return $salida;
    }

    /**
     * Las sedes con dirección, en formato `PostalAddress`.
     *
     * Devuelve lista vacía si el bloque no existe, si no tiene sedes o si
     * ninguna trae dirección. La regla de esta clase —solo datos que
     * existen— aplica aquí con más razón que en ningún otro campo: una
     * dirección postal inventada en el marcado de un abogado es exactamente
     * la clase de afirmación que la Ley 1123 de 2007 regula.
     *
     * @return list<array<string,string>>
     */
    private function sedes(): array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT contenido FROM landing_bloques WHERE clave = 'confianza' AND visible = 1"
        );
        $stmt->execute();
        $crudo = $stmt->fetchColumn();

        if (!is_string($crudo)) {
            return [];
        }

        $contenido = json_decode($crudo, true);
        $sedes = is_array($contenido) && is_array($contenido['sedes'] ?? null)
            ? $contenido['sedes']
            : [];

        $salida = [];

        foreach ($sedes as $sede) {
            if (!is_array($sede)) {
                continue;
            }

            $direccion = trim((string) ($sede['direccion'] ?? ''));

            // Vacía o marcada como pendiente, fuera. El relleno provisional
            // se ve en la página como lo que es —en gris y con su marca—,
            // pero en el marcado no hay forma de decir «esto todavía no es
            // cierto»: `PostalAddress` es una afirmación a secas. Emitir aquí
            // una dirección de relleno la convertiría en un dato falso
            // publicado por un abogado sobre su propio despacho.
            if ($direccion === '' || ($sede['pendiente'] ?? null) === true) {
                continue;
            }

            $salida[] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $direccion,
                'addressLocality' => 'Bogotá',
                'addressCountry' => 'CO',
            ];
        }

        return $salida;
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
