<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Excepciones\ChatwootNoDisponible;
use App\Modelos\Usuario;
use App\Soporte\Http;
use App\Soporte\Logger;
use App\Soporte\RespuestaHttp;

/**
 * Implementación sobre la API v1 de Chatwoot. cURL nativo, sin SDK.
 *
 * MODO SOMBRA
 *
 * `entregar()` lee `motor_modo_sombra` y decide. Esa consulta vive aquí y en
 * ningún otro sitio: si estuviera en cada punto que quiere hablar, bastaría un
 * olvido para que un borrador sin revisar saliera hacia un cliente. Hay una
 * prueba de arquitectura que comprueba que `src/Motor/` no llama a
 * `responder()` directamente.
 *
 * REINTENTOS Y DUPLICADOS
 *
 * Tres intentos con espera creciente (docs/CONTRATOS.md), y solo ante fallos
 * que indican que la petición **no llegó a procesarse**: error de red, 502,
 * 503, 504, 429. Un **500 no se reintenta**, y esa distinción es la que
 * protege de duplicar: un 500 significa que la aplicación de Chatwoot corrió y
 * pudo haber creado el mensaje antes de romperse; repetir la petición pondría
 * el mismo mensaje dos veces en el hilo de un cliente.
 *
 * El riesgo residual es real y conviene tenerlo escrito: un 504 puede llegar
 * después de que Chatwoot creara el mensaje. Se acepta a conciencia, porque el
 * compromiso del ADR-004 es «nunca se pierde» y entre perder un mensaje y
 * repetirlo, repetirlo es el daño menor. En modo sombra ni siquiera es
 * visible: son dos borradores para Pedro.
 */
final class ChatwootApi implements Chatwoot
{
    private const MAX_INTENTOS = 3;

    /** Espera antes de cada reintento, en milisegundos. */
    private const ESPERAS_MS = [200, 600];

    /** Prefijo de la nota en modo sombra. Ver `entregar()`. */
    public const MARCA_SOMBRA = '🤖 BORRADOR DEL MOTOR — no se ha enviado al contacto';

    /** @var callable(int):void */
    private $dormir;

    public function __construct(
        private readonly Http $http,
        private readonly Config $config,
        private readonly Logger $log,
        private readonly string $url,
        private readonly string $cuentaId,
        private readonly string $token,
        /** `chatwoot_agent_id` de Pedro, para asignarle los escalamientos. */
        private readonly ?int $agenteAbogadoId = null,
        // Inyectable para que las pruebas no esperen de verdad. Sin esto, la
        // suite tardaría casi un segundo por cada caso de reintento.
        ?callable $dormir = null,
    ) {
        $this->dormir = $dormir ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function configurado(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    /**
     * El único método que el motor debe llamar para hablar.
     *
     * La marca del borrador no es decorativa: sin ella, una nota privada y un
     * mensaje enviado se parecen demasiado en la bandeja, y el día que se
     * active el envío automático nadie sabrá mirando el hilo cuál de los dos
     * está viendo.
     *
     * @return array{id:int,sombra:bool}
     */
    public function entregar(int $conversacionId, string $texto): array
    {
        // Por defecto TRUE. Si la clave falta, se rompió, o alguien la borró,
        // el comportamiento seguro es no enviarle nada a un cliente.
        $sombra = $this->config->get('motor_modo_sombra', true);
        $sombra = !($sombra === false || $sombra === 'false' || $sombra === 0 || $sombra === '0');

        if ($sombra) {
            return [
                'id' => $this->notaPrivada($conversacionId, self::MARCA_SOMBRA . "\n\n" . $texto),
                'sombra' => true,
            ];
        }

        return ['id' => $this->responder($conversacionId, $texto), 'sombra' => false];
    }

    public function responder(int $conversacionId, string $texto): int
    {
        return $this->crearMensaje($conversacionId, $texto, 'outgoing', privado: false);
    }

    public function notaPrivada(int $conversacionId, string $texto): int
    {
        return $this->crearMensaje($conversacionId, $texto, 'outgoing', privado: true);
    }

    public function etiquetar(int $conversacionId, array $etiquetas): void
    {
        $limpias = [];

        foreach ($etiquetas as $etiqueta) {
            // Chatwoot normaliza a minúsculas y guiones; hacerlo aquí evita
            // acabar con «Urgente» y «urgente» como etiquetas distintas.
            $limpia = preg_replace('/[^a-z0-9_-]+/', '-', mb_strtolower(trim($etiqueta)));
            $limpia = trim((string) $limpia, '-');

            if ($limpia !== '') {
                $limpias[] = mb_substr($limpia, 0, 40);
            }
        }

        if ($limpias === []) {
            return;
        }

        $this->intentar(
            'POST',
            "/conversations/{$conversacionId}/labels",
            ['labels' => array_values(array_unique($limpias))],
            'etiquetar',
        );
    }

    public function cambiarPrioridad(int $conversacionId, string $prioridad): void
    {
        if (!in_array($prioridad, ['urgent', 'high', 'medium', 'low'], true)) {
            throw new \InvalidArgumentException("Prioridad desconocida: {$prioridad}");
        }

        $this->intentar(
            'POST',
            "/conversations/{$conversacionId}/toggle_priority",
            ['priority' => $prioridad],
            'prioridad',
        );
    }

    /**
     * Asigna la conversación a Pedro.
     *
     * Si no hay agente configurado no se inventa uno: se registra y se sigue.
     * Un escalamiento sin asignar es peor atendido, pero un escalamiento que
     * revienta por no encontrar el agente no se atiende en absoluto.
     */
    public function asignarAlAbogado(int $conversacionId): void
    {
        if ($this->agenteAbogadoId === null) {
            $this->log->warn('chatwoot.sin_agente_abogado', ['conversacion' => $conversacionId]);

            return;
        }

        $this->intentar(
            'POST',
            "/conversations/{$conversacionId}/assignments",
            ['assignee_id' => $this->agenteAbogadoId],
            'asignar',
        );
    }

    public function cambiarEstado(int $conversacionId, string $estado): void
    {
        if (!in_array($estado, ['open', 'pending', 'resolved'], true)) {
            throw new \InvalidArgumentException("Estado de conversación desconocido: {$estado}");
        }

        $this->intentar(
            'POST',
            "/conversations/{$conversacionId}/toggle_status",
            ['status' => $estado],
            'estado',
        );
    }

    /**
     * Atributos personalizados del contacto en Chatwoot.
     *
     * Lo que se escribe aquí lo ve cualquier agente en la ficha lateral. **No
     * se manda el puntaje de lead** (`CLAUDE.md` §3.2 dice que es interno) ni
     * nada cifrado (regla 13): quien llame a esto elige qué mandar, y eso
     * queda dicho para que nadie meta aquí el NIT por comodidad.
     *
     * @param array<string,mixed> $atributos
     */
    public function setAtributos(int $contactoId, array $atributos): void
    {
        if ($atributos === []) {
            return;
        }

        $this->intentar(
            'PUT',
            "/contacts/{$contactoId}",
            ['custom_attributes' => $atributos],
            'atributos',
        );
    }

    public function sincronizarAgente(Usuario $usuario): int
    {
        if ($usuario->chatwootAgentId !== null) {
            return $usuario->chatwootAgentId;
        }

        $respuesta = $this->intentar(
            'POST',
            '/agents',
            [
                'name' => $usuario->nombre,
                'email' => $usuario->email,
                'role' => $usuario->rol === 'super_admin' ? 'administrator' : 'agent',
            ],
            'alta_agente',
        );

        $id = $respuesta->json()['id'] ?? null;

        if (!is_int($id)) {
            throw new ChatwootNoDisponible(
                'Chatwoot no devolvió el id del agente.',
                agotoReintentos: false,
            );
        }

        return $id;
    }

    private function crearMensaje(int $conversacionId, string $texto, string $tipo, bool $privado): int
    {
        $limpio = trim($texto);

        if ($limpio === '') {
            throw new \InvalidArgumentException('No se envía un mensaje vacío a Chatwoot.');
        }

        $respuesta = $this->intentar(
            'POST',
            "/conversations/{$conversacionId}/messages",
            ['content' => $limpio, 'message_type' => $tipo, 'private' => $privado],
            $privado ? 'nota_privada' : 'mensaje',
        );

        $id = $respuesta->json()['id'] ?? null;

        if (!is_int($id)) {
            // Chatwoot aceptó pero no devolvió id. El mensaje probablemente
            // está en el hilo, así que NO se reintenta: se registra y se
            // devuelve 0, que quien llame interpretará como «no hay id que
            // guardar», no como «no se escribió».
            $this->log->warn('chatwoot.mensaje_sin_id', ['conversacion' => $conversacionId]);

            return 0;
        }

        return $id;
    }

    /**
     * Petición con reintentos.
     *
     * Solo se reintenta lo que indica que la petición no llegó a procesarse.
     * Ver la nota de duplicados en la cabecera de la clase: un 500 se deja
     * pasar sin reintentar a propósito.
     *
     * @param  array<string,mixed> $cuerpo
     * @throws ChatwootNoDisponible
     */
    private function intentar(string $metodo, string $ruta, array $cuerpo, string $operacion): RespuestaHttp
    {
        if (!$this->configurado()) {
            throw new ChatwootNoDisponible(
                'Chatwoot no está configurado: faltan CHATWOOT_URL o CHATWOOT_BOT_TOKEN.',
                agotoReintentos: false,
            );
        }

        $url = "{$this->url}/api/v1/accounts/{$this->cuentaId}{$ruta}";
        $ultimo = '';

        for ($intento = 1; $intento <= self::MAX_INTENTOS; $intento++) {
            $respuesta = $this->http->pedir(
                $metodo,
                $url,
                ['api_access_token' => $this->token, 'accept' => 'application/json'],
                $cuerpo,
            );

            if ($respuesta->ok()) {
                return $respuesta;
            }

            $ultimo = $respuesta->errorRed ?? ('HTTP ' . $respuesta->estado);

            if (!self::reintentable($respuesta)) {
                $this->log->warn('chatwoot.rechazo', [
                    'operacion' => $operacion,
                    'estado' => $respuesta->estado,
                    'intento' => $intento,
                ]);

                throw new ChatwootNoDisponible(
                    "Chatwoot rechazó «{$operacion}»: {$ultimo}",
                    agotoReintentos: false,
                    intentos: $intento,
                );
            }

            if ($intento < self::MAX_INTENTOS) {
                ($this->dormir)(self::ESPERAS_MS[$intento - 1] ?? 600);
            }
        }

        $this->log->error('chatwoot.sin_respuesta', [
            'operacion' => $operacion,
            'intentos' => self::MAX_INTENTOS,
            'ultimo' => $ultimo,
        ]);

        throw new ChatwootNoDisponible(
            "Chatwoot no respondió a «{$operacion}» tras " . self::MAX_INTENTOS . " intentos: {$ultimo}",
            agotoReintentos: true,
            intentos: self::MAX_INTENTOS,
        );
    }

    /**
     * ¿Merece otro intento?
     *
     * Sí cuando la petición no llegó a procesarse: sin respuesta (red, DNS,
     * timeout), 429, o un error de pasarela. No cuando Chatwoot corrió y
     * falló —500— porque pudo haber creado el mensaje, ni ante 4xx, que van a
     * fallar igual.
     */
    private static function reintentable(RespuestaHttp $respuesta): bool
    {
        if ($respuesta->errorRed !== null) {
            return true;
        }

        return $respuesta->estado === 429
            || in_array($respuesta->estado, [502, 503, 504], true);
    }
}
