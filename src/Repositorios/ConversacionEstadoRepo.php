<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;
use App\Modelos\ConversacionEstado;
use App\Motor\Estados;

/**
 * Todo el SQL de `conversacion_estado`.
 *
 * Aquí viven dos mecanismos que el motor de referencia no tenía y que
 * `CLAUDE.md` §7 pide expresamente:
 *
 *  · **El buffer de ráfagas** (§7.5). En WhatsApp la gente manda cuatro
 *    mensajes seguidos. Sin buffer se disparan cuatro llamadas al modelo que
 *    se pisan entre sí: cuatro respuestas, cuatro cobros, y un hilo en el que
 *    el bot se contesta a sí mismo.
 *  · **El tope de turnos** (§7.7). Un contacto puede quemar presupuesto de
 *    LLM indefinidamente. `turnos` es lo que permite cortar.
 */
final class ConversacionEstadoRepo
{
    private const CAMPOS = 'id, chatwoot_conv_id, contacto_id, caso_id, prompt_version_id,
                            estado, ia_activa, pausada_hasta, historial, resumen_largo,
                            buffer_mensajes, buffer_hasta, turnos, tokens_consumidos,
                            ultimo_mensaje_en';

    public function __construct(private readonly BD $bd)
    {
    }

    public function porConversacion(int $chatwootConvId): ?ConversacionEstado
    {
        return $this->uno(
            'SELECT ' . self::CAMPOS . ' FROM conversacion_estado WHERE chatwoot_conv_id = ?',
            [$chatwootConvId],
        );
    }

    public function porCasoId(string $casoId): ?ConversacionEstado
    {
        return $this->uno(
            'SELECT ' . self::CAMPOS . ' FROM conversacion_estado WHERE caso_id = ?
              ORDER BY actualizado_en DESC LIMIT 1',
            [$casoId],
        );
    }

    /**
     * La fila de esta conversación, creándola si es la primera vez.
     *
     * Idempotente frente a concurrencia por el `UNIQUE` sobre
     * `chatwoot_conv_id`: dos webhooks simultáneos del primer mensaje no
     * pueden crear dos filas, y el que pierda la carrera relee la del otro en
     * vez de fallar.
     */
    public function buscarOCrear(int $chatwootConvId, ?string $contactoId = null): ConversacionEstado
    {
        $existente = $this->porConversacion($chatwootConvId);

        if ($existente !== null) {
            // El contacto puede conocerse después del primer mensaje.
            if ($contactoId !== null && $existente->contactoId === null) {
                $this->bd->pdo()
                    ->prepare('UPDATE conversacion_estado SET contacto_id = ? WHERE id = ?')
                    ->execute([$contactoId, $existente->id]);

                return $this->porConversacion($chatwootConvId) ?? $existente;
            }

            return $existente;
        }

        $pdo = $this->bd->pdo();
        $id = (string) $pdo->query('SELECT UUID()')->fetchColumn();

        try {
            $pdo->prepare(
                'INSERT INTO conversacion_estado
                    (id, chatwoot_conv_id, contacto_id, estado, historial, buffer_mensajes)
                 VALUES (?, ?, ?, ?, \'[]\', \'[]\')'
            )->execute([$id, $chatwootConvId, $contactoId, Estados::NUEVO->value]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            return $this->porConversacion($chatwootConvId)
                ?? throw new \RuntimeException('Carrera irresoluble creando la conversación.');
        }

        return $this->porConversacion($chatwootConvId)
            ?? throw new \RuntimeException('La conversación no se pudo releer.');
    }

    /**
     * Guarda un turno completo.
     *
     * `turnos` y `tokens_consumidos` se **incrementan en SQL** y no se leen y
     * reescriben desde PHP: dos peticiones a la vez leerían el mismo valor y
     * una de las dos se perdería, que es como el tope de turnos deja de
     * cortar sin que nadie lo note.
     *
     * @param list<array{role:string,content:string}> $historial
     */
    public function guardarTurno(
        int $chatwootConvId,
        array $historial,
        int $tokens = 0,
        ?string $estado = null,
        ?string $casoId = null,
        ?string $promptVersionId = null,
        ?string $resumenLargo = null,
    ): void {
        $this->bd->pdo()->prepare(
            'UPDATE conversacion_estado
                SET historial          = ?,
                    estado             = COALESCE(?, estado),
                    caso_id            = COALESCE(?, caso_id),
                    prompt_version_id  = COALESCE(?, prompt_version_id),
                    resumen_largo      = COALESCE(?, resumen_largo),
                    turnos             = turnos + 1,
                    tokens_consumidos  = tokens_consumidos + ?,
                    ultimo_mensaje_en  = NOW(),
                    buffer_mensajes    = \'[]\',
                    buffer_hasta       = NULL
              WHERE chatwoot_conv_id = ?'
        )->execute([
            json_encode(array_values($historial), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $estado,
            $casoId,
            $promptVersionId,
            $resumenLargo,
            max(0, $tokens),
            $chatwootConvId,
        ]);
    }

    /**
     * Añade un mensaje al buffer de ráfaga y abre la ventana si no lo estaba.
     *
     * El motor no responde hasta que la ventana vence; entonces trata los
     * mensajes acumulados como un solo turno. `buffer_hasta` solo se fija la
     * primera vez: si se moviera con cada mensaje, alguien escribiendo sin
     * parar dejaría al bot mudo indefinidamente.
     *
     * @return int cuántos mensajes hay ahora en el buffer
     */
    public function acumularBuffer(int $chatwootConvId, string $mensaje, int $ventanaSegundos): int
    {
        $pdo = $this->bd->pdo();

        $pdo->prepare(
            'UPDATE conversacion_estado
                SET buffer_mensajes = JSON_ARRAY_APPEND(
                        COALESCE(NULLIF(buffer_mensajes, JSON_QUOTE(\'\')), \'[]\'), \'$\', ?),
                    buffer_hasta = COALESCE(buffer_hasta, DATE_ADD(NOW(), INTERVAL ? SECOND)),
                    ultimo_mensaje_en = NOW()
              WHERE chatwoot_conv_id = ?'
        )->execute([$mensaje, max(1, $ventanaSegundos), $chatwootConvId]);

        $stmt = $pdo->prepare(
            'SELECT JSON_LENGTH(buffer_mensajes) FROM conversacion_estado WHERE chatwoot_conv_id = ?'
        );
        $stmt->execute([$chatwootConvId]);

        return (int) $stmt->fetchColumn();
    }

    /** ¿Venció la ventana de ráfaga y hay algo que responder? */
    public function bufferListo(int $chatwootConvId): bool
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT 1 FROM conversacion_estado
              WHERE chatwoot_conv_id = ?
                AND buffer_hasta IS NOT NULL
                AND buffer_hasta <= NOW()
                AND JSON_LENGTH(buffer_mensajes) > 0'
        );
        $stmt->execute([$chatwootConvId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<int> conversaciones con ráfaga vencida, para el worker */
    public function conBufferVencido(int $limite = 50): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT chatwoot_conv_id FROM conversacion_estado
              WHERE buffer_hasta IS NOT NULL AND buffer_hasta <= NOW()
                AND JSON_LENGTH(buffer_mensajes) > 0
              ORDER BY buffer_hasta LIMIT ' . max(1, min(500, $limite))
        );
        $stmt->execute();

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function cambiarEstado(int $chatwootConvId, Estados $estado): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE conversacion_estado SET estado = ? WHERE chatwoot_conv_id = ?')
            ->execute([$estado->value, $chatwootConvId]);
    }

    /**
     * Apaga la IA en esta conversación (regla 8).
     *
     * La llama el escalamiento y también el webhook cuando un agente humano
     * escribe. **No hay ningún camino que la vuelva a encender sola**: eso es
     * `reactivarIa()`, y solo lo invoca una acción explícita de una persona.
     */
    public function apagarIa(int $chatwootConvId, Estados $estado = Estados::HUMANO): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE conversacion_estado SET ia_activa = 0, estado = ?, pausada_hasta = NULL
              WHERE chatwoot_conv_id = ?'
        )->execute([$estado->value, $chatwootConvId]);
    }

    /** Reactivación explícita. Nunca automática dentro de la misma sesión. */
    public function reactivarIa(int $chatwootConvId, Estados $estado = Estados::TRIAGE): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE conversacion_estado SET ia_activa = 1, estado = ?, pausada_hasta = NULL
              WHERE chatwoot_conv_id = ?'
        )->execute([$estado->value, $chatwootConvId]);
    }

    /**
     * Pausa temporal, distinta de apagar.
     *
     * Sirve para el respiro tras un error técnico: la IA calla un rato y
     * vuelve sola. Apagar por escalamiento no vuelve solo nunca — son dos
     * mecanismos distintos y por eso son dos columnas distintas.
     */
    public function pausar(int $chatwootConvId, int $minutos): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE conversacion_estado SET pausada_hasta = DATE_ADD(NOW(), INTERVAL ? MINUTE)
              WHERE chatwoot_conv_id = ?'
        )->execute([max(1, $minutos), $chatwootConvId]);
    }

    public function vincularCaso(int $chatwootConvId, string $casoId): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE conversacion_estado SET caso_id = ? WHERE chatwoot_conv_id = ?')
            ->execute([$casoId, $chatwootConvId]);
    }

    /** @param list<mixed> $parametros */
    private function uno(string $sql, array $parametros): ?ConversacionEstado
    {
        $stmt = $this->bd->pdo()->prepare($sql);
        $stmt->execute($parametros);
        $fila = $stmt->fetch();

        return $fila === false ? null : ConversacionEstado::desdeFila($fila);
    }
}
