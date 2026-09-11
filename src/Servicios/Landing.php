<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Core\Respuesta;
use App\Modelos\Bloque;

/**
 * Renderiza la landing desde `landing_bloques` y la sirve desde caché.
 *
 * «La landing no consulta MySQL en cada visita ni carga JavaScript del panel»
 * (docs/PANEL_ADMIN.md §5). Con la caché caliente, una visita cuesta un
 * `file_get_contents` y cero consultas — que es lo que hace alcanzable el
 * LCP < 2 s del criterio de cierre.
 *
 * La caché se invalida por dos vías: el TTL de `landing_cache_segundos` y el
 * centinela, que el panel tocará al guardar un bloque en la Etapa 3. Sin el
 * centinela, Pedro cambiaría un texto y no lo vería hasta cinco minutos
 * después, y acabaría pensando que el panel no guarda.
 */
final class Landing
{
    private readonly CachePagina $cache;

    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
        private readonly Seo $seo,
        private readonly string $urlBase,
        string $rutaCache,
        string $rutaSentinela,
        string $rutaCss,
    ) {
        $this->cache = new CachePagina($rutaCache, $rutaSentinela, $rutaCss);
    }

    public function responder(): Respuesta
    {
        $html = $this->htmlCacheado();

        return new Respuesta($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // El NAVEGADOR sigue revalidando siempre: si Pedro corrige un
            // precio, no puede quedar viviendo una hora en los clientes.
            // Eso es `max-age=0, must-revalidate`, y es la regla de antes.
            //
            // Lo nuevo es `s-maxage`, que solo obedecen las cachés
            // COMPARTIDAS —la CDN—, y que es lo que puede arreglar el
            // problema real de esta página: el origen está en Francia y el
            // borde de Cloudflare que atiende a Colombia está en Miami. Con
            // el HTML sin cachear, cada visita cruza el Atlántico dos veces
            // y el TTFB se va a 0,75 s cuando el servidor responde en 8 ms.
            //
            // 300 s es exactamente el TTL que ya tiene la caché de páginas
            // (`landing_cache_segundos`), así que no añade ni un segundo de
            // desfase que el sistema no aceptara ya. `stale-while-revalidate`
            // deja que el borde sirva al instante mientras refresca detrás.
            //
            // OJO: esto no hace nada por sí solo. Cloudflare **ignora** las
            // cabeceras de caché en HTML salvo que haya una Cache Rule que
            // diga «Cache Everything» para el dominio. La cabecera está aquí
            // para que el día que se active esa regla el comportamiento sea
            // el correcto, y no uno que haya que descubrir en producción.
            'Cache-Control' => 'public, max-age=0, s-maxage=300, stale-while-revalidate=600, must-revalidate',
        ]);
    }

    public function htmlCacheado(): string
    {
        return $this->cache->obtener(
            (int) $this->config->get('landing_cache_segundos', 300),
            fn (): string => $this->render(),
        );
    }

    public function render(): string
    {
        $bloques = $this->bloques();

        return Respuesta::vista('landing/pagina', [
            'bloques' => $bloques,
            'meta' => [
                'titulo' => (string) $this->config->get(
                    'landing_meta_titulo',
                    'Abogado aduanero',
                ),
                'descripcion' => (string) $this->config->get('landing_meta_descripcion', ''),
                'indexable' => (bool) $this->config->get('landing_indexable', true),
                'url' => rtrim($this->urlBase, '/'),
                'jsonLd' => $this->seo->datosEstructurados(),
            ],
            'whatsapp' => [
                'numero' => (string) $this->config->get('whatsapp_numero_negocio', ''),
                'mensaje' => (string) $this->config->get('landing_mensaje_whatsapp', ''),
            ],
            'chatwoot' => [
                'token' => (string) $this->config->get('chatwoot_widget_token', ''),
                'url' => rtrim((string) $this->config->get('chatwoot_widget_url', ''), '/'),
            ],
            'topeEventos' => (int) $this->config->get('landing_eventos_por_sesion', 60),
            // Solo controla el enlace del menú, no las rutas /cursos en sí
            // (spec §5) — igual que cualquier otro valor de `configuraciones`
            // que lee esta plantilla, el cambio tarda hasta
            // `landing_cache_segundos` en reflejarse (comportamiento ya
            // existente, no nuevo de este campo).
            'cursosActivo' => (bool) $this->config->get('cursos_activo', false),
        ])->cuerpo;
    }

    /** @return array<string,Bloque> indexados por clave, en orden */
    public function bloques(): array
    {
        $filas = $this->bd->pdo()->query(
            'SELECT clave, titulo, subtitulo, contenido, orden, visible
               FROM landing_bloques WHERE visible = 1 ORDER BY orden'
        )->fetchAll();

        $bloques = [];
        foreach ($filas as $fila) {
            $bloque = Bloque::desdeFila($fila);
            $bloques[$bloque->clave] = $bloque;
        }

        return $bloques;
    }

    /**
     * El centinela lo comparte con `/perfil`: los bloques de las dos páginas
     * viven en la misma tabla, así que invalidar una invalida la otra.
     */
    public function invalidarCache(): void
    {
        $this->cache->invalidar();
    }
}
