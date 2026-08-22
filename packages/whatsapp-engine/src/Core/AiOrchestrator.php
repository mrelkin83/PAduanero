<?php
/**
 * ============================================================================
 * AiOrchestrator — el bucle mensaje → LLM → herramientas → respuesta
 * ============================================================================
 * Es la pieza que ata todo, y la que menos decide: el prompt lo compone
 * PromptComposer, los datos los da ToolEngine, el proveedor lo elige
 * LlmProviderManager. Aquí solo se lleva el turno.
 *
 * El bucle está ACOTADO a MAX_VUELTAS. Un modelo puede quedarse pidiendo
 * herramientas indefinidamente; sin tope, una sola conversación se come el
 * presupuesto del mes del negocio.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

use ElkinLinan\WhatsappAiEngine\Engine;

use ElkinLinan\WhatsappAiEngine\Providers\LlmProviderManager;


class AiOrchestrator
{
    /** Vueltas de herramientas por mensaje. Con 6 sobra para pedir y cobrar. */
    const MAX_VUELTAS = 6;
    /** Fallos seguidos de herramienta antes de rendirse y llamar a una persona. */
    const MAX_FALLOS = 2;

    private $db;
    private $log;
    private $canal;
    private $conv;
    private $agentes;
    private $adapter;
    private $tools;
    private $llm;

    public function __construct($db, $canal, $log, $pagos = null)
    {
        $this->db      = $db;
        $this->canal   = $canal;
        $this->log     = $log;
        $this->conv    = new ConversationManager($db);
        $this->agentes = new AgentManager($db);
        // El adaptador lo pone el proyecto: el motor no puede saber si vende
        // platos o iPhones. Antes lo instanciaba el motor —y con eso el motor
        // solo servia para un negocio.
        $this->adapter = Engine::dominio();
        $this->tools   = new ToolEngine($db, $this->adapter, $log, $pagos);
        $this->llm     = new LlmProviderManager($db, $log);
    }

    /**
     * Procesa un mensaje entrante YA normalizado y con su media ya convertida
     * a texto. Devuelve lo que se le respondió al cliente ('' si no se
     * respondió, que es lo correcto cuando hay un humano atendiendo).
     */
    public function procesar(array $conversacion, string $textoUsuario): string
    {
        $cfg    = WaConfig::cargar($this->db);
        $agente = $this->agentes->activo();

        // Un humano al mando: la IA calla (§36).
        if (!HumanHandoff::iaPuedeResponder($conversacion)) {
            return '';
        }

        // Fuera de horario: se informa y no se toma pedido. La decisión NO se
        // deja al modelo — es una regla del negocio, no una interpretación.
        if (!WaConfig::abiertoAhora($cfg) && trim($textoUsuario) !== '') {
            $ctxCli = $this->adapter->contextoCliente($conversacion);
            // Se sigue respondiendo con el modelo (puede resolver dudas), pero
            // crear_pedido ya está bloqueado dentro de ToolEngine.
            unset($ctxCli);
        }

        $ctxCli = $this->adapter->contextoCliente($conversacion);
        $defs   = $this->tools->definiciones($agente, $cfg);
        $system = PromptComposer::componer($cfg, $agente, $ctxCli, $defs);

        $mensajes = $this->conv->historial((int)$conversacion['id']);
        // El mensaje actual ya está guardado por el receptor, así que ya viene
        // en el historial. Si no estuviera (reproceso manual), se añade.
        if (!$mensajes || end($mensajes)['role'] !== 'user') {
            $mensajes[] = ['role' => 'user', 'content' => $textoUsuario];
        }

        $ctxTool = [
            'conversacion' => $conversacion,
            'config'       => $cfg,
            'agente'       => $agente,
            'canal'        => $this->canal,
        ];

        $fallosSeguidos = 0;
        $textoFinal = '';
        $ultimoUso  = ['proveedor' => '', 'modelo' => '', 'tokens_in' => 0, 'tokens_out' => 0, 'latencia' => 0];

        // Llamadas ya hechas EN ESTE TURNO, para no repetirlas. Ver más abajo.
        $llamadasVistas = [];
        $repeticiones   = 0;

        for ($vuelta = 1; $vuelta <= self::MAX_VUELTAS; $vuelta++) {
            // Si el modelo ya se atascó repitiendo, se le retiran las
            // herramientas y se le obliga a contestar con lo que ya tiene.
            //
            // Esto NO es teoría: medido contra Groq/Llama 3.3, mientras se le
            // sigan ofreciendo las herramientas vuelve a llamar a la que acaba
            // de usar, una y otra vez, hasta agotar las vueltas — y entonces el
            // cliente recibía «no puedo completar la operación» después de que
            // el sistema hubiera consultado el menú correctamente. Sin
            // herramientas en la petición, el mismo modelo responde bien.
            $peticion = ['system' => $system, 'messages' => $mensajes];
            if ($repeticiones < 2) $peticion['tools'] = $defs;

            $res = $this->llm->chat($peticion, (int)$conversacion['id']);

            $ultimoUso = [
                'proveedor' => $res['proveedor_usado'] ?? '',
                'modelo'    => $res['modelo'] ?? '',
                'tokens_in' => (int)($res['tokens_in'] ?? 0),
                'tokens_out'=> (int)($res['tokens_out'] ?? 0),
                'latencia'  => (int)($res['latencia_ms'] ?? 0),
            ];

            if (!$res['ok']) {
                // El proveedor y su respaldo fallaron: no se improvisa nada.
                $this->transferir($conversacion, 'Fallo del proveedor de IA', $cfg);
                return $this->responder($conversacion, $this->agentes->mensajeError($agente), $ultimoUso);
            }

            if (empty($res['tool_calls'])) {
                $textoFinal = trim($res['texto']);
                break;
            }

            // Turno del asistente con sus llamadas, tal cual, para que el modelo
            // vea lo que pidió cuando le lleguen los resultados.
            $mensajes[] = ['role' => 'assistant', 'content' => $res['texto'] ?? '', 'tool_calls' => $res['tool_calls']];

            foreach ($res['tool_calls'] as $tc) {
                if (!empty($tc['args_invalidos'])) {
                    // Se registra CON el texto crudo: sin esto, "argumentos
                    // ilegibles" era un callejón sin salida en la bitácora.
                    $this->log->log('tool', 'Argumentos ilegibles en ' . $tc['name'],
                        ['tool' => $tc['name'], 'crudo' => $tc['args_crudos'] ?? ''],
                        (int)$conversacion['id']);

                    // Si la herramienta NO exige ningún parámetro, un JSON mal
                    // formado da igual: se ejecuta sin argumentos. Los modelos
                    // abiertos emiten JSON roto justamente en las llamadas sin
                    // parámetros (consultar_menu, consultar_promociones), y
                    // contarlo como fallo mandaba la conversación a una persona
                    // por algo que no tenía la menor importancia.
                    // ToolEngine sigue validando: esto no relaja nada.
                    if (!$this->exigeParametros($defs, $tc['name'])) {
                        $tc['arguments'] = [];
                    } else {
                        $mensajes[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'name' => $tc['name'],
                                       'content' => json_encode(['ok' => false, 'error' => 'No entendí los parámetros; vuelve a enviarlos como JSON válido'], JSON_UNESCAPED_UNICODE)];
                        $fallosSeguidos++;
                        continue;
                    }
                }
                // ¿Esta misma llamada, con estos mismos parámetros, ya se hizo
                // en este turno? Entonces no se vuelve a ejecutar: se le
                // devuelve al modelo un aviso para que conteste. Además de
                // ahorrar vueltas y dinero, evita repetir efectos secundarios.
                $huella = $tc['name'] . '|' . md5(json_encode($tc['arguments']));
                if (isset($llamadasVistas[$huella])) {
                    $repeticiones++;
                    $mensajes[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'name' => $tc['name'],
                        'content' => json_encode([
                            'ok' => true,
                            'repetida' => true,
                            'aviso' => 'Ya consultaste esto en este mismo turno y tienes el resultado más arriba. '
                                     . 'Responde ahora al cliente con esos datos; no vuelvas a llamar a esta herramienta.',
                        ], JSON_UNESCAPED_UNICODE)];
                    $this->log->log('tool', 'Llamada repetida evitada: ' . $tc['name'],
                        ['vuelta' => $vuelta], (int)$conversacion['id']);
                    continue;
                }
                $llamadasVistas[$huella] = true;

                $r = $this->tools->ejecutar($tc['name'], $tc['arguments'], $ctxTool);
                $fallosSeguidos = empty($r['ok']) ? $fallosSeguidos + 1 : 0;

                $mensajes[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'name' => $tc['name'],
                               'content' => json_encode($r, JSON_UNESCAPED_UNICODE)];

                // La transferencia corta el turno: a partir de aquí manda una persona.
                if ($tc['name'] === 'transferir_a_humano' && !empty($r['transferido'])) {
                    return $this->responder($conversacion,
                        $r['mensaje_para_el_cliente'] ?? 'Le paso tu caso a una persona del equipo.', $ultimoUso);
                }

                // Refrescar la conversación: una herramienta pudo cambiar su
                // contexto (datos de entrega, ticket abierto).
                $ref = $this->conv->refrescar((int)$conversacion['id']);
                if ($ref) { $conversacion = $ref; $ctxTool['conversacion'] = $ref; }
            }

            if ($fallosSeguidos >= self::MAX_FALLOS) {
                $this->transferir($conversacion, 'Varias herramientas fallaron seguidas', $cfg);
                return $this->responder($conversacion, $this->agentes->mensajeError($agente), $ultimoUso);
            }
        }

        if ($textoFinal === '') {
            // Se agotaron las vueltas sin una respuesta para el cliente. Callar
            // sería peor: el cliente se queda mirando el chat.
            $this->log->log('llm', 'Se agotaron las vueltas de herramientas sin respuesta final', null, (int)$conversacion['id']);
            $this->transferir($conversacion, 'La conversación se enredó y no se resolvió sola', $cfg);
            $textoFinal = $this->agentes->mensajeError($agente);
        }

        return $this->responder($conversacion, $textoFinal, $ultimoUso);
    }

    /** Envía la respuesta por WhatsApp y la deja guardada. */
    private function responder(array $conv, string $texto, array $uso): string
    {
        $texto = trim($texto);
        if ($texto === '') return '';

        // WhatsApp corta los mensajes muy largos; además, un muro de texto en el
        // móvil no se lee. Se parte por párrafos antes de llegar al límite.
        foreach ($this->partir($texto) as $trozo) {
            $envio = $this->canal->enviarTexto($conv['telefono'], $trozo);
            $this->conv->guardarMensaje((int)$conv['id'], 'saliente', [
                'message_id' => $envio['message_id'] ?? null,
                'tipo'       => 'texto',
                'contenido'  => $trozo,
                'proveedor'  => $uso['proveedor'],
                'modelo'     => $uso['modelo'],
                'tokens_in'  => $uso['tokens_in'],
                'tokens_out' => $uso['tokens_out'],
                'latencia_ms'=> $uso['latencia'],
            ]);
            if (empty($envio['ok'])) {
                $this->log->error('No se pudo enviar la respuesta: ' . ($envio['error'] ?? ''), null, (int)$conv['id']);
            }
            // Solo el primer trozo lleva el consumo; los demás son el mismo turno.
            $uso = ['proveedor' => $uso['proveedor'], 'modelo' => $uso['modelo'],
                    'tokens_in' => 0, 'tokens_out' => 0, 'latencia' => 0];
        }
        $this->conv->tocar((int)$conv['id']);
        return $texto;
    }

    /** Parte un texto largo en trozos de ~1200 caracteres por párrafos. */
    private function partir(string $texto, int $max = 1200): array
    {
        if (mb_strlen($texto) <= $max) return [$texto];
        $trozos = []; $actual = '';
        foreach (preg_split('/\n\n+/', $texto) as $parrafo) {
            if (mb_strlen($actual) + mb_strlen($parrafo) + 2 > $max && $actual !== '') {
                $trozos[] = trim($actual);
                $actual = '';
            }
            $actual .= ($actual === '' ? '' : "\n\n") . $parrafo;
        }
        if (trim($actual) !== '') $trozos[] = trim($actual);
        // Un solo párrafo gigantesco: se corta duro antes que no enviarlo.
        $out = [];
        foreach ($trozos as $t) {
            while (mb_strlen($t) > $max) {
                $out[] = mb_substr($t, 0, $max);
                $t = mb_substr($t, $max);
            }
            if ($t !== '') $out[] = $t;
        }
        return $out;
    }

    /** ¿La herramienta declara parámetros obligatorios? */
    private function exigeParametros(array $defs, string $nombre): bool
    {
        foreach ($defs as $d) {
            if (($d['name'] ?? '') === $nombre) {
                return !empty($d['parameters']['required']);
            }
        }
        return true;   // desconocida: mejor pecar de estricto
    }

    private function transferir(array $conv, string $motivo, ?array $cfg): void
    {
        $h = new HumanHandoff($this->db, $this->log);
        $h->transferir((int)$conv['id'], $motivo, $this->canal, $cfg);
    }
}
