<?php

declare(strict_types=1);

namespace App\Soporte;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Genera un QR como data-URI listo para incrustar en un <img>.
 *
 * Prefiere PNG (extensión gd), que dompdf rasteriza sin sorpresas; si el
 * servidor no tiene gd, cae a SVG — así el certificado nunca se queda sin QR
 * por una extensión ausente. La corrección de errores media (M) tolera que el
 * QR quede impreso sobre un fondo con algo de textura.
 */
final class GeneradorQr
{
    public static function dataUri(string $texto): string
    {
        $opciones = new QROptions([
            'eccLevel'        => EccLevel::M,
            'scale'           => 6,
            'outputInterface' => extension_loaded('gd') ? QRGdImagePNG::class : QRMarkupSVG::class,
            'outputBase64'    => true,
            'quietzoneSize'   => 2,
        ]);

        return (new QRCode($opciones))->render($texto);
    }
}
