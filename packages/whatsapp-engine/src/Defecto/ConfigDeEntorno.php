<?php

namespace ElkinLinan\WhatsappAiEngine\Defecto;
use ElkinLinan\WhatsappAiEngine\Ports\ConfigPort;

/**
 * ConfigPort por defecto: lee la configuración de PLATAFORMA del entorno.
 *
 * Es el mismo comportamiento que el módulo de WhatsApp IA trae de fábrica en
 * las pantallas de Conexión y de Voz: si el hospedador define estas constantes
 * (o variables de entorno), el negocio no configura nada —
 *
 *   APP_URL              → base pública del sitio (enlaces del bot)
 *   WA_EVOLUTION_URL     → servidor Evolution de la plataforma (Conexión)
 *   WA_EVOLUTION_APIKEY  → su API Key (secreto del operador, el negocio no la ve)
 *   WA_TTS_URL/MODELO    → voz open source de la plataforma (Piper)
 *   WA_STT_URL/MODELO    → transcripción open source (Whisper/speaches)
 *   WA_VISION_URL/MODELO → visión open source (Ollama y compatibles)
 *
 * Sin nada definido, todo devuelve '' y el paquete se comporta como siempre:
 * cada negocio configura lo suyo. Para negar los defaults aunque el entorno
 * los tenga, se pasa Defecto\SinUrl explícitamente.
 */
final class ConfigDeEntorno implements ConfigPort
{
    /** Constante definida o variable de entorno; '' si no hay ninguna. */
    private static function valor(string $nombre): string
    {
        if (defined($nombre)) return (string)constant($nombre);
        $v = getenv($nombre);
        return $v === false ? '' : (string)$v;
    }

    private static function url(string $nombre): string
    {
        return rtrim(self::valor($nombre), '/');
    }

    public function urlBase(): string { return self::url('APP_URL'); }

    public function canalUrlPorDefecto(): string    { return self::url('WA_EVOLUTION_URL'); }
    public function canalApikeyPorDefecto(): string { return self::valor('WA_EVOLUTION_APIKEY'); }

    public function ttsUrlPorDefecto(): string    { return self::url('WA_TTS_URL'); }
    public function ttsModeloPorDefecto(): string { return self::valor('WA_TTS_MODELO'); }

    public function visionUrlPorDefecto(): string    { return self::url('WA_VISION_URL'); }
    public function visionModeloPorDefecto(): string { return self::valor('WA_VISION_MODELO'); }

    public function sttUrlPorDefecto(): string    { return self::url('WA_STT_URL'); }
    public function sttModeloPorDefecto(): string { return self::valor('WA_STT_MODELO'); }
}
