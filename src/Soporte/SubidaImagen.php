<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Guarda una imagen subida por el panel (entrada cruda de `$_FILES`) dentro
 * de `public/img/`.
 *
 * No confía en el nombre ni la extensión que manda el navegador: el tipo
 * real lo decide `getimagesize()` sobre el contenido, y el nombre final lo
 * genera esta clase — nunca el que trae el archivo.
 *
 * `$mover` es el punto de prueba: en producción es `move_uploaded_file`, que
 * ya rechaza cualquier `tmp_name` que no venga de una subida real de PHP. En
 * pruebas se inyecta `copy(...)`, porque `move_uploaded_file` siempre falla
 * fuera de una petición HTTP genuina — no hay forma de simular eso, así que
 * la seguridad real la sigue poniendo `move_uploaded_file` en producción.
 */
final class SubidaImagen
{
    private const MIMES = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $archivo entrada de $_FILES
     * @return array{ok:bool,nombre:string,error:string}
     */
    public static function guardar(array $archivo, string $carpetaAbsoluta, string $prefijo, ?callable $mover = null): array
    {
        $mover ??= move_uploaded_file(...);

        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            // No se eligió archivo: no es un error, es «no tocar lo que había».
            return ['ok' => false, 'nombre' => '', 'error' => ''];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'nombre' => '', 'error' => 'No se pudo recibir el archivo.'];
        }

        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'nombre' => '', 'error' => 'Archivo inválido.'];
        }
        if ((int) ($archivo['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'nombre' => '', 'error' => 'La imagen pesa más de 5 MB.'];
        }

        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!isset(self::MIMES[$mime])) {
            return ['ok' => false, 'nombre' => '', 'error' => 'Formato no soportado: usa JPG, PNG o WebP.'];
        }

        if (!is_dir($carpetaAbsoluta) && !@mkdir($carpetaAbsoluta, 0775, true) && !is_dir($carpetaAbsoluta)) {
            return ['ok' => false, 'nombre' => '', 'error' => 'No se pudo crear la carpeta de destino.'];
        }

        // Mapa explícito de acentos en vez de iconv//TRANSLIT: en builds de
        // Windows esa función deja marcas ('a, ~n) que el regex de abajo
        // convertiría en guiones de más («protecci'on» → «protecci-on»).
        $mapaAcentos = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ];
        $slug = strtolower(strtr($prefijo, $mapaAcentos));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-') ?: 'imagen';
        $nombre = $slug . '-' . bin2hex(random_bytes(4)) . self::MIMES[$mime];

        if (!$mover($tmp, rtrim($carpetaAbsoluta, '/') . '/' . $nombre)) {
            return ['ok' => false, 'nombre' => '', 'error' => 'No se pudo guardar la imagen en el servidor.'];
        }

        return ['ok' => true, 'nombre' => $nombre, 'error' => ''];
    }
}
