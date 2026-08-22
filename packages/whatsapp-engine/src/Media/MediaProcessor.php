<?php
/**
 * ============================================================================
 * MediaProcessor — descarga, valida, guarda y purga la media (§16, §19)
 * ============================================================================
 * El audio y las imágenes de WhatsApp cuentan para el cupo de almacenamiento
 * del plan. Por eso las imágenes pasan por el compresor que ya usa todo el
 * sistema (WebP ≤1024px, −85% medido) y todo se purga pasados los días que
 * configure el negocio.
 *
 * Los comprobantes de pago son la excepción: se conservan más tiempo porque son
 * la prueba de una transacción, no una foto cualquiera.
 */

namespace ElkinLinan\WhatsappAiEngine\Media;

class MediaProcessor
{
    const MAX_BYTES        = 16777216;   // 16 MB: lo que WhatsApp deja mandar
    const MAX_SEG_AUDIO    = 300;        // 5 min de nota de voz
    const MIMES_AUDIO      = ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/amr', 'audio/wav', 'audio/webm'];
    const MIMES_IMAGEN     = ['image/jpeg', 'image/png', 'image/webp'];

    private $db;
    private $log;

    public function __construct($db, $log = null)
    {
        $this->db  = $db;
        $this->log = $log;
    }

    /** Carpeta del negocio dentro de uploads/, creada si no existe. */
    private function directorio(): string
    {
        $base = \ElkinLinan\WhatsappAiEngine\Engine::archivos()->raiz();
        $dir = \ElkinLinan\WhatsappAiEngine\Engine::archivos()->directorio() . '/' . date('Y-m');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    /** Ruta relativa a uploads/, que es lo que se guarda en BD. */
    private function relativa(string $absoluta): string
    {
        $base = \ElkinLinan\WhatsappAiEngine\Engine::archivos()->raiz();
        return ltrim(str_replace(str_replace('\\', '/', $base), '', str_replace('\\', '/', $absoluta)), '/');
    }

    /**
     * Guarda un binario entrante. Devuelve ['ok','ruta','mime','bytes','error'].
     * Valida ANTES de escribir: un tipo no soportado no llega a ocupar disco.
     */
    public function guardar(string $binario, string $mime, string $tipo): array
    {
        $out = ['ok' => false, 'ruta' => '', 'mime' => $mime, 'bytes' => strlen($binario), 'error' => ''];
        if ($binario === '')                  { $out['error'] = 'Archivo vacío'; return $out; }
        if (strlen($binario) > self::MAX_BYTES) { $out['error'] = 'El archivo es demasiado grande'; return $out; }

        $mimeBase = strtolower(trim(explode(';', $mime)[0]));
        if ($tipo === 'audio'  && !in_array($mimeBase, self::MIMES_AUDIO, true)) {
            $out['error'] = 'Formato de audio no soportado'; return $out;
        }
        if ($tipo === 'imagen' && !in_array($mimeBase, self::MIMES_IMAGEN, true)) {
            $out['error'] = 'Formato de imagen no soportado'; return $out;
        }

        // El cupo del plan manda: si no cabe, no se guarda.
        {
            if (!\ElkinLinan\WhatsappAiEngine\Engine::archivos()->cabe(strlen($binario))) {
                $out['error'] = 'El negocio alcanzó su límite de almacenamiento';
                if ($this->log) $this->log->error('Media descartada: sin cupo de almacenamiento');
                return $out;
            }
        }

        if ($tipo === 'imagen') {
            // OJO CON EL CONTRATO: devuelve ['bin'=>…, 'mime'=>…] o null, NO el
            // binario suelto. Tratarlo como string reventaba con TypeError en
            // strlen() y —como el crash cae después de responder 200— el cliente
            // solo veía «no puedo completar la operación» y la transferencia a
            // una persona NUNCA llegaba a ejecutarse. Toda imagen entrante moría
            // aquí mientras GD estuviera disponible.
            $comprimida = \ElkinLinan\WhatsappAiEngine\Engine::archivos()->comprimirImagen($binario, 1024, 78);
            if (is_array($comprimida) && !empty($comprimida['bin'])
                && strlen($comprimida['bin']) < strlen($binario)) {
                $binario = $comprimida['bin'];
                // El mime REAL de la recompresión: sin WebP disponible cae a PNG
                // o JPEG, y anunciar webp guardaría el archivo con la extensión
                // equivocada.
                $out['mime'] = $comprimida['mime'] ?? 'image/webp';
                $mimeBase    = $out['mime'];
            }
        }

        $ext = self::extension($mimeBase);
        $ruta = $this->directorio() . '/' . $tipo . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $ext;
        if (@file_put_contents($ruta, $binario) === false) {
            $out['error'] = 'No se pudo guardar el archivo';
            return $out;
        }
        $out['ok']    = true;
        $out['ruta']  = $this->relativa($ruta);
        $out['bytes'] = strlen($binario);
        return $out;
    }

    /** Binario de una media guardada, o null. */
    public function leer(string $rutaRelativa): ?string
    {
        $base = \ElkinLinan\WhatsappAiEngine\Engine::archivos()->raiz();
        $abs  = $base . '/' . ltrim($rutaRelativa, '/');
        // Confinamiento: una ruta con ../ guardada por error no puede leer fuera.
        $real = realpath($abs);
        if ($real === false || strpos(str_replace('\\', '/', $real), str_replace('\\', '/', realpath($base))) !== 0) {
            return null;
        }
        $bin = @file_get_contents($real);
        return $bin === false ? null : $bin;
    }

    private static function extension(string $mime): string
    {
        $map = ['audio/ogg' => '.ogg', 'audio/mpeg' => '.mp3', 'audio/mp4' => '.m4a', 'audio/amr' => '.amr',
                'audio/wav' => '.wav', 'audio/webm' => '.webm',
                'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
        return $map[$mime] ?? '.bin';
    }

    /**
     * Purga la media vencida. La llama el cron.
     * Los comprobantes de pago se conservan el DOBLE de tiempo: son evidencia.
     */
    public function purgar(int $dias): int
    {
        if ($dias <= 0) return 0;
        $borrados = 0;
        $limite   = date('Y-m-d H:i:s', time() - $dias * 86400);
        $limiteComprobantes = date('Y-m-d H:i:s', time() - $dias * 2 * 86400);

        try {
            $comprobantes = array_column($this->db->fetchAll(
                "SELECT comprobante_media_ruta FROM wa_pagos WHERE comprobante_media_ruta IS NOT NULL"),
                'comprobante_media_ruta');
            $filas = $this->db->fetchAll(
                "SELECT id, media_ruta, created_at FROM wa_mensajes
                 WHERE media_ruta IS NOT NULL AND created_at < ?", [$limiteComprobantes < $limite ? $limite : $limite]);
        } catch (\Throwable $e) { return 0; }

        $base = \ElkinLinan\WhatsappAiEngine\Engine::archivos()->raiz();
        foreach ($filas as $f) {
            $esComprobante = in_array($f['media_ruta'], $comprobantes, true);
            if ($esComprobante && $f['created_at'] >= $limiteComprobantes) continue;
            $abs = $base . '/' . ltrim($f['media_ruta'], '/');
            if (is_file($abs) && @unlink($abs)) $borrados++;
            $this->db->query("UPDATE wa_mensajes SET media_ruta = NULL WHERE id = ?", [(int)$f['id']]);
        }
        if ($borrados && $this->log) $this->log->log('config', 'Media purgada', ['archivos' => $borrados]);
        return $borrados;
    }
}
