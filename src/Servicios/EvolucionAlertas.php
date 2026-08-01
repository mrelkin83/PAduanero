<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Soporte\Http;
use App\Soporte\Logger;

/**
 * WhatsApp directo al número personal de Pedro. **Solo alertas internas.**
 *
 * Es la única excepción del ADR-001, y el alcance es estrecho a propósito:
 * todo lo que va a un **cliente** sale por Chatwoot, para que quede en el hilo
 * y el traspaso a humano sea instantáneo. Aquí solo salen avisos al abogado —
 * un escalamiento urgente, un modelo primario retirado— que no deben
 * contaminar la bandeja de clientes.
 *
 * Hay una prueba de arquitectura que comprueba que `src/Motor/` no menciona
 * Evolution: el motor no puede empezar a usar este camino para hablarle a
 * nadie.
 *
 * **Regla 14.** Los mensajes que se componen aquí no llevan texto del
 * contacto. Un escalamiento sin consentimiento vigente no puede dejar el
 * contenido del caso en el historial de WhatsApp del teléfono personal de
 * Pedro, que es un dispositivo que este sistema no controla ni puede purgar.
 */
final class EvolucionAlertas
{
    public function __construct(
        private readonly Http $http,
        private readonly Logger $log,
        private readonly string $url,
        private readonly string $instancia,
        private readonly string $apiKey,
        /** Número personal de Pedro, en E.164 sin `+`. */
        private readonly string $numeroAbogado,
    ) {
    }

    public function configurado(): bool
    {
        return $this->url !== '' && $this->apiKey !== '' && $this->numeroAbogado !== '';
    }

    /**
     * Envía el aviso. Devuelve si se entregó.
     *
     * No lanza: quien la llama es el manejador del outbox, que ya decide qué
     * hacer con un `false` según su propio contrato de reintentos.
     */
    public function avisar(string $texto): bool
    {
        if (!$this->configurado()) {
            $this->log->warn('evolution.sin_configurar');

            return false;
        }

        $respuesta = $this->http->pedir(
            'POST',
            rtrim($this->url, '/') . '/message/sendText/' . rawurlencode($this->instancia),
            ['apikey' => $this->apiKey, 'accept' => 'application/json'],
            ['number' => $this->numeroAbogado, 'text' => $texto],
        );

        if ($respuesta->ok()) {
            return true;
        }

        // El 503 de Evolution significa «pendiente de activación» a partir de
        // la 2.4.0, y el remedio es distinto del resto de fallos: hay que
        // entrar al Manager. Se distingue en el registro para que
        // `bin/salud.sh` y quien lea los logs no busquen donde no es.
        $this->log->warn('evolution.alerta_fallida', [
            'estado' => $respuesta->estado,
            'error_red' => $respuesta->errorRed,
            'pista' => $respuesta->estado === 503
                ? 'posible instancia pendiente de activación (ver docs/DESPLIEGUE_CANALES.md §2.1)'
                : null,
        ]);

        return false;
    }
}
