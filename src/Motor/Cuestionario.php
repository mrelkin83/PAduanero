<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * El cuestionario del diagnóstico público (`/perfil`).
 *
 * Es el triage de la Etapa 4 —el que hoy hace el bot a lo largo de una
 * conversación de WhatsApp— adelantado a la landing y resuelto en el
 * navegador. Quien lo termina llega a WhatsApp con el caso ya descrito en
 * el vocabulario correcto, y Pedro deja de gastar seis mensajes en
 * averiguar qué le pasó a la persona.
 *
 * **Nada de lo que se responde aquí se persiste.** Ni una fila, ni un log.
 * El resultado se compone en el navegador y sale por el mensaje prellenado
 * de WhatsApp, que el contacto lee antes de enviarlo. Por eso el
 * diagnóstico no necesita habeas data (regla 1): no hay tratamiento de dato
 * personal alguno hasta que la persona decide escribir, y para entonces el
 * consentimiento lo pide el motor como siempre.
 *
 * Tres decisiones que no son de estilo:
 *
 *  · **Solo procesos correctivos.** El despacho atiende procedimientos ya
 *    abiertos. La primera pregunta ofrece explícitamente la salida «todavía
 *    no hay nada abierto», y esa opción termina el diagnóstico en
 *    `fuera_alcance` en vez de arrastrar a alguien por cinco pantallas para
 *    decirle que no al final.
 *
 *  · **El puntaje no aparece.** Las respuestas alimentan exactamente los
 *    cinco factores de `Puntaje` (§3.2), pero el número es interno y **nunca
 *    se le muestra al contacto**: es una medida de capacidad de pago y no
 *    puede llegar a quien acaba de perder su mercancía. El diagnóstico no lo
 *    calcula siquiera — solo recoge lo que lo alimentaría.
 *
 *  · **Los tipos salen de `Catalogo`, no de una lista nueva.** Si mañana se
 *    añade un tipo de caso, este cuestionario y el motor siguen hablando del
 *    mismo catálogo. `CuestionarioTest` lo verifica: ninguna opción puede
 *    emitir un tipo que el motor rechazaría.
 *
 * Las reglas 2, 3 y 4 gobiernan cada cadena de texto de este archivo: no se
 * nombra un plazo, no se cita una norma con número y no se promete un
 * resultado. Pedro revisa el copy bajo la Ley 1123 de 2007 antes de que la
 * página se indexe (`landing_indexable`).
 */
final class Cuestionario
{
    /** Salidas posibles. `null` es «siga preguntando». */
    public const SALIDA_FUERA_ALCANCE = 'fuera_alcance';
    public const SALIDA_URGENTE = 'urgente';

    /**
     * Los pasos, en orden.
     *
     * `rama` null significa que el paso lo ve todo el mundo; `aduanero` o
     * `tributario`, que solo lo ve quien vino por esa bifurcación. La
     * bifurcación la fija el paso 1 con el `rama` de la opción elegida.
     *
     * @return list<array{
     *   id: string,
     *   rama: ?string,
     *   rotulo: string,
     *   pregunta: string,
     *   ayuda: ?string,
     *   resumen: string,
     *   opciones: list<array{
     *     valor: string, etiqueta: string, detalle: string, mensaje: string,
     *     tecnico: ?string, rama: ?string, tipo: ?string, salida: ?string
     *   }>
     * }>
     */
    public static function pasos(): array
    {
        return [
            // ── 1. Punto de partida ────────────────────────────────────
            //
            // El filtro que define el negocio: solo procesos correctivos
            // en derecho aduanero y comercio exterior.
            [
                'id' => 'partida',
                'rama' => null,
                'rotulo' => 'Punto de partida',
                'pregunta' => '¿Qué situación aduanera tiene hoy?',
                'ayuda' => 'Elija la opción que mejor describe su situación actual ante la DIAN o la POLFA.',
                'resumen' => 'Situación',
                'opciones' => [
                    [
                        'valor' => 'mercancia',
                        'etiqueta' => 'Una mercancía retenida o aprehendida',
                        'detalle' => 'La DIAN o la POLFA la retuvo en puerto, aeropuerto, bodega o carretera.',
                        'mensaje' => 'Tengo una mercancía retenida o aprehendida por la DIAN o la POLFA',
                        'tecnico' => 'Aprehensión de mercancía',
                        'rama' => 'aduanero',
                        'tipo' => 'aprehension_mercancia',
                        'salida' => null,
                    ],
                    [
                        'valor' => 'levante',
                        'etiqueta' => 'Problemas con el levante o inspección',
                        'detalle' => 'Inspección aduanera, rechazo, cancelación o suspensión de levante.',
                        'mensaje' => 'Tengo un inconveniente con el levante de mi declaración de importación',
                        'tecnico' => 'Cancelación de levante',
                        'rama' => 'aduanero',
                        'tipo' => 'cancelacion_levante',
                        'salida' => null,
                    ],
                    [
                        'valor' => 'operador',
                        'etiqueta' => 'Sanción o investigación a mi operación',
                        'detalle' => 'Proceso sancionatorio a agencia de aduanas, depósito habilitado, usuario o transportador.',
                        'mensaje' => 'Hay un proceso sancionatorio contra mi operación de comercio exterior',
                        'tecnico' => 'Proceso sancionatorio aduanero',
                        'rama' => 'aduanero',
                        'tipo' => 'proceso_sancionatorio',
                        'salida' => null,
                    ],
                    [
                        'valor' => 'preventivo',
                        'etiqueta' => 'Todavía nada: quiero prevenir',
                        'detalle' => 'No hay ningún proceso abierto ni mercancía retenida. Busco ordenar la operación antes.',
                        'mensaje' => 'No tengo un proceso abierto',
                        'tecnico' => null,
                        'rama' => null,
                        'tipo' => null,
                        'salida' => self::SALIDA_FUERA_ALCANCE,
                    ],
                ],
            ],

            // ── 2. Documento — aduanero ─────────────────────────────────
            //
            // «Operativo en curso» corta el diagnóstico en seco (regla 5).
            [
                'id' => 'documento_aduanero',
                'rama' => 'aduanero',
                'rotulo' => 'El documento',
                'pregunta' => '¿Qué documento le notificaron o entregaron?',
                'ayuda' => 'El título aparece en el encabezado de la primera página del acto administrativo.',
                'resumen' => 'Documento',
                'opciones' => [
                    [
                        'valor' => 'acta_aprehension',
                        'etiqueta' => 'Un acta de aprehensión',
                        'detalle' => 'Se llevaron o inmovilizaron la mercancía y dejaron constancia escrita.',
                        'mensaje' => 'Recibí un acta de aprehensión',
                        'tecnico' => 'Aprehensión de mercancía',
                        'rama' => null,
                        'tipo' => 'aprehension_mercancia',
                        'salida' => null,
                    ],
                    [
                        'valor' => 'resolucion_decomiso',
                        'etiqueta' => 'Una resolución de decomiso',
                        'detalle' => 'El procedimiento aduanero avanzó y la DIAN emitió decisión de fondo.',
                        'mensaje' => 'Recibí una resolución de decomiso',
                        'tecnico' => 'Decomiso aduanero',
                        'rama' => null,
                        'tipo' => 'decomiso',
                        'salida' => null,
                    ],
                    [
                        'valor' => 'levante',
                        'etiqueta' => 'Auto de suspensión o cancelación de levante',
                        'detalle' => 'No autorizaron el retiro, suspendieron o cancelaron el levante aduanero.',
                        'mensaje' => 'Tengo una decisión sobre la suspensión o cancelación del levante',
                        'tecnico' => 'Cancelación de levante',
                        'rama' => null,
                        'tipo' => 'cancelacion_levante',
                        'salida' => null,
                    ],
                    [
                        'valor' => 'requerimiento',
                        'etiqueta' => 'Requerimiento especial aduanero o pliego de cargos',
                        'detalle' => 'Le formulan cargos, cuestionan clasificación arancelaria, valor o piden explicaciones.',
                        'mensaje' => 'Recibí un requerimiento aduanero o pliego de cargos',
                        'tecnico' => 'Proceso sancionatorio aduanero',
                        'rama' => null,
                        'tipo' => 'proceso_sancionatorio',
                        'salida' => null,
                    ],
                    [
                        'valor' => 'operativo',
                        'etiqueta' => 'Un operativo de la POLFA en curso',
                        'detalle' => 'La POLFA está en el sitio ahora, o hubo allanamiento o captura.',
                        'mensaje' => 'Hay un operativo de la POLFA en curso',
                        'tecnico' => 'Operativo de la POLFA',
                        'rama' => null,
                        'tipo' => 'operativo_polfa',
                        'salida' => null, // lo pone esCritico() en definicion()
                    ],
                    [
                        'valor' => 'nada_escrito',
                        'etiqueta' => 'Nada por escrito todavía',
                        'detalle' => 'La mercancía está retenida de hecho pero no me han entregado documento oficial.',
                        'mensaje' => 'Todavía no me han entregado ningún documento por escrito',
                        'tecnico' => 'Retención sin acto notificado',
                        'rama' => null,
                        'tipo' => 'otro',
                        'salida' => null,
                    ],
                ],
            ],

            // ── 3. Antigüedad ───────────────────────────────────────────
            [
                'id' => 'antiguedad',
                'rama' => null,
                'rotulo' => 'El tiempo',
                'pregunta' => '¿Hace cuánto ocurrió o lo notificaron?',
                'ayuda' => 'Cuente desde la fecha en que se practicó la diligencia o le fue notificado el acto.',
                'resumen' => 'Notificado',
                'opciones' => [
                    ['valor' => 'hoy', 'etiqueta' => 'Hoy o ayer', 'detalle' => '', 'mensaje' => 'hoy o ayer', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'semana', 'etiqueta' => 'Esta semana', 'detalle' => '', 'mensaje' => 'esta semana', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'mes', 'etiqueta' => 'Entre una semana y un mes', 'detalle' => '', 'mensaje' => 'hace entre una semana y un mes', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'mas_mes', 'etiqueta' => 'Más de un mes', 'detalle' => '', 'mensaje' => 'hace más de un mes', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'no_se', 'etiqueta' => 'No estoy seguro', 'detalle' => '', 'mensaje' => 'no estoy seguro de la fecha', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                ],
            ],

            // ── 4. Estado de la mercancía / proceso ──────────────────────
            [
                'id' => 'estado_aduanero',
                'rama' => 'aduanero',
                'rotulo' => 'La mercancía',
                'pregunta' => '¿Dónde se encuentra la mercancía u operación hoy?',
                'ayuda' => null,
                'resumen' => 'Ubicación',
                'opciones' => [
                    ['valor' => 'puerto', 'etiqueta' => 'En puerto o aeropuerto', 'detalle' => 'En zona primaria aduanera, sin poder retirarla.', 'mensaje' => 'está en puerto o aeropuerto sin levante', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'deposito', 'etiqueta' => 'En un depósito habilitado', 'detalle' => 'Bajo custodia en depósito aduanero autorizado.', 'mensaje' => 'está en un depósito habilitado', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'bodega_dian', 'etiqueta' => 'En una bodega o recinto de la DIAN', 'detalle' => 'Aprehendida físicamente por la autoridad aduanera.', 'mensaje' => 'está en una bodega de la DIAN', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'entregada', 'etiqueta' => 'Ya fue entregada o retirada', 'detalle' => 'Pero existe investigación o proceso sancionatorio posterior.', 'mensaje' => 'ya me la entregaron, pero el proceso sigue abierto', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'no_se', 'etiqueta' => 'No lo sé con certeza', 'detalle' => '', 'mensaje' => 'no sé con certeza dónde está', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                ],
            ],

            // ── 5. Valor ────────────────────────────────────────────────
            [
                'id' => 'valor',
                'rama' => null,
                'rotulo' => 'La cuantía',
                'pregunta' => '¿De cuánto es el valor o controversia aproximada?',
                'ayuda' => 'Un aproximado basta para dimensionar la controversia aduanera. La tarifa de la asesoría es fija para todos los casos.',
                'resumen' => 'Cuantía',
                'opciones' => [
                    ['valor' => 'menos_20', 'etiqueta' => 'Menos de $20 millones', 'detalle' => '', 'mensaje' => 'menos de $20 millones', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => '20_100', 'etiqueta' => 'Entre $20 y $100 millones', 'detalle' => '', 'mensaje' => 'entre $20 y $100 millones', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => '100_500', 'etiqueta' => 'Entre $100 y $500 millones', 'detalle' => '', 'mensaje' => 'entre $100 y $500 millones', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'mas_500', 'etiqueta' => 'Más de $500 millones', 'detalle' => '', 'mensaje' => 'más de $500 millones', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'no_se', 'etiqueta' => 'No lo sé todavía', 'detalle' => '', 'mensaje' => 'todavía no sé la cuantía', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                ],
            ],

            // ── 6. Titular ──────────────────────────────────────────────
            [
                'id' => 'titular',
                'rama' => null,
                'rotulo' => 'El titular',
                'pregunta' => '¿A nombre de quién figura la operación o trámite?',
                'ayuda' => null,
                'resumen' => 'Titular',
                'opciones' => [
                    ['valor' => 'juridica', 'etiqueta' => 'Una empresa (Persona jurídica)', 'detalle' => 'Sociedad comercial con NIT.', 'mensaje' => 'a nombre de una empresa', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                    ['valor' => 'natural', 'etiqueta' => 'Una persona natural', 'detalle' => 'A mi nombre o al de un tercero particular.', 'mensaje' => 'a nombre de una persona natural', 'tecnico' => null, 'rama' => null, 'tipo' => null, 'salida' => null],
                ],
            ],
        ];
    }

    /**
     * Los pasos con la salida crítica ya resuelta.
     *
     * `salida` no se escribe a mano en las opciones críticas: se deduce de
     * `Catalogo::esCritico()`, que es donde vive la regla 5. Escribirla a
     * mano crearía dos listas de casos críticos, y el día que alguien
     * añadiera una a `Catalogo` el diagnóstico seguiría preguntándole la
     * cuantía a quien tiene la POLFA en la puerta.
     *
     * @return list<array<string,mixed>>
     */
    public static function definicion(): array
    {
        $pasos = self::pasos();

        foreach ($pasos as $i => $paso) {
            foreach ($paso['opciones'] as $j => $opcion) {
                if ($opcion['salida'] === null
                    && is_string($opcion['tipo'])
                    && Catalogo::esCritico($opcion['tipo'])
                ) {
                    $pasos[$i]['opciones'][$j]['salida'] = self::SALIDA_URGENTE;
                }
            }
        }

        return $pasos;
    }

    /**
     * Cuántos pasos ve quien elige esta rama. Lo necesita el «paso N de M»,
     * que en un cuestionario ramificado no puede ser el total de pasos
     * definidos: un contador que dice «3 de 9» cuando solo quedan tres
     * preguntas hace abandonar.
     */
    public static function largoDeRama(string $rama): int
    {
        $n = 0;

        foreach (self::pasos() as $paso) {
            if ($paso['rama'] === null || $paso['rama'] === $rama) {
                $n++;
            }
        }

        return $n;
    }

    /** @return list<string> */
    public static function ramas(): array
    {
        return ['aduanero'];
    }
}
