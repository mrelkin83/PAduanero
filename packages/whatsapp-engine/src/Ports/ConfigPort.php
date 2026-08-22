<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/** Las URL y rutas del proyecto que el motor necesita para construir enlaces. */
interface ConfigPort
{
    /** Base pública del sitio, sin barra final. */
    public function urlBase(): string;

    /**
     * URL del servidor de canal (Evolution API) provista por la PLATAFORMA,
     * común a todos los negocios. Cuando el hospedador la da, el negocio no la
     * escribe: solo pone su número. '' = cada negocio configura la suya.
     */
    public function canalUrlPorDefecto(): string;

    /**
     * API Key del servidor de canal provista por la PLATAFORMA. Secreto de
     * infraestructura: el negocio no la ve ni la teclea. '' = sin default.
     */
    public function canalApikeyPorDefecto(): string;

    /**
     * Servidor de VOZ (TTS) open source autoalojado de la PLATAFORMA (Piper).
     * Definido → el negocio contesta con voz sin configurar nada. '' = cada
     * negocio configura su propio TTS (o se queda sin voz).
     */
    public function ttsUrlPorDefecto(): string;
    /** Modelo/voz por defecto del TTS de plataforma. '' = el del servidor. */
    public function ttsModeloPorDefecto(): string;

    /**
     * Servidor de VISIÓN (imágenes) open source autoalojado de la PLATAFORMA.
     * Definido → el bot entiende las fotos que le mandan sin configurar nada.
     * '' = cae al proveedor de IA principal si sabe ver, o nada.
     */
    public function visionUrlPorDefecto(): string;
    /** Modelo por defecto de visión de plataforma. '' = 'moondream'. */
    public function visionModeloPorDefecto(): string;

    /**
     * Servidor de TRANSCRIPCIÓN (STT, audio→texto) open source autoalojado de la
     * PLATAFORMA (Whisper). Definido → el bot entiende las notas de voz que le
     * mandan sin configurar nada. '' = el negocio configura su propio STT o no
     * transcribe.
     */
    public function sttUrlPorDefecto(): string;
    /** Modelo por defecto del STT de plataforma. '' = el del servidor. */
    public function sttModeloPorDefecto(): string;
}
