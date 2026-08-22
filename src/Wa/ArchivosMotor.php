<?php

declare(strict_types=1);

namespace App\Wa;

use ElkinLinan\WhatsappAiEngine\Ports\StoragePort;

/**
 * La media de WhatsApp (notas de voz, fotos, comprobantes) va a
 * `storage/wa/`, FUERA de public/: son datos personales de quien escribe y
 * no se sirven al mundo. Cuando el panel tenga pantalla de conversaciones,
 * los servirá él con sesión y permisos por delante.
 */
final class ArchivosMotor implements StoragePort
{
    public function __construct(
        private readonly string $raiz,      // .../storage/wa
        private readonly string $urlBase,   // https://sitio para enlaces del panel
    ) {
    }

    public function directorio(): string
    {
        if (!is_dir($this->raiz)) {
            @mkdir($this->raiz, 0770, true);
        }

        return $this->raiz;
    }

    public function raiz(): string
    {
        return $this->raiz;
    }

    public function url(string $rutaRelativa): string
    {
        // Ruta del panel, todavía sin pantalla detrás: el enlace existe para
        // la bitácora y para cuando la pantalla llegue. Nunca apunta a public/.
        return rtrim($this->urlBase, '/') . '/panel/whatsapp/media/' . ltrim($rutaRelativa, '/');
    }

    public function comprimirImagen(string $binario, int $maxLado = 1024, int $calidad = 78): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $img = @imagecreatefromstring($binario);
            if ($img === false) {
                return null;
            }

            $ancho = imagesx($img);
            $alto = imagesy($img);
            $mayor = max($ancho, $alto);

            if ($mayor > $maxLado) {
                $escala = $maxLado / $mayor;
                $img = imagescale($img, (int) ($ancho * $escala), (int) ($alto * $escala));
                if ($img === false) {
                    return null;
                }
            }

            ob_start();
            imagejpeg($img, null, $calidad);
            $salida = ob_get_clean();
            imagedestroy($img);

            if ($salida === false || strlen($salida) >= strlen($binario)) {
                return null; // no mejora: se guarda el original
            }

            return ['bin' => $salida, 'mime' => 'image/jpeg'];
        } catch (\Throwable) {
            return null;
        }
    }

    public function cabe(int $bytes): bool
    {
        // Sin planes ni cupos por negocio. El límite real es la retención de
        // media que el propio motor purga (`retencion_media_dias`).
        return true;
    }
}
