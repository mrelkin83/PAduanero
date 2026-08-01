<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;

/**
 * Estado del conjunto dorado de cada modelo.
 *
 * **Ya no es una puerta.** Lo fue hasta el 2026-08-01, cuando el Product
 * Owner decidió retirarla: «quita el gate, elegir el modelo debe ser
 * suficiente». Se conserva el nombre de la clase porque el concepto —el
 * conjunto dorado— sigue existiendo y es como se le llama en el resto de la
 * documentación; lo que se retiró es el bloqueo, no la evidencia.
 *
 * Lo que hace hoy:
 *
 *  · `registrarCorrida()` deja constancia de cada corrida. Sin cambios.
 *  · `estado()` describe la situación de un modelo para que el panel la
 *    enseñe. **Informa, no impide.**
 *
 * Lo que hacía y ya no hace: negar el ascenso de un modelo sin corrida en
 * verde, negarle la respuesta a un suplente de la cascada, y negar la
 * activación de un prompt no probado contra el modelo que está hablando.
 *
 * Consecuencia, escrita aquí porque a quien lea esta clase dentro de un año
 * le va a faltar: un modelo puede empezar a hablar con clientes sin que
 * nadie haya comprobado que no suelta un plazo, que no cita una norma
 * numerada y que no promete un resultado. Las reglas siguen escritas en el
 * prompt y siguen probadas en el conjunto dorado — lo que ya no existe es la
 * comprobación de que ese modelo concreto las cumple antes de servir.
 * Debajo quedan el modo sombra de la Etapa 4 y la corrida, que se puede
 * lanzar cuando se quiera.
 */
final class GateDorado
{
    /** Prompt cuya versión activa gobierna lo que el bot dice. */
    public const CLAVE_PROMPT = 'conversacion';

    public function __construct(private readonly BD $bd)
    {
    }

    /**
     * Cómo está este modelo respecto del conjunto dorado.
     *
     * Es puramente descriptivo: nadie decide nada con esto salvo qué texto
     * pintar en la ficha. `ok` significa «hay evidencia vigente», no
     * «tiene permiso».
     *
     * @param  array<string,mixed> $modelo fila de `modelos_ia`
     * @return array{ok:bool,motivo:string}
     */
    public function estado(array $modelo): array
    {
        // Un modelo de embeddings no le dice nada a nadie: el conjunto
        // dorado no tiene nada que decir sobre él.
        if ((string) $modelo['proposito'] !== 'conversacion') {
            return ['ok' => true, 'motivo' => ''];
        }

        $estado = (string) ($modelo['dorado_estado'] ?? 'sin_correr');

        if ($estado === 'sin_correr') {
            return [
                'ok' => false,
                'motivo' => 'El conjunto dorado no se ha corrido contra este modelo: '
                    . 'no hay evidencia de que respete las reglas inviolables.',
            ];
        }

        if ($estado === 'rojo') {
            $fallos = (int) ($modelo['dorado_fallos'] ?? 0);

            return [
                'ok' => false,
                'motivo' => 'El conjunto dorado falló contra este modelo'
                    . ($fallos > 0 ? " ({$fallos} caso(s) en rojo)" : '')
                    . '. Revise el detalle.',
            ];
        }

        $activo = $this->promptActivoId();

        if ($activo === null) {
            return [
                'ok' => false,
                'motivo' => 'No hay prompt de conversación activo, así que la corrida '
                    . 'dorada no está atada a nada.',
            ];
        }

        $corridoCon = $modelo['dorado_prompt_id'] !== null
            ? (string) $modelo['dorado_prompt_id']
            : null;

        if ($corridoCon !== $activo) {
            return [
                'ok' => false,
                'motivo' => 'El prompt activo cambió después de la última corrida dorada: '
                    . 'ese verde no dice nada sobre lo que el bot diría hoy.',
            ];
        }

        return ['ok' => true, 'motivo' => 'Conjunto dorado en verde con el prompt activo.'];
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
     * De aquí sale la caducidad de las corridas: activar una versión nueva no
     * borra ningún resultado —queda el histórico de que ese modelo pasó
     * alguna vez— pero hace que `dorado_prompt_id` deje de coincidir y que
     * `estado()` pase a decir que ese verde ya no dice nada sobre lo que el
     * bot diría hoy.
     *
     * Desde que se retiró el gate eso es un aviso, no un impedimento.
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
