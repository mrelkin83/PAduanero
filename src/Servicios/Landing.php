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
    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
        private readonly Seo $seo,
        private readonly string $urlBase,
        private readonly string $rutaCache,
        private readonly string $rutaSentinela,
        private readonly string $rutaCss,
    ) {
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
        $ttl = (int) $this->config->get('landing_cache_segundos', 300);

        if ($ttl > 0 && $this->cacheVigente($ttl)) {
            $html = @file_get_contents($this->rutaCache);

            if (is_string($html) && $html !== '') {
                return $html;
            }
        }

        $html = $this->render();

        if ($ttl > 0) {
            $this->guardarCache($html);
        }

        return $html;
    }

    public function render(): string
    {
        $bloques = $this->bloques();

        return Respuesta::vista('landing/pagina', [
            'bloques' => $bloques,
            'meta' => [
                'titulo' => (string) $this->config->get(
                    'landing_meta_titulo',
                    'Abogado aduanero y tributario',
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

    public function invalidarCache(): void
    {
        @unlink($this->rutaCache);
        $this->tocarSentinela();
    }

    private function cacheVigente(int $ttl): bool
    {
        clearstatcache(true, $this->rutaCache);

        if (!is_file($this->rutaCache)) {
            return false;
        }

        $generada = (int) @filemtime($this->rutaCache);

        if ($generada + $ttl < time()) {
            return false;
        }

        // Un centinela más reciente que la caché significa que alguien
        // guardó un bloque desde el panel.
        clearstatcache(true, $this->rutaSentinela);
        $sentinela = is_file($this->rutaSentinela) ? (int) @filemtime($this->rutaSentinela) : 0;

        if ($sentinela > $generada) {
            return false;
        }

        // El CSS va incrustado en el HTML, así que recompilarlo tiene que
        // invalidar la caché igual que editar un bloque. Sin esto, un
        // `npm run build:css` no se vería hasta que expirara el TTL, y el
        // síntoma —cambio de CSS que no aparece— es de los que cuestan una
        // tarde encontrar.
        clearstatcache(true, $this->rutaCss);
        $css = is_file($this->rutaCss) ? (int) @filemtime($this->rutaCss) : 0;

        return $css <= $generada;
    }

    private function guardarCache(string $html): void
    {
        $directorio = dirname($this->rutaCache);
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0o770, true);
        }

        // Escritura atómica: sin el rename, una visita concurrente puede leer
        // el archivo a medio escribir y servir HTML truncado.
        $temporal = $this->rutaCache . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporal, $html) === false) {
            return;
        }

        if (!@rename($temporal, $this->rutaCache)) {
            @unlink($temporal);
        }
    }

    private function tocarSentinela(): void
    {
        $directorio = dirname($this->rutaSentinela);
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0o770, true);
        }

        @touch($this->rutaSentinela);
    }
}
