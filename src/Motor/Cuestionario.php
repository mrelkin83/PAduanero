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
     * `rama` null significa que el paso lo ve todo el mundo; `aduanero`,
     * que solo lo ve quien vino por esa rama. (Hasta el 2026-08-25 existió
     * también una rama `tributario`; se retiró junto con esa área de
     * práctica — CLAUDE.md §5, §7.)
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
     *     tecnico: ?string, rama: ?string, tipo: ?string, salida: ?string,
     *     expediente?: bool
     *   }>
     * }>
     *
     * `expediente` marca las opciones en las que el expediente técnico
     * —subpartida, valor declarado, régimen, documentos soporte— decide el
     * caso tanto como el argumento jurídico; el resultado menciona ahí a la
     * consultora del despacho (§4.5). Solo se escribe donde es `true`:
     * `definicion()` rellena el resto y es la única que lo lee.
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
                        'valor' => 'contenedor',
                        'etiqueta' => 'Cobros de demoras o sobrestadía de contenedores',
                        'detalle' => 'La naviera o el puerto le cobran por contenedores devueltos tarde y quiere revisar si el cobro le corresponde.',
                        'mensaje' => 'Me están cobrando demoras o sobrestadía de contenedores y quiero revisar si me corresponden',
                        'tecnico' => 'Cobros de demoras de contenedores',
                        'rama' => 'aduanero',
                        'tipo' => 'demoras_contenedor',
                        'salida' => null,
                    ],
                    [
                        // La controversia puramente técnica: no le quitaron
                        // nada, le discuten CÓMO declaró. Hasta el
                        // 2026-09-11 estos casos entraban mezclados con el
                        // pliego de cargos en el paso 2, y son justo la rama
                        // donde el expediente —subpartida, valor, régimen—
                        // decide el resultado.
                        'valor' => 'tecnico',
                        'etiqueta' => 'Me discuten la clasificación, el valor o el régimen',
                        'detalle' => 'La DIAN cuestiona la subpartida, el valor en aduana, el origen o el régimen bajo el que entró la mercancía.',
                        'mensaje' => 'La DIAN me cuestiona la clasificación, el valor en aduana o el régimen de mi operación',
                        'tecnico' => 'Controversia de clasificación o valor',
                        'rama' => 'aduanero',
                        'tipo' => 'clasificacion_arancelaria',
                        'salida' => null,
                        'expediente' => true,
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
                        'expediente' => true,
                    ],
                    [
                        'valor' => 'requerimiento_solvencia',
                        'etiqueta' => 'Un requerimiento que cuestiona mi solvencia económica',
                        'detalle' => 'La DIAN pone en duda la capacidad de pago, el patrimonio o el origen de los recursos usados en la operación.',
                        'mensaje' => 'Recibí un requerimiento que cuestiona la solvencia económica de mi operación',
                        'tecnico' => 'Cuestionamiento de solvencia económica',
                        'rama' => null,
                        'tipo' => 'cuestionamiento_solvencia_economica',
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
                        'valor' => 'cobro_naviera',
                        'etiqueta' => 'No es de la DIAN: es un cobro de la naviera o el puerto',
                        'detalle' => 'Factura o cobro por demoras, sobrestadía o uso de contenedores tras la devolución.',
                        'mensaje' => 'Me están cobrando demoras o sobrestadía de contenedores',
                        'tecnico' => 'Cobros de demoras de contenedores',
                        'rama' => null,
                        'tipo' => 'demoras_contenedor',
                        'salida' => null,
                    ],
                    [
                        'valor' => 'regimen_temporal',
                        'etiqueta' => 'Un requerimiento sobre importación temporal o reimportación',
                        'detalle' => 'Le cuestionan el régimen: la finalización, la reexportación o la modalidad bajo la cual ingresó la mercancía.',
                        'mensaje' => 'Recibí un requerimiento sobre una importación temporal o una reimportación',
                        'tecnico' => 'Controversia de régimen aduanero',
                        'rama' => null,
                        'tipo' => 'requerimiento_ordinario',
                        'salida' => null,
                        'expediente' => true,
                    ],
                    [
                        'valor' => 'documentos_soporte',
                        'etiqueta' => 'Le rechazaron documentos soporte o requisitos de otra entidad',
                        'detalle' => 'Inconsistencias entre factura, BL, packing list y declaración, o requisitos de ICA o INVIMA no acreditados.',
                        'mensaje' => 'Me rechazaron documentos soporte o requisitos de otra entidad',
                        'tecnico' => 'Inconsistencias en documentos soporte',
                        'rama' => null,
                        'tipo' => 'requerimiento_ordinario',
                        'salida' => null,
                        'expediente' => true,
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
                // «La tarifa es fija para todos los casos» dejó de ser cierto
                // el día que el despacho tuvo más de un servicio. Lo que
                // sigue siendo cierto —y es lo único que esta pregunta
                // necesita decir— es que la cuantía no la mueve.
                'ayuda' => 'Un aproximado basta para dimensionar la controversia aduanera. La tarifa no depende de la cuantía.',
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
     * Los pasos con la salida crítica ya resuelta y la mención del
     * expediente técnico ya decidida.
     *
     * `salida` no se escribe a mano en las opciones críticas: se deduce de
     * `Catalogo::esCritico()`, que es donde vive la regla 5. Escribirla a
     * mano crearía dos listas de casos críticos, y el día que alguien
     * añadiera una a `Catalogo` el diagnóstico seguiría preguntándole la
     * cuantía a quien tiene la POLFA en la puerta.
     *
     * `expediente` se normaliza aquí por la misma razón, y con una regla
     * dura encima: **una opción que sale del cuestionario nunca menciona a
     * la consultora**. Eso cubre de una vez los dos casos en los que la
     * mención estaría mal y que, escritos a mano, alguien acabaría
     * olvidando:
     *
     *  · quien marca «todavía nada: quiero prevenir» sale por
     *    `fuera_alcance` — ahí la revisión técnica sería la protagonista y
     *    no un apoyo, y es una línea de servicio que el despacho todavía no
     *    vende (decisión del PO pendiente: precio y facturación);
     *  · quien marca «operativo de la POLFA en curso» sale por `urgente` —
     *    ahí lo único sensato es escribir ya, y hablar de subpartidas sería
     *    contestar otra pregunta.
     *
     * Y vale para cualquier salida que se añada después, sin tocar esto.
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

                $pasos[$i]['opciones'][$j]['expediente'] =
                    ($opcion['expediente'] ?? false)
                    && $pasos[$i]['opciones'][$j]['salida'] === null;
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
