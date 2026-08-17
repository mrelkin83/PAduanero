<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Core\Respuesta;
use App\Modelos\Bloque;
use App\Motor\Cuestionario;

/**
 * El diagnóstico público: `/perfil`.
 *
 * Página propia y no una sección de la landing, por tres razones que se
 * refuerzan: se puede pautar y medir por separado, no compite con el scroll
 * de la portada, y las preguntas —«me llegó un acta de aprehensión, ¿qué
 * hago?»— son exactamente las búsquedas por las que este despacho quiere
 * aparecer, lo que solo sirve si viven en una URL indexable.
 *
 * Se renderiza **entera** en el servidor: los seis pasos y las dos ramas
 * salen en el HTML como un formulario normal. El JavaScript se limita a
 * esconder los pasos que no tocan y a animar el paso de uno a otro. Sin
 * JavaScript la página sigue siendo un documento legible que termina en el
 * botón de WhatsApp de siempre — que es la regla de esta landing desde la
 * Etapa 1: la analítica y los adornos nunca pueden estorbar a la conversión.
 *
 * Cero persistencia. Ver `App\Motor\Cuestionario` para por qué eso es lo que
 * mantiene el diagnóstico fuera del alcance de la regla 1.
 */
final class Perfil
{
    /** Los bloques editables de esta página. */
    private const CLAVES = ['perfil_intro', 'perfil_resultado', 'perfil_fuera_alcance'];

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
        return new Respuesta($this->htmlCacheado(), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
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
        $base = rtrim($this->urlBase, '/');

        return Respuesta::vista('perfil/pagina', [
            'pasos' => Cuestionario::definicion(),
            'largos' => array_combine(
                Cuestionario::ramas(),
                array_map(Cuestionario::largoDeRama(...), Cuestionario::ramas()),
            ),
            'bloques' => $this->bloques(),
            'asesoria' => $this->asesoria(),
            'meta' => [
                'titulo' => (string) $this->config->get(
                    'perfil_meta_titulo',
                    'Diagnostique su caso ante la DIAN',
                ),
                'descripcion' => (string) $this->config->get('perfil_meta_descripcion', ''),
                'indexable' => (bool) $this->config->get('landing_indexable', true),
                'url' => $base,
                'jsonLd' => $this->seo->datosEstructurados(),
            ],
            'whatsapp' => [
                'numero' => (string) $this->config->get('whatsapp_numero_negocio', ''),
                'mensaje' => (string) $this->config->get('landing_mensaje_whatsapp', ''),
            ],
        ])->cuerpo;
    }

    /** @return array<string,Bloque> */
    public function bloques(): array
    {
        $marcas = implode(',', array_fill(0, count(self::CLAVES), '?'));

        $stmt = $this->bd->pdo()->prepare(
            "SELECT clave, titulo, subtitulo, contenido, orden, visible
               FROM landing_bloques WHERE clave IN ({$marcas}) AND visible = 1"
        );
        $stmt->execute(self::CLAVES);

        $bloques = [];

        foreach ($stmt->fetchAll() as $fila) {
            $bloque = Bloque::desdeFila($fila);
            $bloques[$bloque->clave] = $bloque;
        }

        return $bloques;
    }

    /**
     * Precio y duración de la modalidad activa.
     *
     * Se leen de `modalidades_asesoria` y no de un texto del bloque a
     * propósito. El precio ya vive en una columna (ADR-010, en pesos
     * enteros); copiarlo a mano en el copy del resultado crearía una segunda
     * verdad, y el día que Pedro lo suba desde el panel la página seguiría
     * anunciando el viejo. Un precio anunciado y otro cobrado no es una
     * errata: es lo que hace que alguien abandone el pago.
     *
     * @return array{precio:?int, duracion:?int, nombre:string}
     */
    private function asesoria(): array
    {
        $fila = $this->bd->pdo()->query(
            'SELECT nombre, precio_cop, duracion_min FROM modalidades_asesoria
              WHERE activo = 1 ORDER BY orden LIMIT 1'
        )->fetch();

        if ($fila === false) {
            return ['precio' => null, 'duracion' => null, 'nombre' => 'Asesoría jurídica'];
        }

        return [
            'precio' => (int) $fila['precio_cop'],
            'duracion' => (int) $fila['duracion_min'],
            'nombre' => (string) $fila['nombre'],
        ];
    }
}
