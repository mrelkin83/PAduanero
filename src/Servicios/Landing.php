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
            // El HTML no se cachea en el navegador: si Pedro corrige un
            // precio, no puede quedar viviendo una hora en los clientes.
            // Lo pesado (imágenes, CSS) sí, y va con hash en el nombre.
            'Cache-Control' => 'no-cache, must-revalidate',
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
