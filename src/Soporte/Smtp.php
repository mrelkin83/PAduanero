<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Cliente SMTP mínimo, en PHP puro.
 *
 * Existe porque este proyecto no lleva dependencias de producción (CLAUDE.md
 * §1: todo lo que usa este sistema viene con PHP) y lo único que necesita
 * enviar son textos cortos — el recordatorio de una cita. PHPMailer resuelve
 * mil casos que aquí no ocurren, a cambio de una dependencia que custodiar.
 *
 * Hace exactamente esto: STARTTLS en 587 (o TLS implícito en 465),
 * AUTH LOGIN, un destinatario, texto plano UTF-8. Nada de adjuntos, HTML ni
 * varios destinatarios: el día que hagan falta, se reevalúa la dependencia.
 *
 * `desdeEntorno()` devuelve null si SMTP_HOST está vacío — que es el estado
 * del VPS mientras el SMTP siga pendiente. Quien lo usa trata el null como
 * «sin correo», no como error: el recordatorio de WhatsApp sale igual.
 */
final class Smtp
{
    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $usuario,
        private readonly string $clave,
        private readonly string $desde,
    ) {
    }

    public static function desdeEntorno(): ?self
    {
        $host = trim((string) (Entorno::obtener('SMTP_HOST', '') ?? ''));
        if ($host === '') {
            return null;
        }

        return new self(
            $host,
            (int) (Entorno::obtener('SMTP_PORT', '587') ?? 587),
            (string) (Entorno::obtener('SMTP_USER', '') ?? ''),
            (string) (Entorno::obtener('SMTP_PASS', '') ?? ''),
            (string) (Entorno::obtener('SMTP_DESDE', '') ?? ''),
        );
    }

    /** Envía texto plano. Devuelve false ante cualquier tropiezo, sin excepción. */
    public function enviar(string $para, string $asunto, string $texto): bool
    {
        $para = trim($para);
        if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL) || $this->desde === '') {
            return false;
        }

        try {
            $implicito = $this->puerto === 465;
            $s = @stream_socket_client(
                ($implicito ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->puerto,
                $errno,
                $error,
                10,
            );
            if ($s === false) {
                return false;
            }
            stream_set_timeout($s, 15);

            if (!$this->espera($s, '220')) {
                return $this->cerrar($s);
            }
            $this->manda($s, 'EHLO ' . gethostname());
            if (!$this->espera($s, '250')) {
                return $this->cerrar($s);
            }

            if (!$implicito) {
                $this->manda($s, 'STARTTLS');
                if (!$this->espera($s, '220')) {
                    return $this->cerrar($s);
                }
                if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return $this->cerrar($s);
                }
                // Tras STARTTLS el diálogo empieza de nuevo.
                $this->manda($s, 'EHLO ' . gethostname());
                if (!$this->espera($s, '250')) {
                    return $this->cerrar($s);
                }
            }

            if ($this->usuario !== '') {
                $this->manda($s, 'AUTH LOGIN');
                if (!$this->espera($s, '334')) {
                    return $this->cerrar($s);
                }
                $this->manda($s, base64_encode($this->usuario));
                if (!$this->espera($s, '334')) {
                    return $this->cerrar($s);
                }
                $this->manda($s, base64_encode($this->clave));
                if (!$this->espera($s, '235')) {
                    return $this->cerrar($s);
                }
            }

            $this->manda($s, 'MAIL FROM:<' . $this->desde . '>');
            if (!$this->espera($s, '250')) {
                return $this->cerrar($s);
            }
            $this->manda($s, 'RCPT TO:<' . $para . '>');
            if (!$this->espera($s, '250')) {
                return $this->cerrar($s);
            }
            $this->manda($s, 'DATA');
            if (!$this->espera($s, '354')) {
                return $this->cerrar($s);
            }

            $cuerpo = implode("\r\n", [
                'From: <' . $this->desde . '>',
                'To: <' . $para . '>',
                'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->host . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                '',
                // Base64 del cuerpo entero: evita tener que escapar los puntos
                // iniciales y los finales de línea del protocolo.
                chunk_split(base64_encode($texto)),
            ]);
            $this->manda($s, $cuerpo . "\r\n.");
            $ok = $this->espera($s, '250');
            $this->manda($s, 'QUIT');
            fclose($s);

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param resource $s */
    private function manda($s, string $linea): void
    {
        fwrite($s, $linea . "\r\n");
    }

    /**
     * Lee la respuesta (con sus líneas de continuación «250-…») y comprueba
     * el código.
     *
     * @param resource $s
     */
    private function espera($s, string $codigo): bool
    {
        $linea = '';
        do {
            $linea = (string) fgets($s, 1024);
        } while ($linea !== '' && isset($linea[3]) && $linea[3] === '-');

        return str_starts_with($linea, $codigo);
    }

    /** @param resource $s */
    private function cerrar($s): bool
    {
        @fclose($s);

        return false;
    }
}
