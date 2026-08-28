<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Genera la URL firmada del reproductor de Bunny Stream. Es pura fórmula
 * HMAC local (documentada por Bunny) — esta clase nunca llama a la red.
 *
 * Sin credenciales configuradas, `disponible()` es false y quien la use debe
 * degradar sin tronar (mismo principio que TtsManager en
 * packages/whatsapp-engine: una dependencia externa ausente nunca rompe la
 * respuesta).
 */
final class BunnyStream
{
    public function __construct(
        private readonly string $libraryId,
        private readonly string $securityKey,
    ) {
    }

    public static function desdeEntorno(): self
    {
        return new self(
            Entorno::obtener('BUNNY_LIBRARY_ID', '') ?? '',
            Entorno::obtener('BUNNY_STREAM_SECURITY_KEY', '') ?? '',
        );
    }

    public function disponible(): bool
    {
        return $this->libraryId !== '' && $this->securityKey !== '';
    }

    /**
     * @param int $minutosVigencia cuánto dura el token una vez generado
     * @param int|null $expiraEn timestamp Unix exacto de vencimiento — solo
     *     para pruebas deterministas; en producción se omite y se calcula
     *     desde `$minutosVigencia`.
     */
    public function urlEmbed(string $videoId, int $minutosVigencia = 240, ?int $expiraEn = null): string
    {
        $expira = $expiraEn ?? (time() + $minutosVigencia * 60);
        $token = hash('sha256', $this->securityKey . $videoId . $expira);

        return sprintf(
            'https://iframe.mediadelivery.net/embed/%s/%s?token=%s&expires=%d',
            rawurlencode($this->libraryId),
            rawurlencode($videoId),
            $token,
            $expira,
        );
    }
}
