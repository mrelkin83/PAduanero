<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;

/**
 * Puerta entre «este modelo existe» y «este modelo le habla a los clientes».
 *
 * Una sola clase con la regla, usada por dos sitios: el panel, cuando el
 * abogado pulsa «Hacer primario», y el corredor del conjunto dorado, cuando
 * termina. Si la regla viviera duplicada en ambos, uno de los dos se quedaría
 * atrás y el que se quedara atrás sería el que deja pasar.
 *
 * La regla completa tiene tres partes; solo dos caben en un CHECK:
 *
 *  1. Hubo corrida y salió verde        → `ck_modelo_primario_dorado`
 *  2. Es de propósito `conversacion`    → idem
 *  3. **La corrida sigue vigente**: el prompt activo es el mismo con el que
 *     se corrió. Esto cruza `modelos_ia` con `prompts` y por eso vive aquí.
 *
 * La tercera es la que impide el fraude honesto: correr el dorado en verde,
 * cambiar el prompt al día siguiente y promover con un verde que ya no dice
 * nada sobre lo que el bot diría.
 */
final class GateDorado
{
    /** Prompt cuya versión activa gobierna lo que el bot dice. */
    public const CLAVE_PROMPT = 'conversacion';

    public function __construct(private readonly BD $bd)
    {
    }

    /**
     * ¿Puede este modelo hablar con un cliente?
     *
     * Mismo criterio que `puedePromover()`, y adrede: si un suplente de la
     * cascada pudiera responder con requisitos más laxos que los que se le
     * exigen a un primario, la firma del abogado sería decorativa — bastaría
     * con que el primario fallara para que hablara un modelo sin firmar, que
     * es justo el día en que menos conviene.
     *
     * Existen los dos nombres porque los dos sitios preguntan lo mismo desde
     * intenciones distintas, y leer `puedePromover()` dentro de la cascada
     * despistaría a quien la revise.
     *
     * @param  array<string,mixed> $modelo fila de `modelos_ia`
     * @return array{ok:bool,motivo:string}
     */
    public function puedeResponder(array $modelo): array
    {
        return $this->puedePromover($modelo);
    }

    /**
     * @param  array<string,mixed> $modelo fila de `modelos_ia`
     * @return array{ok:bool,motivo:string}
     */
    public function puedePromover(array $modelo): array
    {
        // Un modelo de embeddings no le dice nada a nadie. Exigirle el
        // conjunto dorado sería teatro.
        if ((string) $modelo['proposito'] !== 'conversacion') {
            return ['ok' => true, 'motivo' => ''];
        }

        $estado = (string) ($modelo['dorado_estado'] ?? 'sin_correr');

        if ($estado === 'sin_correr') {
            return [
                'ok' => false,
                'motivo' => 'El conjunto dorado no se ha corrido contra este modelo. '
                    . 'Sin eso, aprobarlo sería aprobar un nombre: no hay evidencia de '
                    . 'que respete las reglas inviolables.',
            ];
        }

        if ($estado === 'rojo') {
            $fallos = (int) ($modelo['dorado_fallos'] ?? 0);

            return [
                'ok' => false,
                'motivo' => 'El conjunto dorado falló contra este modelo'
                    . ($fallos > 0 ? " ({$fallos} caso(s) en rojo)" : '')
                    . '. Revise el detalle antes de volver a intentarlo.',
            ];
        }

        $activo = $this->promptActivoId();

        if ($activo === null) {
            return [
                'ok' => false,
                'motivo' => 'No hay prompt de conversación activo. La corrida dorada no '
                    . 'puede quedar atada a nada.',
            ];
        }

        $corridoCon = $modelo['dorado_prompt_id'] !== null
            ? (string) $modelo['dorado_prompt_id']
            : null;

        if ($corridoCon !== $activo) {
            return [
                'ok' => false,
                'motivo' => 'El prompt activo cambió después de la última corrida dorada. '
                    . 'El verde anterior no dice nada sobre lo que el bot diría hoy: '
                    . 'vuelva a correr el conjunto dorado contra este modelo.',
            ];
        }

        return ['ok' => true, 'motivo' => ''];
    }

    /**
     * ¿Se puede activar esta versión de prompt?
     *
     * Simétrico al de los modelos, y por la misma razón: la corrida dorada
     * valida **la pareja prompt + modelo**, no cada uno por su lado. Activar
     * un prompt que nunca se probó contra el modelo que está hablando deja al
     * bot funcionando con una combinación que nadie verificó — y sin ningún
     * síntoma, porque el bot sigue respondiendo.
     *
     * Es lo que cierra el hueco que quedaba: `puedePromover()` impide cambiar
     * el modelo sin dorado, pero sin esto se podía conseguir lo mismo por el
     * otro lado, cambiando el prompt.
     *
     * @return array{ok:bool,motivo:string}
     */
    public function puedeActivarPrompt(string $promptId): array
    {
        $primario = $this->bd->pdo()->query(
            "SELECT id, identificador FROM modelos_ia
              WHERE proposito = 'conversacion' AND es_primario = 1 LIMIT 1"
        )->fetch();

        if ($primario === false) {
            // Todavía no hay primario: es el caso normal la primera vez, y
            // entonces el orden correcto es activar el prompt y después
            // correr el dorado para poder promover el modelo.
            return ['ok' => true, 'motivo' => ''];
        }

        $stmt = $this->bd->pdo()->prepare(
            "SELECT dorado_estado, dorado_prompt_id FROM modelos_ia WHERE id = ?"
        );
        $stmt->execute([$primario['id']]);
        $modelo = $stmt->fetch();

        if (($modelo['dorado_estado'] ?? '') === 'verde'
            && (string) ($modelo['dorado_prompt_id'] ?? '') === $promptId) {
            return ['ok' => true, 'motivo' => ''];
        }

        return [
            'ok' => false,
            'motivo' => 'Esta versión no se ha probado contra ' . $primario['identificador']
                . ', que es el modelo que está hablando. Corra el conjunto dorado '
                . '(`php bin/correr-dorado.php --prompt=' . $promptId . '`) y actívela '
                . 'cuando salga en verde.',
        ];
    }

    /**
     * Deja constancia de una corrida.
     *
     * La llama el corredor del conjunto dorado, no el panel: nadie marca un
     * verde a mano. `dorado_prompt_id` se toma **aquí** y no se recibe como
     * parámetro, para que sea imposible registrar una corrida atribuyéndola a
     * un prompt que no era el activo.
     *
     * @param array{id:string,contenido:string} $prompt la FILA del prompt con
     *        el que se corrió, no su id suelto. Se recibe entera para que sea
     *        imposible correr con un texto y registrar contra otro id: quien
     *        llama solo puede pasar lo que leyó del repositorio.
     * @param array<string,mixed> $detalle recuento por categoría de regla,
     *                                     nunca el texto de las conversaciones
     */
    public function registrarCorrida(
        string $modeloId,
        array $prompt,
        bool $verde,
        int $casos,
        int $fallos,
        array $detalle = [],
        ?string $usuarioId = null,
    ): void {
        $this->bd->pdo()->prepare(
            'UPDATE modelos_ia
                SET dorado_estado    = ?,
                    dorado_en        = NOW(),
                    dorado_prompt_id = ?,
                    dorado_casos     = ?,
                    dorado_fallos    = ?,
                    dorado_detalle   = ?,
                    dorado_por       = ?
              WHERE id = ?'
        )->execute([
            $verde ? 'verde' : 'rojo',
            $prompt['id'],
            $casos,
            $fallos,
            $detalle === []
                ? null
                : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $usuarioId,
            $modeloId,
        ]);
    }

    /**
     * Versión del prompt de conversación que gobierna hoy.
     *
     * De aquí sale la invalidación implícita de las corridas: activar una
     * versión nueva no borra ningún resultado —queda el histórico de que ese
     * modelo pasó alguna vez— pero hace que `dorado_prompt_id` deje de
     * coincidir y `puedePromover()` empiece a decir que no.
     *
     * Nótese lo que eso NO hace: activar un prompt nuevo no degrada al
     * primario actual, que sigue respondiendo. Solo impide promover a otro
     * con un verde caducado. Degradar al primario en caliente por un cambio
     * de prompt dejaría al motor sin modelo en mitad de una conversación.
     */
    public function promptActivoId(): ?string
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id FROM prompts WHERE clave = ? AND activo = 1 LIMIT 1'
        );
        $stmt->execute([self::CLAVE_PROMPT]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }
}
