<?php

declare(strict_types=1);

namespace App\Motor;

use App\Excepciones\LlmException;
use App\Modelos\ConversacionEstado;
use App\Repositorios\CasoRepo;
use App\Repositorios\ConsentimientoRepo;
use App\Repositorios\ContactoRepo;
use App\Repositorios\ConversacionEstadoRepo;
use App\Servicios\Config;
use App\Servicios\Llm;
use App\Servicios\Outbox;
use App\Soporte\Logger;

/**
 * El motor. Decide qué se hace con cada mensaje que entra.
 *
 * EL ORDEN DE LAS PUERTAS ES EL DISEÑO
 *
 * No es una secuencia cualquiera: cada puerta está antes de la siguiente
 * porque dejarla después rompe algo concreto.
 *
 *   1. Kill switch global          — regla 9
 *   2. IA apagada en esta conversación — regla 8
 *   3. SEÑAL CRÍTICA               — regla 5, sin pasar por el modelo
 *   4. GATE DE CONSENTIMIENTO      — regla 1, antes de persistir nada
 *   5. Tope de turnos              — tope de costo
 *   6. Buffer de ráfaga
 *   7. El modelo
 *
 * **La señal crítica va antes que el consentimiento** porque la regla 5 obliga
 * a escalar de inmediato y no puede quedarse esperando a que alguien acepte una
 * política de datos. Esa ruta cae bajo la regla 14: se persiste teléfono,
 * motivo, marca de tiempo y `chatwoot_conv_id`, y nada más.
 *
 * **El consentimiento va antes que todo lo demás** —antes del RAG, antes del
 * modelo, antes de cualquier escritura de contenido— porque después ya es
 * tarde: el texto ya viajó al proveedor del LLM o ya está en una fila.
 *
 * ESTE MOTOR NO PUEDE HABLARLE A UN CLIENTE
 *
 * No tiene dependencia de `Chatwoot`. Lo que dice sale por `Outbox`, y el
 * manejador lo entrega con `entregar()`, que consulta `motor_modo_sombra`. No
 * es una convención: es que aquí no existe el objeto con el que se podría
 * enviar un mensaje directo. Con `motor_modo_sombra` en true, la única salida
 * que existe es la nota privada.
 *
 * Hay pruebas de arquitectura que lo comprueban leyendo este directorio, para
 * que la dependencia no se cuele en un futuro «solo para este caso».
 */
final class MotorConversacional
{
    public function __construct(
        private readonly ContactoRepo $contactos,
        private readonly ConsentimientoRepo $consentimientos,
        private readonly CasoRepo $casos,
        private readonly ConversacionEstadoRepo $conversaciones,
        private readonly Llm $llm,
        private readonly Outbox $outbox,
        private readonly Config $config,
        private readonly Logger $log,
        private readonly ConstructorPrompt $prompt,
        // Etapa 5. Opcional para que el motor de las etapas anteriores siga
        // armándose sin la maquinaria de cobro; sin agenda, las acciones de
        // reserva se ignoran y el bot solo conversa.
        private readonly ?Agenda $agenda = null,
    ) {
    }

    /**
     * Procesa un mensaje entrante.
     *
     * @param int    $chatwootConvId conversación en Chatwoot
     * @param string $telefonoE164   del contacto, sin «+»
     * @param string $mensaje        texto tal cual llegó
     */
    public function procesar(
        int $chatwootConvId,
        string $telefonoE164,
        string $mensaje,
        ?int $chatwootContactId = null,
    ): Decision {
        // ── 1. Kill switch global (regla 9) ──────────────────────────────
        //
        // Antes que nada, incluida la creación del estado: si Pedro apagó la
        // IA, este sistema no debe ni empezar a trabajar. Chatwoot y WhatsApp
        // siguen funcionando; solo calla el bot.
        if ($this->encendido('motor_ia_pausado', false)) {
            return Decision::silencio('kill switch global activo');
        }

        $estado = $this->conversaciones->buscarOCrear($chatwootConvId);

        // ── 2. Un humano tiene la conversación (regla 8) ─────────────────
        //
        // No se reactiva sola. Ni aquí ni en ningún otro punto del motor.
        if (!$estado->puedeResponderIa()) {
            return Decision::silencio('la IA está apagada o pausada en esta conversación');
        }

        // ── 3. SEÑAL CRÍTICA — antes del modelo (regla 5) ────────────────
        //
        // Coincidencia de cadenas, determinista y barata. Preguntarle al
        // modelo si una POLFA en la bodega es urgente introduce una
        // probabilidad de que diga que no; una lista de frases no se
        // equivoca en esa dirección. Y no se le manda al proveedor del LLM
        // el texto de alguien que está en medio de un allanamiento.
        if (SenalesCriticas::detecta($mensaje)) {
            return $this->escalar(
                $chatwootConvId,
                $telefonoE164,
                MotivoEscalamiento::URGENCIA,
                SenalesCriticas::cual($mensaje),
            );
        }

        // ── 4. GATE DE CONSENTIMIENTO (regla 1) ──────────────────────────
        //
        // A partir de aquí se persiste contenido y se habla con el proveedor
        // del LLM. Ninguna de las dos cosas puede ocurrir antes de esta línea.
        $contacto = $this->contactos->porTelefono($telefonoE164);

        if ($contacto === null || !$this->consentimientos->tieneVigente($contacto->id)) {
            return $this->pedirConsentimiento($chatwootConvId, $telefonoE164, $mensaje, $chatwootContactId);
        }

        // Vincular contacto y conversación en cuanto hay permiso para hacerlo.
        // Sin esto, el despacho de ráfaga no sabe de quién es la conversación
        // y se queda callado: el último mensaje de una ráfaga no se contesta
        // nunca, y el síntoma es «a veces el bot no responde».
        $this->conversaciones->buscarOCrear($chatwootConvId, $contacto->id);

        // ── 5. Tope de turnos ────────────────────────────────────────────
        //
        // Tope de costo, no de paciencia: un contacto puede quemar
        // presupuesto de LLM indefinidamente (`CLAUDE.md` §7.7).
        $maxTurnos = (int) $this->config->get('max_turnos_ia', 40);

        if ($estado->turnos >= $maxTurnos) {
            return $this->escalar($chatwootConvId, $telefonoE164, MotivoEscalamiento::LIMITE_TURNOS);
        }

        // ── 6. Buffer de ráfaga ──────────────────────────────────────────
        //
        // En WhatsApp la gente manda cuatro mensajes seguidos. Sin esto se
        // disparan cuatro llamadas al modelo que se pisan: cuatro respuestas,
        // cuatro cobros, y un hilo donde el bot se contesta a sí mismo.
        $ventana = (int) $this->config->get('ventana_rafaga_segundos', 8);
        $enBuffer = $this->conversaciones->acumularBuffer($chatwootConvId, $mensaje, $ventana);

        if (!$this->conversaciones->bufferListo($chatwootConvId)) {
            return Decision::acumulado($enBuffer);
        }

        // ── 7. El modelo ─────────────────────────────────────────────────
        return $this->turnoDelModelo($chatwootConvId, $telefonoE164, $contacto->id);
    }

    /**
     * Despacha una ráfaga cuya ventana ya venció.
     *
     * Lo llama el worker. Es el mismo camino que el turno 7 de `procesar()`,
     * expuesto aparte porque la ventana vence sin que llegue ningún mensaje
     * nuevo — y si solo se pudiera responder al recibir uno, el último mensaje
     * de una ráfaga se quedaría sin contestar hasta que el contacto escribiera
     * otra vez.
     */
    public function despacharRafaga(int $chatwootConvId): Decision
    {
        if ($this->encendido('motor_ia_pausado', false)) {
            return Decision::silencio('kill switch global activo');
        }

        $estado = $this->conversaciones->porConversacion($chatwootConvId);

        if ($estado === null || !$estado->puedeResponderIa()) {
            return Decision::silencio('la IA está apagada o pausada en esta conversación');
        }

        if ($estado->contactoId === null) {
            return Decision::silencio('la conversación no tiene contacto asociado');
        }

        $contacto = $this->contactos->porId($estado->contactoId);

        if ($contacto === null || !$this->consentimientos->tieneVigente($contacto->id)) {
            return Decision::silencio('sin consentimiento vigente');
        }

        return $this->turnoDelModelo($chatwootConvId, $contacto->telefono, $contacto->id);
    }

    /**
     * Llama al modelo y entrega la respuesta.
     *
     * `Llm` corta si no hay modelo autorizado o si se agotó el presupuesto, y
     * las dos cosas terminan en escalamiento: es preferible que un humano
     * atienda a que el bot conteste con un modelo sin firma o gastando fuera
     * de presupuesto.
     */
    private function turnoDelModelo(int $chatwootConvId, string $telefono, string $contactoId): Decision
    {
        $estado = $this->conversaciones->porConversacion($chatwootConvId);

        if ($estado === null) {
            return Decision::silencio('la conversación desapareció');
        }

        // ── Horario del bot (Etapa 6) ────────────────────────────────────
        //
        // Fuera del horario configurado no se gasta un turno de modelo: se
        // responde con la plantilla de espera y la conversación queda
        // pausada hasta la apertura, para que una ráfaga nocturna no mande
        // veinte veces el mismo aviso. Las señales críticas NO pasan por
        // aquí: la regla 5 escala en `procesar()`, antes de este punto.
        $minutosParaAbrir = $this->minutosParaAbrir();

        if ($minutosParaAbrir > 0) {
            $texto = 'Gracias por escribir. En este momento estamos fuera del horario de '
                . 'atención, pero su mensaje quedó recibido: le respondemos a primera hora.';

            $this->outbox->encolar('chatwoot.entregar', [
                'chatwoot_conv_id' => $chatwootConvId,
                'texto' => $texto,
            ]);

            $this->conversaciones->pausar($chatwootConvId, $minutosParaAbrir);

            return Decision::respondio($texto, $estado->casoId, null);
        }

        $historial = $this->prompt->historialConBuffer($estado);

        try {
            $respuesta = $this->llm->chat(
                $this->prompt->sistema($estado),
                $historial,
                casoId: $estado->casoId,
            );
        } catch (LlmException $e) {
            $this->log->warn('motor.sin_respuesta_llm', [
                'conversacion' => $chatwootConvId,
                'motivo' => $e->motivo,
            ]);

            // Un fallo de infraestructura no puede dejar al contacto hablando
            // solo. Se escala, que es lo que un despacho haría si el
            // recepcionista se queda sin voz.
            return $this->escalar($chatwootConvId, $telefono, MotivoEscalamiento::ERROR_TECNICO);
        }

        $analisis = Accion::analizar($respuesta->texto);

        // Aunque el prompt lo prohíba, los modelos mezclan texto y JSON. Al
        // contacto no se le puede enseñar el JSON interno.
        $texto = Accion::limpiarTexto($respuesta->texto);

        if (trim($texto) === '') {
            $texto = 'Cuénteme un poco más, por favor.';
        }

        // La acción de escalar la puede pedir el propio modelo.
        if ($analisis->hayAccion() && $analisis->accion->nombre === 'ESCALAR_HUMANO') {
            return $this->escalar(
                $chatwootConvId,
                $telefono,
                MotivoEscalamiento::desde((string) $analisis->accion->dato('motivo')),
                casoId: $estado->casoId,
            );
        }

        $casoId = $this->aplicarAccion($analisis, $contactoId, $estado);

        // ── Acciones de agenda (Etapa 5) ─────────────────────────────────
        //
        // El modelo propone; la base dispone. Horarios, precio y enlace de
        // pago salen de plantillas con datos reales y se AÑADEN al texto del
        // modelo: lo factual nunca viene de lo generado.
        if ($this->agenda !== null && $analisis->hayAccion()) {
            $contacto = $this->contactos->porId($contactoId);

            if ($contacto !== null) {
                $resultado = $this->agenda->despachar($analisis->accion, $contactoId, $casoId, $contacto);

                if ($resultado->apendice !== null) {
                    $texto = rtrim($texto) . "\n\n" . $resultado->apendice;
                }

                if ($resultado->nuevoEstado !== null) {
                    $this->conversaciones->cambiarEstado($chatwootConvId, $resultado->nuevoEstado);
                }

                // Tocar una asesoría PAGADA lo resuelve una persona. El aviso
                // va sin carga útil, como manda la regla 14: motivo y número
                // de conversación, cero texto del contacto.
                if ($resultado->escalarPagada) {
                    $this->outbox->encolarAlertaEscalamiento(
                        $telefono,
                        MotivoEscalamiento::SOLICITUD_EXPRESA,
                        $chatwootConvId,
                    );
                }
            }
        }

        $this->conversaciones->guardarTurno(
            $chatwootConvId,
            [
                ...$historial,
                ['role' => 'assistant', 'content' => $texto],
            ],
            tokens: $respuesta->tokens(),
            casoId: $casoId,
        );

        $this->outbox->encolar('chatwoot.entregar', [
            'chatwoot_conv_id' => $chatwootConvId,
            'texto' => $texto,
        ]);

        // La explicación del saneador va como nota de diagnóstico, no como
        // respuesta: es para Pedro, no para el contacto. Sin esto, «el bot
        // respondió raro» no tiene forma de investigarse.
        if ($analisis->camposDescartados !== [] || $analisis->motivo === AnalisisAccion::ACCION_DESCONOCIDA) {
            $this->log->info('motor.saneador', [
                'conversacion' => $chatwootConvId,
                'explicacion' => $analisis->explicacion(),
            ]);
        }

        return Decision::respondio($texto, $casoId, $analisis);
    }

    /**
     * Ejecuta la acción del modelo sobre la base.
     *
     * @return string|null id del caso, si se creó o ya existía
     */
    private function aplicarAccion(
        AnalisisAccion $analisis,
        string $contactoId,
        ConversacionEstado $estado,
    ): ?string {
        if (!$analisis->hayAccion() || $analisis->accion->nombre !== 'REGISTRAR_CASO') {
            return $estado->casoId;
        }

        $datos = $analisis->accion->datos;

        if ($estado->casoId !== null) {
            $this->casos->actualizar($estado->casoId, $datos);

            return $estado->casoId;
        }

        $caso = $this->casos->crear($contactoId, $datos);

        $this->conversaciones->vincularCaso($estado->chatwootConvId, $caso->id);

        if (isset($datos['tipo_persona'])) {
            $this->contactos->actualizarTipoPersona($contactoId, (string) $datos['tipo_persona']);
        }

        if (isset($datos['nombre'])) {
            $this->contactos->actualizarNombre($contactoId, (string) $datos['nombre']);
        }

        return $caso->id;
    }

    /**
     * Pide el habeas data. **No persiste contenido del mensaje** (regla 1).
     *
     * Lo único que se escribe aquí es el contacto —teléfono y canal, que la
     * regla 1 autoriza expresamente— y el propio consentimiento cuando llegue.
     * El mensaje que el contacto acaba de mandar no se guarda en ninguna
     * parte, ni siquiera en el historial de la conversación.
     */
    private function pedirConsentimiento(
        int $chatwootConvId,
        string $telefonoE164,
        string $mensaje,
        ?int $chatwootContactId,
    ): Decision {
        $contacto = $this->contactos->porTelefono($telefonoE164)
            ?? $this->contactos->crear($telefonoE164, 'whatsapp', $chatwootContactId);

        $this->conversaciones->buscarOCrear($chatwootConvId, $contacto->id);

        $ultimo = $this->consentimientos->ultimoPorContacto($contacto->id);

        // Ya dijo que no. No se le vuelve a preguntar en bucle: eso es
        // precisamente lo que la Ley 1581 no quiere. Solo se reabre si es él
        // quien vuelve aceptando.
        if ($ultimo !== null && !$ultimo->otorgado) {
            if (!$this->pareceNegativa($mensaje) && $this->pareceAceptacion($mensaje)) {
                return $this->registrarAceptacion($chatwootConvId, $contacto->id, $telefonoE164);
            }

            $this->conversaciones->cambiarEstado($chatwootConvId, Estados::CERRADO);

            return Decision::silencio('el contacto no autorizó el tratamiento de datos');
        }

        // Que el mensaje sea un «sí» solo significa algo si ya se le mostró el
        // aviso. La señal de eso es el estado de la conversación, NO que exista
        // una fila de consentimiento: enseñar el aviso no crea ninguna fila,
        // así que condicionarlo a la fila hacía que el «sí» no se reconociera
        // jamás y el contacto se quedara atrapado en el aviso.
        $yaSeMostro = $this->conversaciones->porConversacion($chatwootConvId)?->nodo()
            === Estados::CONSENTIMIENTO;

        // La negativa se comprueba PRIMERO y gana siempre. «No autorizo»
        // contiene «autorizo»: con el orden al revés se registraba
        // consentimiento donde había un rechazo, que es la peor forma posible
        // de equivocarse en esta puerta. Ante cualquier ambigüedad, no se
        // registra el consentimiento.
        if ($yaSeMostro && $this->pareceNegativa($mensaje)) {
            $this->consentimientos->registrar(
                $contacto->id,
                (string) $this->config->get('version_politica_datos', 'v1'),
                $this->textoAviso(),
                otorgado: false,
                evidencia: ['canal' => 'whatsapp', 'chatwoot_conv_id' => $chatwootConvId],
            );

            $this->conversaciones->cambiarEstado($chatwootConvId, Estados::CERRADO);

            $this->outbox->encolar('chatwoot.entregar', [
                'chatwoot_conv_id' => $chatwootConvId,
                'texto' => 'Entendido. Sin esa autorización no puedo continuar por este medio. '
                    . 'Si cambia de opinión, escríbame de nuevo.',
            ]);

            return Decision::silencio('el contacto rechazó el tratamiento de datos');
        }

        if ($yaSeMostro && $this->pareceAceptacion($mensaje)) {
            return $this->registrarAceptacion($chatwootConvId, $contacto->id, $telefonoE164);
        }

        // Primera vez: se muestra el aviso.
        $this->conversaciones->cambiarEstado($chatwootConvId, Estados::CONSENTIMIENTO);

        $this->outbox->encolar('chatwoot.entregar', [
            'chatwoot_conv_id' => $chatwootConvId,
            'texto' => $this->textoAviso(),
        ]);

        return Decision::silencio('esperando autorización de tratamiento de datos');
    }

    private function registrarAceptacion(int $chatwootConvId, string $contactoId, string $telefono): Decision
    {
        $this->consentimientos->registrar(
            $contactoId,
            (string) $this->config->get('version_politica_datos', 'v1'),
            $this->textoAviso(),
            otorgado: true,
            evidencia: [
                'canal' => 'whatsapp',
                'chatwoot_conv_id' => $chatwootConvId,
                // Sin el texto del mensaje: la evidencia de que aceptó es que
                // hay fila, no una transcripción.
                'aceptado_en' => date('c'),
            ],
        );

        $this->conversaciones->cambiarEstado($chatwootConvId, Estados::TRIAGE);

        $this->outbox->encolar('chatwoot.entregar', [
            'chatwoot_conv_id' => $chatwootConvId,
            'texto' => 'Gracias. Cuénteme qué pasó: qué documento recibió o qué le informaron, '
                . 'y de qué entidad.',
        ]);

        return Decision::silencio('consentimiento registrado, esperando el relato');
    }

    /**
     * Escala a humano y apaga la IA (reglas 5, 8 y 14).
     *
     * `$fraseDetectada` es **la frase del catálogo**, nunca el texto del
     * contacto: `SenalesCriticas::cual()` devuelve la entrada de la lista que
     * disparó, y eso es una constante nuestra. Se registra para poder afinar
     * el catálogo, y no viaja en la alerta.
     */
    private function escalar(
        int $chatwootConvId,
        string $telefono,
        MotivoEscalamiento $motivo,
        ?string $fraseDetectada = null,
        ?string $casoId = null,
    ): Decision {
        $this->conversaciones->apagarIa($chatwootConvId, Estados::HUMANO);

        // Metadato en la bandeja: prioridad, asignación y estado. Sin texto.
        $this->outbox->encolar('chatwoot.escalar', [
            'chatwoot_conv_id' => $chatwootConvId,
            'prioridad' => $motivo->prioridadChatwoot(),
        ]);

        $this->outbox->encolar('chatwoot.etiquetar', [
            'chatwoot_conv_id' => $chatwootConvId,
            'etiquetas' => ['escalado', $motivo->value],
        ]);

        // Regla 14: la firma de este método es la que impide que aquí entre
        // texto del contacto. Ver `Outbox::encolarAlertaEscalamiento()`.
        $this->outbox->encolarAlertaEscalamiento($telefono, $motivo, $chatwootConvId);

        $this->log->warn('motor.escalado', [
            'conversacion' => $chatwootConvId,
            'motivo' => $motivo->value,
            // La frase del catálogo, no lo que escribió el contacto.
            'senal' => $fraseDetectada,
        ]);

        return Decision::escalo($motivo, $casoId);
    }

    private function textoAviso(): string
    {
        $texto = (string) $this->config->get('texto_aviso_habeas_data', '');

        if (trim($texto) === '') {
            // Que falte el texto aprobado por Pedro es un bloqueo de cierre de
            // etapa, no una excusa para inventarse un aviso legal. Este
            // provisional es deliberadamente mínimo y se ve que lo es.
            return 'Antes de continuar necesito su autorización para tratar sus datos '
                . 'personales conforme a la Ley 1581 de 2012. ¿Autoriza? (sí / no)';
        }

        return $texto;
    }

    /**
     * Delegan en `Afirmacion`, que resuelve las tres posibilidades a la vez.
     *
     * No se reimplementan aquí con dos regex separadas: así era como «no
     * autorizo» acababa registrado como consentimiento. La clase evalúa la
     * negación primero y devuelve `null` cuando no puede saberlo, que es lo
     * que impide tratar el silencio como una respuesta.
     */
    private function pareceAceptacion(string $mensaje): bool
    {
        return Afirmacion::esAfirmativa($mensaje);
    }

    private function pareceNegativa(string $mensaje): bool
    {
        return Afirmacion::esNegativa($mensaje);
    }

    private function encendido(string $clave, bool $porDefecto): bool
    {
        $valor = $this->config->get($clave, $porDefecto);

        return $valor === true || $valor === 'true' || $valor === 1 || $valor === '1';
    }

    /**
     * Minutos que faltan para que abra el bot; 0 si está abierto.
     *
     * `horario_atencion_bot` = `{"inicio":"07:00","fin":"20:00","dias":[1..6]}`
     * con los días en la convención de `Fechas::diaSemana()` (0 = domingo).
     *
     * Una config ausente o rota significa **bot abierto 24/7**, no bot mudo:
     * el fallo seguro para un embudo de captación es responder, y el
     * registro deja constancia de que el horario no se pudo leer.
     */
    private function minutosParaAbrir(): int
    {
        $crudo = $this->config->get('horario_atencion_bot');

        $horario = is_string($crudo) ? json_decode($crudo, true) : $crudo;

        if (!is_array($horario)
            || !is_string($horario['inicio'] ?? null)
            || !is_string($horario['fin'] ?? null)
            || !is_array($horario['dias'] ?? null)
            || $horario['dias'] === []) {
            if ($crudo !== null) {
                $this->log->warn('motor.horario_ilegible', []);
            }

            return 0;
        }

        $ahora = \App\Soporte\Fechas::ahora();
        $dias = array_map(intval(...), $horario['dias']);

        // Se busca la próxima apertura recorriendo hasta una semana. Si hoy
        // es día hábil y estamos dentro de la franja, la respuesta es 0.
        for ($salto = 0; $salto <= 7; $salto++) {
            $dia = $ahora->modify("+{$salto} days");

            if (!in_array((int) $dia->format('w'), $dias, true)) {
                continue;
            }

            $abre = new \DateTimeImmutable(
                $dia->format('Y-m-d') . ' ' . $horario['inicio'],
                $ahora->getTimezone(),
            );
            $cierra = new \DateTimeImmutable(
                $dia->format('Y-m-d') . ' ' . $horario['fin'],
                $ahora->getTimezone(),
            );

            if ($salto === 0 && $ahora >= $abre && $ahora < $cierra) {
                return 0;
            }

            if ($abre > $ahora) {
                return (int) ceil(($abre->getTimestamp() - $ahora->getTimestamp()) / 60);
            }
        }

        // Ningún día activo en la semana: horario mal configurado. Abierto.
        $this->log->warn('motor.horario_sin_dias_validos', []);

        return 0;
    }
}
