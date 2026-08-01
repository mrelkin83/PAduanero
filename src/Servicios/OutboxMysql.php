<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Modelos\EventoOutbox;
use App\Motor\MotivoEscalamiento;

/**
 * Outbox sobre MySQL 8.
 *
 * TRES DECISIONES QUE SOSTIENEN «NUNCA SE PIERDE NI SE DUPLICA»
 *
 *  1. **`FOR UPDATE SKIP LOCKED` al reclamar.** Dos workers en paralelo —o el
 *     mismo worker relanzado por el cron antes de que el anterior terminara—
 *     nunca toman el mismo evento. Sin `SKIP LOCKED`, el segundo se quedaría
 *     bloqueado esperando; con él, se lleva los siguientes y ambos avanzan.
 *
 *  2. **Las comparaciones de tiempo van en SQL.** `disponible_en <= NOW()` y
 *     los `DATE_ADD` del backoff se resuelven del lado de la base, donde
 *     escribir y comparar usan el mismo reloj. Calcular la fecha en PHP y
 *     mandarla como parámetro habría metido la conversión UTC/Bogotá en el
 *     camino, que es el error 17 de CONTRATOS y falla hacia el lado
 *     peligroso: un evento programado a cinco horas vista se despacharía de
 *     inmediato, o al revés, uno vencido no se tomaría nunca.
 *
 *  3. **`recuperarAtascados()`.** Un worker que muere entre reclamar y
 *     despachar deja el evento en `procesando` para siempre. Sin esta
 *     recuperación, «nunca se pierde» sería falso — y falso en silencio, que
 *     es lo peor: la cola se ve vacía de pendientes.
 *
 * El riesgo residual es la otra cara: un evento recuperado pudo haberse
 * entregado ya. Se acepta, por lo mismo que en `ChatwootApi` — entre perder
 * un aviso y repetirlo, se repite.
 */
final class OutboxMysql implements Outbox
{
    /**
     * Espera antes de cada reintento, en minutos.
     *
     * 1m para el hipo de red, y de ahí subiendo hasta 6h. Cinco reintentos:
     * si algo lleva ocho horas sin poder entregarse, el problema no se va a
     * arreglar solo y lo que hace falta es que alguien lo vea en el panel, no
     * que la cola siga golpeando.
     *
     * @var list<int>
     */
    public const ESPERAS_MIN = [1, 5, 15, 60, 360];

    public function __construct(private readonly BD $bd)
    {
    }

    public function encolar(string $tipo, array $payload, int $retrasoSegundos = 0): int
    {
        $pdo = $this->bd->pdo();

        $pdo->prepare(
            'INSERT INTO eventos_outbox (tipo, payload, disponible_en)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([
            $tipo,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            max(0, $retrasoSegundos),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Regla 14: sin carga útil.
     *
     * El payload se construye aquí a partir de argumentos tipados. No hay
     * ningún parámetro por el que quepa el texto del mensaje, y esa es toda
     * la razón de que este método exista en vez de un `encolar()` genérico.
     *
     * El teléfono sí va: es lo que permite que la alerta llegue, y la regla 14
     * lo autoriza expresamente junto al motivo, la marca de tiempo y el
     * `chatwoot_conv_id`.
     */
    public function encolarAlertaEscalamiento(
        string $telefonoContacto,
        MotivoEscalamiento $motivo,
        int $chatwootConvId,
    ): int {
        return $this->encolar('alerta.escalamiento', [
            'telefono' => $telefonoContacto,
            'motivo' => $motivo->value,
            'urgente' => $motivo->esUrgente(),
            'chatwoot_conv_id' => $chatwootConvId,
        ]);
    }

    public function tomar(int $limite = 20): array
    {
        $limite = max(1, min(200, $limite));
        $pdo = $this->bd->pdo();

        $pdo->beginTransaction();

        try {
            // SKIP LOCKED: el worker que llega segundo no espera, se lleva los
            // siguientes. Sin esto, dos workers se serializan y la cola avanza
            // a la mitad de velocidad justo cuando hay atasco.
            $stmt = $pdo->prepare(
                "SELECT id FROM eventos_outbox
                  WHERE estado = 'pendiente' AND disponible_en <= NOW()
                  ORDER BY disponible_en, id
                  LIMIT {$limite}
                  FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute();
            $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if ($ids === []) {
                $pdo->commit();

                return [];
            }

            $huecos = implode(',', array_fill(0, count($ids), '?'));

            // `disponible_en = NOW()` al reclamar: en una fila `procesando`
            // esa columna pasa a significar «cuándo se tomó», que es lo que
            // `recuperarAtascados()` necesita saber. Se reutiliza en vez de
            // añadir `tomado_en` porque un cambio de esquema requiere
            // aprobación del PO y esta columna ya no se usa para otra cosa
            // mientras el evento está en vuelo.
            $pdo->prepare(
                "UPDATE eventos_outbox
                    SET estado = 'procesando', intentos = intentos + 1, disponible_en = NOW()
                  WHERE id IN ({$huecos})"
            )->execute($ids);

            $stmt = $pdo->prepare(
                "SELECT * FROM eventos_outbox WHERE id IN ({$huecos}) ORDER BY id"
            );
            $stmt->execute($ids);
            $filas = $stmt->fetchAll();

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return array_map(
            static fn (array $f): EventoOutbox => EventoOutbox::desdeFila($f),
            $filas,
        );
    }

    public function marcarEnviado(int $id): void
    {
        $this->bd->pdo()->prepare(
            "UPDATE eventos_outbox SET estado = 'enviado', procesado_en = NOW(), ultimo_error = NULL
              WHERE id = ?"
        )->execute([$id]);
    }

    /**
     * Devuelve el evento a la cola con la siguiente espera.
     *
     * Agotadas las esperas, pasa a `fallido`: seguir golpeando cada seis horas
     * un endpoint que lleva un día rechazando no arregla nada y esconde el
     * problema entre reintentos.
     */
    public function reprogramar(int $id, string $error): void
    {
        $stmt = $this->bd->pdo()->prepare('SELECT intentos FROM eventos_outbox WHERE id = ?');
        $stmt->execute([$id]);
        $intentos = (int) $stmt->fetchColumn();

        // `intentos` ya viene incrementado por `tomar()`, así que el primer
        // fallo vale 1 y le toca la primera espera de la lista.
        $espera = self::ESPERAS_MIN[$intentos - 1] ?? null;

        if ($espera === null) {
            $this->marcarFallido($id, $error);

            return;
        }

        $this->bd->pdo()->prepare(
            "UPDATE eventos_outbox
                SET estado = 'pendiente',
                    ultimo_error = ?,
                    disponible_en = DATE_ADD(NOW(), INTERVAL ? MINUTE)
              WHERE id = ?"
        )->execute([mb_substr($error, 0, 2000), $espera, $id]);
    }

    public function marcarFallido(int $id, string $error): void
    {
        $this->bd->pdo()->prepare(
            "UPDATE eventos_outbox
                SET estado = 'fallido', ultimo_error = ?, procesado_en = NOW()
              WHERE id = ?"
        )->execute([mb_substr($error, 0, 2000), $id]);
    }

    /**
     * Se mide contra `disponible_en`, que en una fila `procesando` es cuándo
     * se reclamó. Medirlo contra `creado_en` —cuándo se encoló— recuperaría
     * al instante cualquier evento que llevara un rato en cola, que es
     * exactamente el que acaba de tomar un worker vivo.
     */
    public function recuperarAtascados(int $minutos = 15): int
    {
        $stmt = $this->bd->pdo()->prepare(
            "UPDATE eventos_outbox
                SET estado = 'pendiente'
              WHERE estado = 'procesando'
                AND disponible_en <= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([max(1, $minutos)]);

        return $stmt->rowCount();
    }

    /**
     * Para `salud.sh` y el tablero.
     *
     * @return array{pendientes:int,procesando:int,fallidos:int,mas_viejo_min:?int}
     */
    public function estado(): array
    {
        $fila = $this->bd->pdo()->query(
            "SELECT
               SUM(estado = 'pendiente')  AS pendientes,
               SUM(estado = 'procesando') AS procesando,
               SUM(estado = 'fallido')    AS fallidos,
               MIN(CASE WHEN estado = 'pendiente'
                        THEN TIMESTAMPDIFF(MINUTE, disponible_en, NOW()) END) AS mas_viejo_min
             FROM eventos_outbox"
        )->fetch();

        return [
            'pendientes' => (int) ($fila['pendientes'] ?? 0),
            'procesando' => (int) ($fila['procesando'] ?? 0),
            'fallidos' => (int) ($fila['fallidos'] ?? 0),
            'mas_viejo_min' => $fila['mas_viejo_min'] !== null ? (int) $fila['mas_viejo_min'] : null,
        ];
    }
}
