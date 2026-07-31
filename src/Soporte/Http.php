<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Cliente HTTP sobre cURL nativo, sin SDK (docs/CONTRATOS.md).
 *
 * Lo comparten el probador de pasarela y el aprovisionamiento de agentes en
 * Chatwoot. Existe para no repetir el manejo de timeouts y de errores de red
 * en cada integración: un `curl_exec` suelto que no distingue «el servidor
 * dijo que no» de «no hubo servidor» produce diagnósticos equivocados.
 */
class Http
{
    // No es `final` a propósito: los probadores de integraciones se prueban
    // sustituyendo `pedir()` por respuestas preparadas. Sin eso habría que
    // salir a la red de Wompi en cada corrida de la suite.
    public function __construct(
        private readonly int $timeoutSegundos = 15,
    ) {
    }

    /**
     * @param array<string,string>     $cabeceras
     * @param array<string,mixed>|null $json cuerpo a enviar como JSON
     */
    public function pedir(
        string $metodo,
        string $url,
        array $cabeceras = [],
        ?array $json = null,
    ): RespuestaHttp {
        $ch = curl_init($url);

        $lineas = [];
        foreach ($cabeceras as $nombre => $valor) {
            $lineas[] = "{$nombre}: {$valor}";
        }

        $opciones = [
            CURLOPT_CUSTOMREQUEST => strtoupper($metodo),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSegundos,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $lineas,
            // No seguir redirecciones: una pasarela que redirige es una señal
            // de que la URL está mal, no algo que haya que perseguir.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($json !== null) {
            $cuerpo = json_encode($json, JSON_UNESCAPED_UNICODE);
            $opciones[CURLOPT_POSTFIELDS] = $cuerpo === false ? '{}' : $cuerpo;
            $opciones[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, $opciones);

        $inicio = microtime(true);
        $cuerpo = curl_exec($ch);
        $latencia = (int) round((microtime(true) - $inicio) * 1000);

        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorRed = curl_errno($ch) !== 0 ? curl_error($ch) : null;

        curl_close($ch);

        return new RespuestaHttp(
            estado: $estado,
            cuerpo: is_string($cuerpo) ? $cuerpo : '',
            errorRed: $errorRed,
            latenciaMs: $latencia,
        );
    }
}
