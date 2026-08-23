<?php

declare(strict_types=1);

namespace App\Wa;

use ElkinLinan\WhatsappAiEngine\Channel\ChannelInterface;

/**
 * Canal que retiene los textos mientras la respuesta va a ser hablada.
 *
 * Existe porque el orquestador envía sus textos directamente por el canal, y
 * la voz se añadía DESPUÉS por fuera: el cliente que mandaba una nota de voz
 * recibía siempre el texto, y el audio —si llegaba— era un duplicado tardío.
 *
 * La regla del PO (2026-08-23): cuando el cliente habla, se le responde
 * hablando; por texto va únicamente lo que no se puede dictar — enlaces,
 * correos, horas, montos, referencias. Este decorador captura los
 * `enviarTexto()` del turno; el borde decide luego qué se sintetiza y qué se
 * entrega como texto (`esDatoDuro()`), y si la síntesis falla, todo cae a
 * texto — el cliente jamás se queda sin respuesta.
 *
 * Todo lo demás delega tal cual (audio, imágenes, webhooks, estado).
 */
final class CanalConVoz implements ChannelInterface
{
    /** @var list<array{telefono:string,texto:string}> */
    private array $retenidos = [];

    public function __construct(private readonly ChannelInterface $real)
    {
    }

    /** @return list<array{telefono:string,texto:string}> */
    public function retenidos(): array
    {
        return $this->retenidos;
    }

    /** ¿Este texto se dicta mal y debe ir escrito? */
    public static function esDatoDuro(string $texto): bool
    {
        return (bool) preg_match(
            '~https?://|[\w.+-]+@[\w-]+\.\w|\d{1,2}:\d{2}|\$\s?\d|\bCOP\b|\d{6,}~u',
            $texto,
        );
    }

    public function enviarTexto(string $telefono, string $texto): array
    {
        $this->retenidos[] = ['telefono' => $telefono, 'texto' => $texto];

        return ['ok' => true, 'message_id' => null, 'error' => ''];
    }

    public function nombre(): string
    {
        return $this->real->nombre();
    }

    public function requisitosFaltantes(): array
    {
        return $this->real->requisitosFaltantes();
    }

    public function estado(): array
    {
        return $this->real->estado();
    }

    public function conectar(): array
    {
        return $this->real->conectar();
    }

    public function desconectar(): array
    {
        return $this->real->desconectar();
    }

    public function registrarWebhook(string $url): array
    {
        return $this->real->registrarWebhook($url);
    }

    public function enviarAudio(string $telefono, string $audioBase64, string $mime = 'audio/ogg'): array
    {
        return $this->real->enviarAudio($telefono, $audioBase64, $mime);
    }

    public function enviarImagen(string $telefono, string $imagenBase64, string $caption = ''): array
    {
        return $this->real->enviarImagen($telefono, $imagenBase64, $caption);
    }

    public function normalizarWebhook(array $payload): ?array
    {
        return $this->real->normalizarWebhook($payload);
    }

    public function descargarMedia(array $mensaje): ?string
    {
        return $this->real->descargarMedia($mensaje);
    }
}
