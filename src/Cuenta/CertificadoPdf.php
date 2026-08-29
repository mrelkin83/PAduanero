<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Repositorios\CompradorRepo;
use App\Soporte\Vista;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Genera el PDF del certificado en memoria — nunca se guarda un archivo en
 * disco, se produce de nuevo en cada descarga.
 */
final class CertificadoPdf
{
    public function __construct(private readonly CompradorRepo $compradores)
    {
    }

    public function generar(string $compradorId, string $nombreCurso, string $codigo, string $emitidoEn): string
    {
        $comprador = $this->compradores->porId($compradorId);
        $numeroDocumento = $this->compradores->numeroDocumento($compradorId) ?? '';

        $html = $this->html(
            $comprador?->nombreCompleto() ?? '',
            $comprador?->tipoDocumento ?? '',
            $numeroDocumento,
            $nombreCurso,
            $codigo,
            $emitidoEn,
        );

        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function html(
        string $nombre,
        string $tipoDocumento,
        string $numeroDocumento,
        string $curso,
        string $codigo,
        string $emitidoEn,
    ): string {
        $e = Vista::e(...);

        return <<<HTML
        <!doctype html>
        <html><head><meta charset="utf-8"><style>
        body { font-family: sans-serif; text-align: center; padding: 60px; }
        h1 { font-size: 32px; margin-bottom: 40px; }
        .nombre { font-size: 28px; font-weight: bold; margin: 30px 0; }
        .curso { font-size: 20px; margin-bottom: 30px; }
        .pie { margin-top: 60px; font-size: 12px; color: #666; }
        </style></head><body>
        <h1>Certificado de finalización</h1>
        <p>Se certifica que</p>
        <p class="nombre">{$e($nombre)}</p>
        <p>completó satisfactoriamente el curso</p>
        <p class="curso">{$e($curso)}</p>
        <p class="pie">
            {$e($tipoDocumento)} {$e($numeroDocumento)}<br>
            Emitido el {$e($emitidoEn)}<br>
            Código de verificación: {$e($codigo)}
        </p>
        </body></html>
        HTML;
    }
}
