<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Repositorios\CertificadoPlantillaRepo;
use App\Repositorios\CompradorRepo;
use App\Soporte\GeneradorQr;
use App\Soporte\Vista;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Genera el PDF del certificado en memoria — nunca se guarda un archivo en
 * disco, se produce de nuevo en cada descarga.
 *
 * Dos caminos: si el panel cargó una imagen de fondo (plantilla), se estampan
 * los datos encima de ella en las posiciones configuradas; si no, se usa el
 * diseño sobrio de siempre. Así los certificados salen aunque nadie haya
 * tocado la plantilla.
 */
final class CertificadoPdf
{
    /** Carpeta de las imágenes de fondo, relativa a la raíz del proyecto. */
    public const CARPETA_FONDOS = 'storage/cursos/certificados';

    /** Base de la URL que codifica el QR y que la persona verifica. */
    private const URL_VERIFICACION = 'https://pedroabogadoaduanero.com/certificados/verificar/';

    public function __construct(
        private readonly CompradorRepo $compradores,
        private readonly ?CertificadoPlantillaRepo $plantillas = null,
    ) {
    }

    public function generar(string $compradorId, string $nombreCurso, string $codigo, string $emitidoEn): string
    {
        $comprador = $this->compradores->porId($compradorId);
        $numeroDocumento = $this->compradores->numeroDocumento($compradorId) ?? '';

        $datos = [
            'nombre'    => $comprador?->nombreCompleto() ?? '',
            'curso'     => $nombreCurso,
            'fecha'     => 'Emitido el ' . $emitidoEn,
            'documento' => trim(($comprador?->tipoDocumento ?? '') . ' ' . $numeroDocumento),
            'codigo'    => 'Código de verificación: ' . $codigo
                . '  ·  pedroabogadoaduanero.com/certificados/verificar/' . $codigo,
        ];

        $urlQr = self::URL_VERIFICACION . $codigo;
        $plantilla = $this->plantillas?->obtener();
        $rutaFondo = $this->rutaFondo($plantilla['imagen_fondo'] ?? null);

        $html = $rutaFondo !== null
            ? $this->htmlConFondo($rutaFondo, $plantilla['campos'], $datos, $urlQr)
            : $this->html(
                $datos['nombre'],
                $comprador?->tipoDocumento ?? '',
                $numeroDocumento,
                $nombreCurso,
                $codigo,
                $emitidoEn,
                $urlQr,
            );

        return $this->render($html);
    }

    /**
     * PDF de muestra para el panel: los mismos datos de ejemplo, para ajustar
     * la plantilla sin tener que completar un curso real.
     *
     * @param array<string,array<string,mixed>> $campos
     */
    public function muestra(?string $imagenFondo, array $campos): string
    {
        $datos = [
            'nombre'    => 'María Fernanda Rodríguez',
            'curso'     => 'Curso de ejemplo',
            'fecha'     => 'Emitido el ' . date('Y-m-d'),
            'documento' => 'CC 1.234.567.890',
            'codigo'    => 'Código de verificación: PA-EJEMPLO1'
                . '  ·  pedroabogadoaduanero.com/certificados/verificar/PA-EJEMPLO1',
        ];

        $urlQr = self::URL_VERIFICACION . 'PA-EJEMPLO1';
        $rutaFondo = $this->rutaFondo($imagenFondo);
        $html = $rutaFondo !== null
            ? $this->htmlConFondo($rutaFondo, $campos, $datos, $urlQr)
            : $this->html($datos['nombre'], 'CC', '1.234.567.890', $datos['curso'], 'PA-EJEMPLO1', date('Y-m-d'), $urlQr);

        return $this->render($html);
    }

    private function render(string $html): string
    {
        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);
        // Las imágenes de fondo viven en storage/, fuera de la raíz pública:
        // dompdf necesita permiso para leer de ahí por ruta de archivo.
        $opciones->set('chroot', [dirname(__DIR__, 2)]);

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function rutaFondo(?string $archivo): ?string
    {
        $archivo = $archivo !== null ? basename($archivo) : '';
        if ($archivo === '') {
            return null;
        }
        $ruta = dirname(__DIR__, 2) . '/' . self::CARPETA_FONDOS . '/' . $archivo;

        return is_file($ruta) ? $ruta : null;
    }

    /**
     * Estampa los datos sobre la imagen de fondo. La imagen ocupa la hoja
     * entera; cada dato es un bloque absoluto posicionado por % de altura y,
     * si no va centrado, por % de margen lateral.
     *
     * @param array<string,array<string,mixed>> $campos
     * @param array<string,string>              $datos
     */
    private function htmlConFondo(string $rutaFondo, array $campos, array $datos, string $urlQr): string
    {
        $e = Vista::e(...);
        $fondoData = $this->comoDataUri($rutaFondo);

        $bloques = '';
        foreach ($campos as $clave => $c) {
            if (empty($c['visible'])) {
                continue;
            }
            $alineacion = (string) $c['alineacion'];
            $y = (int) $c['y'];
            $x = (int) $c['x'];

            // El QR es una imagen, no texto: 'tamano' es su ancho en % de la hoja.
            if ($clave === 'qr') {
                $ancho = max(4, (int) $c['tamano']);
                $lado = match ($alineacion) {
                    'left'  => 'left:' . $x . '%;',
                    'right' => 'right:' . $x . '%;',
                    default => 'left:' . max(0, (int) ((100 - $ancho) / 2)) . '%;',
                };
                $bloques .= '<img src="' . GeneradorQr::dataUri($urlQr) . '" style="position:absolute; top:'
                    . $y . '%; ' . $lado . ' width:' . $ancho . '%;">';
                continue;
            }

            if (($datos[$clave] ?? '') === '') {
                continue;
            }
            $estilo = 'position:absolute; top:' . $y . '%; font-size:' . (int) $c['tamano'] . 'pt;'
                . ' color:' . $e((string) $c['color']) . '; text-align:' . $alineacion . ';';
            // Centrado: banda de ancho completo centrada. Izquierda/derecha:
            // anclada al margen lateral que se haya configurado.
            $estilo .= match ($alineacion) {
                'left'  => ' left:' . $x . '%; right:0; padding-left:0;',
                'right' => ' left:0; right:' . $x . '%;',
                default => ' left:0; right:0;',
            };

            $bloques .= '<div style="' . $estilo . '">' . $e($datos[$clave]) . '</div>';
        }

        return <<<HTML
        <!doctype html>
        <html><head><meta charset="utf-8"><style>
        @page { margin: 0; }
        html, body { margin: 0; padding: 0; }
        .hoja { position: relative; width: 100%; height: 100%; font-family: sans-serif; }
        .fondo { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        </style></head><body>
        <div class="hoja">
            <img class="fondo" src="{$fondoData}">
            {$bloques}
        </div>
        </body></html>
        HTML;
    }

    /** La imagen embebida como data URI: evita cualquier duda de rutas en dompdf. */
    private function comoDataUri(string $ruta): string
    {
        $bin = (string) @file_get_contents($ruta);
        $mime = match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }

    private function html(
        string $nombre,
        string $tipoDocumento,
        string $numeroDocumento,
        string $curso,
        string $codigo,
        string $emitidoEn,
        string $urlQr,
    ): string {
        $e = Vista::e(...);
        $qr = GeneradorQr::dataUri($urlQr);

        return <<<HTML
        <!doctype html>
        <html><head><meta charset="utf-8"><style>
        body { font-family: sans-serif; text-align: center; padding: 60px; }
        h1 { font-size: 32px; margin-bottom: 40px; }
        .nombre { font-size: 28px; font-weight: bold; margin: 30px 0; }
        .curso { font-size: 20px; margin-bottom: 30px; }
        .pie { margin-top: 40px; font-size: 12px; color: #666; }
        .qr { margin-top: 24px; }
        .qr img { width: 120px; }
        </style></head><body>
        <h1>Certificado de finalización</h1>
        <p>Se certifica que</p>
        <p class="nombre">{$e($nombre)}</p>
        <p>completó satisfactoriamente el curso</p>
        <p class="curso">{$e($curso)}</p>
        <div class="qr"><img src="{$qr}"></div>
        <p class="pie">
            {$e($tipoDocumento)} {$e($numeroDocumento)}<br>
            Emitido el {$e($emitidoEn)}<br>
            Código de verificación: {$e($codigo)}<br>
            Verifique este certificado en pedroabogadoaduanero.com/certificados/verificar/{$e($codigo)}
        </p>
        </body></html>
        HTML;
    }
}
