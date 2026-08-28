<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Guarda un material descargable subido por el panel (entrada cruda de
 * `$_FILES`) dentro de una carpeta de `storage/`.
 *
 * Mismo patrón que `SubidaImagen`: no confía en el nombre que manda el
 * navegador, el nombre final lo genera esta clase. A diferencia de
 * `SubidaImagen`, la validación de tipo es por EXTENSIÓN declarada (lista
 * blanca), no por contenido — un PDF o un .docx no tienen una firma binaria
 * tan uniforme como una imagen, y la lista blanca ya cierra la puerta a
 * ejecutables y scripts.
 */
final class SubidaMaterial
{
    private const EXTENSIONES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'jpg', 'png'];
    private const MAX_BYTES = 30 * 1024 * 1024;

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $archivo entrada de $_FILES
     * @return array{ok:bool,archivo:string,extension:string,tamanioBytes:int,error:string}
     */
    public static function guardar(array $archivo, string $carpetaAbsoluta, ?callable $mover = null): array
    {
        $mover ??= move_uploaded_file(...);

        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => ''];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'No se pudo recibir el archivo.'];
        }

        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'Archivo inválido.'];
        }

        $tamanio = (int) ($archivo['size'] ?? 0);
        if ($tamanio > self::MAX_BYTES) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'El archivo pesa más de 30 MB.'];
        }

        $extension = strtolower(pathinfo((string) ($archivo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONES, true)) {
            return [
                'ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0,
                'error' => 'Formato no permitido: use ' . implode(', ', self::EXTENSIONES) . '.',
            ];
        }

        if (!is_dir($carpetaAbsoluta) && !@mkdir($carpetaAbsoluta, 0775, true) && !is_dir($carpetaAbsoluta)) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'No se pudo crear la carpeta de destino.'];
        }

        $nombre = bin2hex(random_bytes(16));

        if (!$mover($tmp, rtrim($carpetaAbsoluta, '/') . '/' . $nombre . '.' . $extension)) {
            return ['ok' => false, 'archivo' => '', 'extension' => '', 'tamanioBytes' => 0, 'error' => 'No se pudo guardar el archivo en el servidor.'];
        }

        return ['ok' => true, 'archivo' => $nombre, 'extension' => $extension, 'tamanioBytes' => $tamanio, 'error' => ''];
    }
}
