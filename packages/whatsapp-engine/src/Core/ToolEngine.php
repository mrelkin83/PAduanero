<?php
/**
 * ============================================================================
 * ToolEngine — el registro de herramientas y la frontera de autorización
 * ============================================================================
 * Aquí es donde de verdad se cumple el §37. El modelo NO ve la base de datos:
 * ve un catálogo de funciones con firma tipada. Y —esto es lo importante— la
 * autorización vive AQUÍ, no en el prompt. Que un cliente convenza al modelo de
 * aprobar un pago no sirve de nada: `validar_pago` consulta a la pasarela, no
 * al modelo. Contra la inyección de prompts (§38), esta es la defensa real; el
 * texto del prompt es solo la primera capa.
 *
 * Toda ejecución comprueba, en este orden y antes de tocar nada:
 *   1. que la herramienta exista y esté en la lista blanca del agente,
 *   2. que el plan del negocio incluya la función,
 *   3. que los parámetros validen contra el esquema,
 *   4. que la conversación siga en IA_ACTIVA,
 * y queda registrada en wa_eventos con su resultado.
 *
 * NOTA DE IMPLEMENTACIÓN: el plan de la FASE 2 decía "una herramienta = un
 * archivo". Se agrupan en un registro único porque el proyecto no tiene
 * autocarga de clases: habrían sido 18 `require_once` y 18 clases envoltorio
 * casi idénticas. La frontera de seguridad es la misma; lo que cambia es dónde
 * viven las funciones.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

use ElkinLinan\WhatsappAiEngine\Ports\DomainAdapter;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaCitas;

use ElkinLinan\WhatsappAiEngine\Payments\PaymentManager;

class ToolEngine
{
    private $db;
    private $adapter;
    private $log;
    private $pagos;

    public function __construct($db, DomainAdapter $adapter, $log = null, $pagos = null)
    {
        $this->db      = $db;
        $this->adapter = $adapter;
        $this->log     = $log;
        $this->pagos   = $pagos;
    }

    /** Capacidades que este negocio declaró, en memoria por petición. */
    private ?array $caps = null;

    private function tieneCapacidad(string $c): bool
    {
        if ($this->caps === null) {
            try { $this->caps = $this->adapter->capacidades(); }
            catch (\Throwable $e) { $this->caps = []; }
        }
        return in_array($c, $this->caps, true);
    }

    /**
     * Catálogo completo. 'feature' exige una bandera de plan; 'capacidad' exige
     * que el negocio la haya declarado en capacidades(); 'siempre' marca las que
     * no se pueden deshabilitar (transferir a un humano lo es: dejar a un
     * cliente sin salida hacia una persona no puede ser configurable).
     *
     * Lo de 'capacidad' no es cosmética y hasta ahora NO SE CUMPLÍA: el contrato
     * prometía «el motor solo le enseña al modelo lo que declares» y el catálogo
     * ofrecía el almuerzo del día a una tienda de teléfonos y no ofrecía las
     * garantías a nadie. Cada herramienta que sobra son tokens en todos los
     * mensajes y una puerta que el modelo acaba cruzando para nada.
     */
    public function catalogo(): array
    {
        return [
            'consultar_menu' => [
                'description' => 'Consulta lo que el negocio vende ahora mismo, con su precio y cuántas unidades quedan. Úsala siempre antes de hablar de productos o precios: nunca los inventes ni los recuerdes de mensajes anteriores.',
                // busqueda y filtros son OPCIONALES y admiten null: algunos
                // modelos (p. ej. gpt-oss de Groq) mandan `null` en vez de omitir
                // el parámetro, y un proveedor que valida el schema estricto
                // (Groq) rechaza la llamada entera si el tipo no permite null —
                // dejando al bot sin poder consultar el menú y cayendo en handoff.
                'parameters' => ['type' => 'object', 'properties' => [
                    'busqueda' => ['type' => ['string', 'null'], 'description' => 'Filtra por nombre, marca o categoría. Omítelo (o null) para ver el catálogo completo.'],
                    'filtros'  => ['type' => ['object', 'null'], 'description' => 'Filtros propios de este negocio (por ejemplo marca, capacidad de almacenamiento o color). Usa solo los que el cliente haya mencionado; los que este negocio no entienda se ignoran.'],
                ], 'required' => []],
            ],
            'consultar_stock' => [
                'description' => 'Cuántas unidades de un producto se pueden vender por WhatsApp en este momento. Consúltalo antes de confirmar cantidades.',
                // El id va como TEXTO porque el contrato lo trata como texto
                // (`detalleItem(string $id)`): un negocio puede identificar lo
                // que vende con algo que no es un número. El validador
                // rechazaba «p3» por no ser numérico.
                'parameters' => ['type' => 'object', 'properties' => [
                    'producto_id' => ['type' => 'string', 'description' => 'Id del producto, copiado tal cual de consultar_menu'],
                ], 'required' => ['producto_id']],
            ],
            'consultar_promociones' => [
                'description' => 'Promociones vigentes en este momento.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                'feature'   => 'permite_promociones',
                'capacidad' => 'promociones',
            ],
            'consultar_almuerzo_del_dia' => [
                'description' => 'El menú del día de hoy con cuántas porciones quedan y en qué horario se sirve.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                'capacidad' => 'menu_del_dia',
            ],
            'consultar_opciones' => [
                'description' => 'Componentes y opciones de un producto que se ARMA por partes (un menú del día, una hamburguesa con adicionales). Llámala ANTES de calcular el total o crear el pedido si el producto viene marcado como configurable: sin las opciones elegidas el pedido se rechaza. Devuelve, por cada componente, cuántas opciones hay que elegir y cuáles suman precio.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'producto_id' => ['type' => 'integer'],
                ], 'required' => ['producto_id']],
                'capacidad' => 'configurables',
            ],
            'enviar_enlace_para_armar' => [
                'description' => 'Manda al cliente un enlace para que arme el producto en una pantalla, con todas las opciones a la vista. Úsala cuando el producto tenga MUCHOS componentes (tres o más) y preguntarlos uno por uno haría la conversación interminable. Para dos componentes o menos, es mejor preguntar tú por aquí. Cuando el cliente termine, recibirá la confirmación por este mismo chat.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'producto_id' => ['type' => 'integer'],
                    'cantidad'    => ['type' => 'integer', 'description' => 'Cuántos quiere, por defecto 1'],
                ], 'required' => ['producto_id']],
                'capacidad' => 'armado_en_pantalla',
            ],
            'calcular_total' => [
                'description' => 'Calcula el total de un carrito SIN crear el pedido. El servidor pone los precios: nunca calcules tú el total ni se lo digas al cliente sin haber llamado a esta herramienta.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'items' => ['type' => 'array', 'description' => 'Productos y cantidades', 'items' => [
                        'type' => 'object', 'properties' => [
                            // Texto además de número, por la misma razón que en
                            // consultar_stock: el contrato trata el id como
                            // texto y hay negocios que identifican con UUID.
                            'producto_id' => ['type' => ['integer', 'string'], 'description' => 'Id copiado tal cual de consultar_menu'],
                            'cantidad'    => ['type' => 'integer'],
                            'opciones' => ['type' => 'array', 'description' => 'ids de las opciones elegidas, si el producto es configurable', 'items' => ['type' => 'integer']],
                        ], 'required' => ['producto_id', 'cantidad']]],
                    'modo_entrega' => ['type' => 'string', 'enum' => ['domicilio', 'recoger'],
                                       'description' => 'Si es domicilio, se suma el costo de envío'],
                ], 'required' => ['items']],
            ],
            'registrar_datos_entrega' => [
                'capacidad' => 'entrega',
                'description' => 'Guarda los datos de entrega del cliente. Para domicilio la dirección es obligatoria.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'modo_entrega' => ['type' => 'string', 'enum' => ['domicilio', 'recoger']],
                    'nombre'       => ['type' => 'string'],
                    'direccion'    => ['type' => 'string'],
                    'barrio'       => ['type' => 'string'],
                    'ciudad'       => ['type' => 'string'],
                    'referencias'  => ['type' => 'string', 'description' => 'Indicaciones para llegar'],
                ], 'required' => ['modo_entrega']],
            ],
            'crear_pedido' => [
                'description' => 'Crea el pedido de verdad en el sistema del negocio. Llámala SOLO cuando el cliente ya confirmó lo que quiere y ya registraste sus datos de entrega. El pedido NO se manda a cocina hasta que el pago esté verificado.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'items' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'properties' => [
                            'producto_id' => ['type' => ['integer', 'string'], 'description' => 'Id copiado tal cual de consultar_menu'],
                            'cantidad'    => ['type' => 'integer'],
                            'opciones' => ['type' => 'array', 'description' => 'ids de las opciones elegidas, si el producto es configurable', 'items' => ['type' => 'integer']],
                        ], 'required' => ['producto_id', 'cantidad']]],
                    'observaciones' => ['type' => 'string', 'description' => 'Indicaciones del cliente sobre la preparación'],
                ], 'required' => ['items']],
            ],
            'consultar_pedido' => [
                'description' => 'Estado de un pedido del cliente. Sin id, devuelve todos sus pedidos abiertos.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'pedido_id' => ['type' => 'integer'],
                ], 'required' => []],
            ],
            'cancelar_pedido' => [
                'description' => 'Cancela un pedido que todavía no entró a cocina.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'pedido_id' => ['type' => 'integer'],
                ], 'required' => ['pedido_id']],
            ],
            'generar_pago' => [
                'description' => 'Cierra el cobro de un pedido con la forma de pago que eligió el cliente. Si el negocio acepta varias, pregúntale ANTES cuál prefiere: la herramienta te dice cuáles acepta.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'pedido_id' => ['type' => 'integer'],
                    'metodo'    => ['type' => 'string', 'enum' => ['wompi', 'transferencia', 'contra_entrega'],
                                    'description' => 'Lo que eligió el cliente: wompi = enlace de pago en línea, transferencia = Nequi/Bre-B/banco con comprobante, contra_entrega = paga al recibir. Omítelo solo si el negocio acepta una sola forma.'],
                ], 'required' => ['pedido_id']],
            ],
            'consultar_pago' => [
                'description' => 'Estado real del pago de un pedido, consultado a la pasarela. NUNCA afirmes que un pago está confirmado sin llamar a esta herramienta.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'pedido_id' => ['type' => 'integer'],
                ], 'required' => ['pedido_id']],
            ],
            'consultar_estado_cocina' => [
                'description' => 'En qué punto de la preparación va un pedido ya pagado.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'pedido_id' => ['type' => 'integer'],
                ], 'required' => ['pedido_id']],
            ],
            /* ── Postventa de producto duradero ─────────────────────────
               Un bar no tiene nada de esto; una tienda de tecnología lo
               responde a diario. Solo se le enseñan al modelo si el negocio
               las declaró en capacidades(). */

            'consultar_garantia' => [
                'capacidad' => 'garantias',
                'description' => 'Consulta la garantía de un equipo por su IMEI, su número de serie o el número de garantía. Dice si está vigente y hasta cuándo. No afirmes nunca que algo está en garantía sin haberlo consultado aquí.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'identificador' => ['type' => 'string', 'description' => 'IMEI, número de serie o número de garantía, tal como lo dio el cliente'],
                ], 'required' => ['identificador']],
            ],
            'cotizar_servicio_tecnico' => [
                'capacidad' => 'servicio_tecnico',
                'description' => 'Da un estimado de lo que costaría reparar un equipo. Es un ESTIMADO: díselo así al cliente, el precio final sale del diagnóstico.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'tipo_equipo' => ['type' => 'string', 'description' => 'Celular, portátil, tablet, consola…'],
                    'falla'       => ['type' => 'string', 'description' => 'Lo que le pasa, en palabras del cliente'],
                    'marca'       => ['type' => 'string'],
                    'modelo'      => ['type' => 'string'],
                ], 'required' => ['tipo_equipo', 'falla']],
            ],
            'crear_orden_servicio' => [
                'capacidad' => 'servicio_tecnico',
                'description' => 'Abre la orden de servicio para un equipo que el cliente va a dejar en el taller. Llámala solo cuando el cliente confirme que lo trae, y devuélvele el número de orden y el enlace de seguimiento.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'tipo_equipo' => ['type' => 'string'],
                    'marca'       => ['type' => 'string'],
                    'modelo'      => ['type' => 'string'],
                    'imei_serial' => ['type' => 'string', 'description' => 'IMEI o serie del equipo, si el cliente lo tiene a mano'],
                    'falla'       => ['type' => 'string', 'description' => 'La falla que reporta el cliente'],
                    'accesorios'  => ['type' => 'string', 'description' => 'Qué deja con el equipo (cargador, funda, caja…)'],
                    'nombre'      => ['type' => 'string', 'description' => 'Nombre de quien deja el equipo'],
                ], 'required' => ['tipo_equipo', 'falla']],
            ],
            'consultar_orden_servicio' => [
                'capacidad' => 'servicio_tecnico',
                'description' => 'En qué va la reparación de un equipo, por su número de orden.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'numero_orden' => ['type' => 'string'],
                ], 'required' => ['numero_orden']],
            ],
            'consultar_saldo_credito' => [
                'capacidad' => 'credito',
                'description' => 'Cuánto debe el cliente con el que hablas y cuándo vence. Solo funciona si ya está identificado como cliente del negocio; nunca preguntes por el saldo de otra persona.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],

            /* ── Citas ──────────────────────────────────────────────────
               Un negocio que vende tiempo (una asesoría, una consulta)
               declara la capacidad 'citas'. La reserva DE VERDAD la hace
               crear_pedido, que es atómico; estas herramientas solo miran
               y anotan — mismo reparto que entre consultar_stock y
               crear_pedido. */

            'consultar_disponibilidad' => [
                'capacidad' => 'citas',
                'description' => 'Horarios realmente agendables entre dos fechas, consultados ahora contra la agenda del negocio. Úsala SIEMPRE antes de proponer una hora: nunca ofrezcas horarios de memoria ni de mensajes anteriores. Al cliente propónle 2 o 3 opciones, no la lista entera.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'desde' => ['type' => 'string', 'description' => "Inicio del rango, 'YYYY-MM-DD HH:MM' en hora de Colombia"],
                    'hasta' => ['type' => 'string', 'description' => "Fin del rango, 'YYYY-MM-DD HH:MM'. Un rango de 2 a 5 días funciona bien"],
                ], 'required' => ['desde', 'hasta']],
            ],
            'registrar_datos_cita' => [
                'capacidad' => 'citas',
                'description' => 'Anota la franja que eligió el cliente y sus datos para la cita. Llámala cuando ya eligió una hora de las que le diste con consultar_disponibilidad. La cita queda creada de verdad solo al llamar después a crear_pedido.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'inicio' => ['type' => 'string', 'description' => "La franja elegida, 'YYYY-MM-DD HH:MM', copiada tal cual de consultar_disponibilidad"],
                    'nombre' => ['type' => 'string', 'description' => 'Nombre completo de quien asiste'],
                    'correo' => ['type' => 'string', 'description' => 'Correo al que llega la invitación con el enlace de la reunión'],
                    'telefono' => ['type' => 'string', 'description' => 'Número de contacto con indicativo de país, ej. 573001234567. Pídeselo SIEMPRE al cliente: WhatsApp puede ocultar su número real y el negocio necesita poder llamarlo'],
                    'motivo' => ['type' => 'string', 'description' => 'El asunto de la cita en una frase, en palabras del cliente'],
                ], 'required' => ['inicio', 'nombre']],
            ],
            'consultar_cita' => [
                'capacidad' => 'citas',
                'description' => 'Las citas de este cliente: cuándo son y en qué estado están. Solo las de la persona con la que hablas.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],

            'transferir_a_humano' => [
                'description' => 'Pasa la conversación a una persona del equipo. Úsala si el cliente lo pide, si hay un reclamo, si algo falla dos veces, o si te piden algo que no puedes resolver con tus herramientas.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'motivo' => ['type' => 'string', 'description' => 'Por qué se transfiere, en una frase'],
                ], 'required' => ['motivo']],
                'siempre' => true,
            ],
        ];
    }

    /**
     * Herramientas que este agente puede usar, en el formato neutro que
     * entienden los adapters. Se filtran por lista blanca, por plan y por CÓMO
     * COBRA el negocio.
     *
     * Lo tercero no es solo ahorro de tokens. Un bar que cobra contra entrega
     * no tiene pasarela: `PaymentManager::pasarela()` devuelve null y
     * `generar_pago` falla. Ofrecerle esa herramienta al modelo es enseñarle
     * una puerta que da a un muro — y el modelo, tarde o temprano, la cruza:
     * le ofrece al cliente un enlace de pago que no va a existir, la
     * herramienta falla dos veces y la conversación acaba en manos de una
     * persona sin que hubiera ningún problema real.
     */
    public function definiciones(?array $agente = null, ?array $cfg = null): array
    {
        $permitidas = null;
        if ($agente && !empty($agente['herramientas'])) {
            $lista = json_decode($agente['herramientas'], true);
            if (is_array($lista) && $lista) $permitidas = $lista;
        }
        // Sin config no se filtra nada: es lo que hacía antes, y equivocarse
        // por exceso aquí solo cuesta tokens.
        $cobraAntes = $cfg === null || ($cfg['pago_modo'] ?? 'contra_entrega') !== 'contra_entrega';

        $out = [];
        foreach ($this->catalogo() as $nombre => $def) {
            if ($permitidas !== null && !in_array($nombre, $permitidas, true) && empty($def['siempre'])) continue;
            if (!empty($def['capacidad']) && !$this->tieneCapacidad($def['capacidad'])) continue;
            if (!empty($def['feature']) && !\ElkinLinan\WhatsappAiEngine\Engine::funciones()->tiene($def['feature'])) continue;
            if (!$cobraAntes && in_array($nombre, ['generar_pago', 'consultar_pago'], true)) continue;
            $out[] = ['name' => $nombre, 'description' => $def['description'], 'parameters' => $def['parameters']];
        }
        return $out;
    }

    /**
     * Ejecuta una herramienta. NUNCA lanza: devuelve un array que el modelo
     * pueda leer, porque una excepción aquí dejaría al cliente sin respuesta.
     */
    public function ejecutar(string $nombre, array $args, array $ctx): array
    {
        $catalogo = $this->catalogo();
        $inicio = microtime(true);

        if (!isset($catalogo[$nombre])) {
            return $this->fallo($nombre, 'Esa herramienta no existe', $ctx);
        }
        $def = $catalogo[$nombre];

        // Lista blanca del agente (salvo las que no se pueden deshabilitar)
        $agente = $ctx['agente'] ?? null;
        if (empty($def['siempre']) && $agente && !empty($agente['herramientas'])) {
            $lista = json_decode($agente['herramientas'], true);
            if (is_array($lista) && $lista && !in_array($nombre, $lista, true)) {
                return $this->fallo($nombre, 'Esta función no está habilitada en este negocio', $ctx);
            }
        }
        // Capacidad declarada por el negocio. Va ANTES de la bandera de plan
        // porque es más fuerte: el plan dice qué se le vende al negocio, esto
        // dice qué sabe hacer. Un bar al que se le pida una garantía tiene que
        // enterarse aquí, no dentro de un adaptador que no implementa el método.
        if (!empty($def['capacidad']) && !$this->tieneCapacidad($def['capacidad'])) {
            return $this->fallo($nombre, 'Este negocio no ofrece eso', $ctx);
        }
        // Bandera de plan
        if (!empty($def['feature']) && !\ElkinLinan\WhatsappAiEngine\Engine::funciones()->tiene($def['feature'])) {
            return $this->fallo($nombre, 'El plan del negocio no incluye esta función', $ctx);
        }
        // Estado de la conversación: con un humano atendiendo, la IA no actúa
        if (($ctx['conversacion']['estado'] ?? 'IA_ACTIVA') !== 'IA_ACTIVA') {
            return $this->fallo($nombre, 'La conversación la está atendiendo una persona', $ctx);
        }
        // Parámetros
        $err = $this->validarParametros($def['parameters'], $args);
        if ($err !== null) return $this->fallo($nombre, $err, $ctx);

        try {
            $res = $this->despachar($nombre, $args, $ctx);
        } catch (\InvalidArgumentException $e) {
            // Con los args: sin ellos, un producto_id mutilado por el modelo
            // fue indistinguible de un catálogo roto durante toda una noche.
            return $this->fallo($nombre, $e->getMessage(), $ctx, $args);   // mensaje apto para el cliente
        } catch (\Throwable $e) {
            // Detalle real solo a la bitácora; al cliente jamás (§43)
            if ($this->log) $this->log->error('Fallo en la herramienta ' . $nombre . ': ' . $e->getMessage(),
                ['args' => $args], $ctx['conversacion']['id'] ?? null);
            return ['ok' => false, 'error' => 'No se pudo completar la operación'];
        }

        if ($this->log) {
            $this->log->log('tool', 'Herramienta ' . $nombre, [
                'args' => $args, 'ok' => $res['ok'] ?? true,
                'ms' => (int)round((microtime(true) - $inicio) * 1000),
            ], $ctx['conversacion']['id'] ?? null);
        }
        return $res;
    }

    private function fallo(string $nombre, string $motivo, array $ctx, array $args = []): array
    {
        if ($this->log) {
            $detalle = ['motivo' => $motivo];
            if ($args !== []) $detalle['args'] = $args;
            $this->log->log('tool', 'Herramienta rechazada: ' . $nombre, $detalle,
                $ctx['conversacion']['id'] ?? null);
        }
        return ['ok' => false, 'error' => $motivo];
    }

    /**
     * Validación del esquema. Deliberadamente básica: tipos, requeridos y enums.
     * No pretende ser un validador de JSON Schema completo — lo que protege de
     * verdad son las comprobaciones de negocio que hay dentro de cada handler.
     */
    private function validarParametros($esquema, array $args): ?string
    {
        if (!is_array($esquema)) return null;
        foreach (($esquema['required'] ?? []) as $req) {
            if (!array_key_exists($req, $args) || $args[$req] === null || $args[$req] === '') {
                return 'Falta el dato "' . $req . '"';
            }
        }
        $props = $esquema['properties'] ?? [];
        if (!is_array($props)) return null;
        foreach ($args as $k => $v) {
            if (!isset($props[$k]) || !is_array($props[$k])) continue;
            $tipo = $props[$k]['type'] ?? null;
            if ($tipo === 'integer' && !is_numeric($v))            return 'El dato "' . $k . '" debe ser un número';
            if ($tipo === 'array'   && !is_array($v))              return 'El dato "' . $k . '" debe ser una lista';
            if (!empty($props[$k]['enum']) && !in_array($v, $props[$k]['enum'], true)) {
                return 'El dato "' . $k . '" solo admite: ' . implode(', ', $props[$k]['enum']);
            }
        }
        return null;
    }

    // ── Handlers ────────────────────────────────────────────────────────

    private function despachar(string $nombre, array $args, array $ctx): array
    {
        $conv = $ctx['conversacion'];
        $cfg  = $ctx['config'];

        switch ($nombre) {

            case 'consultar_menu':
                $filtros = $args['filtros'] ?? [];
                $productos = $this->adapter->buscarItems(
                    $args['busqueda'] ?? null, is_array($filtros) ? $filtros : []);
                return ['ok' => true, 'productos' => $productos,
                        'nota' => $productos ? null : 'No hay productos disponibles en este momento'];

            case 'consultar_stock':
                // El nombre sale del ADAPTADOR, no de un `SELECT … FROM productos`
                // escrito dentro del motor: esa consulta daba por hecho que el
                // catálogo del negocio se llama `productos` y tiene una columna
                // `nombre`, que es cierto en dos proyectos por casualidad.
                $pid = (string)$args['producto_id'];
                $p = $this->adapter->detalleItem($pid);
                if (!$p) return ['ok' => false, 'error' => 'Ese producto no existe'];
                $d = $this->adapter->disponibilidad($pid);
                return ['ok' => true, 'producto' => $p['nombre'] ?? $pid,
                        'disponible' => $d === null ? 'sin límite' : $d,
                        'se_puede_vender' => $d === null || $d > 0];

            case 'consultar_promociones':
                return ['ok' => true, 'promociones' => $this->adapter->promociones()];

            case 'consultar_almuerzo_del_dia':
                $m = $this->adapter->menuDelDia();
                return ['ok' => true, 'menu_del_dia' => $m,
                        'nota' => $m ? null : 'Hoy no hay menú del día configurado'];

            case 'consultar_opciones':
                $comps = $this->adapter->componentesDe((int)$args['producto_id']);
                if (!$comps) {
                    return ['ok' => true, 'configurable' => false,
                            'nota' => 'Este producto no se arma por partes: se pide tal cual.'];
                }
                return ['ok' => true, 'configurable' => true, 'componentes' => $comps,
                        'como_pedirlo' => 'Pregunta al cliente componente por componente, respetando '
                            . 'min_select y max_select, y manda los ids elegidos en "opciones" al crear el pedido.'];

            case 'enviar_enlace_para_armar':
                $cant = max(1, (int)($args['cantidad'] ?? 1));
                $url  = $this->adapter->enlaceParaArmar((int)$args['producto_id'], $cant, $conv);
                if (!$url) {
                    // Sin enlace no se puede prometer una pantalla: que lo pregunte
                    // por chat, que para eso está la otra herramienta.
                    return ['ok' => false,
                            'error' => 'No pude generar el enlace. Pregúntale las opciones por aquí con consultar_opciones.'];
                }
                return ['ok' => true, 'enlace' => $url,
                        'que_decirle' => 'Pásale el enlace y dile que ahí elige todo y confirma; '
                            . 'cuando termine le llega el resumen por este chat. No le pidas las opciones por aquí.'];

            case 'calcular_total':
                $envio = (($args['modo_entrega'] ?? '') === 'domicilio') ? (float)($cfg['costo_domicilio'] ?? 0) : 0.0;
                $r = $this->adapter->calcularTotal($args['items'] ?? [], $envio);
                $minimo = (float)($cfg['pedido_minimo'] ?? 0);
                if ($minimo > 0 && $r['subtotal'] < $minimo) {
                    $r['aviso'] = 'El pedido mínimo es ' . $this->money($minimo) . ' sin contar el domicilio';
                    $r['cumple_minimo'] = false;
                } else {
                    $r['cumple_minimo'] = true;
                }
                return array_merge(['ok' => true], $r);

            case 'consultar_garantia':
                return ['ok' => true,
                        'garantia' => $this->adapter->consultarGarantia((string)$args['identificador'])];

            case 'cotizar_servicio_tecnico':
                return ['ok' => true, 'es_estimado' => true,
                        'cotizacion' => $this->adapter->cotizarServicio(
                            (string)$args['tipo_equipo'], (string)$args['falla'], $args)];

            case 'crear_orden_servicio':
                return ['ok' => true,
                        'orden' => $this->adapter->crearOrdenServicio($conv, $args)];

            case 'consultar_orden_servicio':
                return ['ok' => true,
                        'orden' => $this->adapter->estadoOrdenServicio((string)$args['numero_orden'])];

            case 'consultar_saldo_credito':
                // El cliente es EL DE LA CONVERSACIÓN, jamás uno que el modelo
                // proponga: la herramienta no acepta un id por parámetro
                // precisamente para que no exista la forma de pedir el saldo
                // de otra persona.
                $cid = (int)($conv['cliente_id'] ?? 0);
                if (!$cid) {
                    return ['ok' => false,
                            'error' => 'Todavía no puedo identificar a este cliente en el sistema; para consultar un saldo hace falta que sea cliente registrado'];
                }
                return ['ok' => true, 'credito' => $this->adapter->saldoCliente($cid)];

            case 'registrar_datos_entrega':
                $modo = $args['modo_entrega'];
                if ($modo === 'domicilio') {
                    if (empty($args['direccion'])) return ['ok' => false, 'error' => 'Para domicilio hace falta la dirección'];
                    $modos = explode(',', (string)($cfg['entrega_modos'] ?? 'domicilio,recoger'));
                    if (!in_array('domicilio', array_map('trim', $modos), true)) {
                        return ['ok' => false, 'error' => 'Este negocio no hace domicilios; solo se puede recoger en el local'];
                    }
                }
                $ctxConv = json_decode($conv['contexto'] ?? '{}', true) ?: [];
                $ctxConv['entrega'] = [
                    'modo_entrega' => $modo,
                    'nombre'       => $args['nombre']      ?? ($conv['nombre_contacto'] ?? ''),
                    'direccion'    => $args['direccion']   ?? '',
                    'barrio'       => $args['barrio']      ?? '',
                    'ciudad'       => $args['ciudad']      ?? '',
                    'referencias'  => $args['referencias'] ?? '',
                ];
                $this->db->query("UPDATE wa_conversaciones SET contexto = ? WHERE id = ?",
                    [json_encode($ctxConv, JSON_UNESCAPED_UNICODE), (int)$conv['id']]);
                // El nombre que da el cliente MANDA sobre el del perfil de
                // WhatsApp: el perfil trae apodos ("Mi Amor 💕", "Ferretería"),
                // y este es el nombre con el que quiere que lo llamen. Se guarda
                // en la conversación para que siga ahí mañana.
                $nom = trim((string)($args['nombre'] ?? ''));
                if ($nom !== '' && $nom !== (string)($conv['nombre_contacto'] ?? '')) {
                    $this->db->query("UPDATE wa_conversaciones SET nombre_contacto = ? WHERE id = ?",
                        [mb_substr($nom, 0, 100), (int)$conv['id']]);
                }
                return ['ok' => true, 'guardado' => $ctxConv['entrega'],
                        'costo_domicilio' => $modo === 'domicilio' ? (float)($cfg['costo_domicilio'] ?? 0) : 0];

            case 'crear_pedido':
                return $this->crearPedido($args, $ctx);

            case 'consultar_pedido':
                if (!empty($args['pedido_id'])) {
                    if (!$this->pedidoEsDeLaConversacion((int)$args['pedido_id'], (int)$conv['id'])) {
                        return ['ok' => false, 'error' => 'Ese pedido no es de esta conversación'];
                    }
                    $e = $this->adapter->estadoTransaccion((string)$args['pedido_id']);
                    return $e ? ['ok' => true, 'pedido' => $e] : ['ok' => false, 'error' => 'Pedido no encontrado'];
                }
                return ['ok' => true, 'pedidos' => $this->adapter->transaccionesDe((int)$conv['id'])];

            case 'cancelar_pedido':
                $pid = (int)$args['pedido_id'];
                if (!$this->pedidoEsDeLaConversacion($pid, (int)$conv['id'])) {
                    return ['ok' => false, 'error' => 'Ese pedido no es de esta conversación'];
                }
                // Una transacción YA CONFIRMADA no se cancela por chat.
                //
                // No basta con mirar el estado que devuelve el negocio:
                // confirmarTransaccion() pone el trabajo en marcha (la comanda
                // entra a cocina, el equipo pasa a despacho) pero el estado
                // propio puede seguir igual hasta que alguien lo tome. Mirando
                // solo ese estado, algo pagado y ya en la plancha se dejaba
                // cancelar, y encima devolvía el stock de lo que se estaba
                // cocinando. `enviado_cocina` es la marca del motor y por eso
                // es la que manda aquí.
                $wpEstado = $this->db->fetch(
                    "SELECT enviado_cocina FROM wa_pedidos WHERE pedido_id = ?", [$pid]);
                if ($wpEstado && (int)$wpEstado['enviado_cocina'] === 1) {
                    return ['ok' => false,
                            'error' => 'El pedido ya está en marcha; para cancelarlo hay que hablar con el negocio'];
                }
                $r = $this->adapter->cancelarTransaccion((string)$pid);
                if (!empty($r['ok'])) {
                    $this->db->query("UPDATE wa_pedidos SET estado_pago = 'PAYMENT_EXPIRED' WHERE pedido_id = ?", [$pid]);
                }
                return $r;

            case 'consultar_estado_cocina':
                $pid = (int)$args['pedido_id'];
                if (!$this->pedidoEsDeLaConversacion($pid, (int)$conv['id'])) {
                    return ['ok' => false, 'error' => 'Ese pedido no es de esta conversación'];
                }
                $e = $this->adapter->estadoTransaccion((string)$pid);
                if (!$e) return ['ok' => false, 'error' => 'Pedido no encontrado'];
                // Lo que decide es si el pedido YA SALIÓ a cocina, no el modo de
                // cobro del negocio: un pedido contra entrega dentro de un negocio
                // que además acepta enlace está en la plancha con el pago aún
                // pendiente, y decirle al cliente que "espera confirmación" sería
                // falso.
                $enCocina = $this->db->fetch("SELECT enviado_cocina FROM wa_pedidos WHERE pedido_id = ?", [$pid]);
                if ($e['estado_pago'] !== 'PAYMENT_VERIFIED' && empty($enCocina['enviado_cocina'])) {
                    return ['ok' => true, 'estado' => 'El pedido está esperando la confirmación del pago'];
                }
                return ['ok' => true, 'estado' => $e['estado']];

            case 'generar_pago':
            case 'consultar_pago':
                if (!$this->pagos) return ['ok' => false, 'error' => 'El cobro en línea no está configurado'];
                $pid = (int)$args['pedido_id'];
                if (!$this->pedidoEsDeLaConversacion($pid, (int)$conv['id'])) {
                    return ['ok' => false, 'error' => 'Ese pedido no es de esta conversación'];
                }
                return $nombre === 'generar_pago'
                    ? $this->pagos->generar($pid, $conv, (string)($args['metodo'] ?? ''))
                    : $this->pagos->consultar($pid);

            case 'consultar_disponibilidad':
                if (!$this->adapter instanceof SoportaCitas) {
                    return ['ok' => false, 'error' => 'Este negocio no agenda citas'];
                }
                $franjas = $this->adapter->horariosDisponibles((string)$args['desde'], (string)$args['hasta']);
                return ['ok' => true, 'horarios' => $franjas,
                        'nota' => $franjas
                            ? 'Propón 2 o 3 de estas opciones, no la lista entera.'
                            : 'No hay horarios libres en ese rango; prueba con un rango distinto.'];

            case 'registrar_datos_cita':
                if (!$this->adapter instanceof SoportaCitas) {
                    return ['ok' => false, 'error' => 'Este negocio no agenda citas'];
                }
                $inicio = trim((string)$args['inicio']);
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $inicio)) {
                    return ['ok' => false, 'error' => "La hora debe venir como 'YYYY-MM-DD HH:MM', copiada de consultar_disponibilidad"];
                }
                if (strtotime($inicio) === false || strtotime($inicio) <= time()) {
                    return ['ok' => false, 'error' => 'Esa hora ya pasó; consulta la disponibilidad de nuevo'];
                }
                $correo = trim((string)($args['correo'] ?? ''));
                if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    return ['ok' => false, 'error' => 'Ese correo no parece válido; pídelo de nuevo'];
                }
                // La foto pudo envejecer entre que el cliente vio los horarios
                // y eligió: se revalida aquí, y crear_pedido revalidará OTRA
                // vez de forma atómica al reservar.
                if (!$this->adapter->franjaDisponible($inicio)) {
                    return ['ok' => false, 'error' => 'Esa franja ya no está libre; consulta la disponibilidad de nuevo y ofrécele otra'];
                }
                // El teléfono de contacto que dicta el cliente. Importa desde
                // que WhatsApp entrega remitentes @lid (identificador de
                // privacidad): el JID de la conversación puede no ser un
                // número al que el negocio pueda llamar.
                $telCita = preg_replace('/[\s+\-().]/', '', (string)($args['telefono'] ?? ''));
                // Un número colombiano dictado sin indicativo (10 dígitos) se
                // completa con 57: es el país del negocio y pedirle al cliente
                // que lo repita "con indicativo" es fricción sin motivo.
                if (preg_match('/^\d{10}$/', (string)$telCita)) {
                    $telCita = '57' . $telCita;
                }
                if ($telCita !== '' && !preg_match('/^\d{10,15}$/', $telCita)) {
                    return ['ok' => false, 'error' => 'Ese teléfono no parece válido: pídelo con indicativo de país y solo dígitos, ej. 573001234567'];
                }
                $ctxConv = json_decode($conv['contexto'] ?? '{}', true) ?: [];
                $ctxConv['cita'] = [
                    'inicio' => $inicio,
                    'nombre' => trim((string)($args['nombre'] ?? '')) ?: ($conv['nombre_contacto'] ?? ''),
                    'correo' => $correo,
                    'telefono' => $telCita,
                    'motivo' => trim((string)($args['motivo'] ?? '')),
                ];
                $this->db->query("UPDATE wa_conversaciones SET contexto = ? WHERE id = ?",
                    [json_encode($ctxConv, JSON_UNESCAPED_UNICODE), (int)$conv['id']]);
                // El nombre que da el cliente manda sobre el apodo del perfil
                // de WhatsApp, igual que en registrar_datos_entrega.
                $nom = trim((string)($args['nombre'] ?? ''));
                if ($nom !== '' && $nom !== (string)($conv['nombre_contacto'] ?? '')) {
                    $this->db->query("UPDATE wa_conversaciones SET nombre_contacto = ? WHERE id = ?",
                        [mb_substr($nom, 0, 100), (int)$conv['id']]);
                }
                return ['ok' => true, 'guardado' => $ctxConv['cita'],
                        'siguiente_paso' => 'Confirma con el cliente el resumen (día, hora y servicio) y crea la cita con crear_pedido usando el servicio del catálogo.'];

            case 'consultar_cita':
                if (!$this->adapter instanceof SoportaCitas) {
                    return ['ok' => false, 'error' => 'Este negocio no agenda citas'];
                }
                return ['ok' => true, 'citas' => $this->adapter->citasDe((int)$conv['id'])];

            case 'transferir_a_humano':
                $h = new HumanHandoff($this->db, $this->log);
                $h->transferir((int)$conv['id'], (string)$args['motivo'], $ctx['canal'] ?? null, $cfg);
                return ['ok' => true, 'transferido' => true,
                        'mensaje_para_el_cliente' => 'Le paso tu caso a una persona del equipo; te responde en un momento.'];
        }
        return ['ok' => false, 'error' => 'Herramienta no implementada'];
    }

    /**
     * Crea el pedido. Aquí viven las reglas comerciales que el modelo no puede
     * saltarse por mucho que se lo pidan: horario, pedido mínimo, datos de
     * entrega e idempotencia.
     */
    private function crearPedido(array $args, array $ctx): array
    {
        $conv = $ctx['conversacion'];
        $cfg  = $ctx['config'];

        if (!WaConfig::abiertoAhora($cfg)) {
            return ['ok' => false, 'error' => 'El negocio está cerrado en este momento y no se pueden tomar pedidos'];
        }

        $ctxConv = json_decode($conv['contexto'] ?? '{}', true) ?: [];
        $entrega = $ctxConv['entrega'] ?? null;
        // Los datos de entrega solo se exigen al negocio que ENTREGA. Exigirlos
        // siempre dejaba sin poder vender a cualquier negocio que no declare la
        // capacidad: la herramienta que los registra ni siquiera se le ofrece
        // al modelo, así que el requisito era imposible de cumplir.
        if (!$entrega && $this->tieneCapacidad('entrega')) {
            return ['ok' => false, 'error' => 'Faltan los datos de entrega: usa registrar_datos_entrega primero'];
        }
        $entrega = $entrega ?: ['modo_entrega' => 'recoger', 'nombre' => '', 'direccion' => '',
                                'barrio' => '', 'ciudad' => '', 'referencias' => ''];

        // Mismo reparto que con la entrega: al negocio que agenda citas no se
        // le puede crear la transacción sin saber CUÁNDO. El adaptador validará
        // la franja otra vez, atómicamente, dentro de crearTransaccion().
        $cita = $ctxConv['cita'] ?? null;
        if (!$cita && $this->tieneCapacidad('citas')) {
            return ['ok' => false, 'error' => 'Falta elegir la hora de la cita: usa consultar_disponibilidad y registrar_datos_cita primero'];
        }
        if ($cita) {
            // El nombre de la cita sirve también como nombre de la transacción
            // cuando el negocio no maneja entregas.
            if (empty($entrega['nombre']) && !empty($cita['nombre'])) {
                $entrega['nombre'] = $cita['nombre'];
            }
        }
        $items = $args['items'] ?? [];
        if (!$items) return ['ok' => false, 'error' => 'El pedido está vacío'];

        $envio  = ($entrega['modo_entrega'] === 'domicilio') ? (float)($cfg['costo_domicilio'] ?? 0) : 0.0;
        $calc   = $this->adapter->calcularTotal($items, $envio);
        $minimo = (float)($cfg['pedido_minimo'] ?? 0);
        if ($minimo > 0 && $calc['subtotal'] < $minimo) {
            return ['ok' => false, 'error' => 'El pedido mínimo es ' . $this->money($minimo)];
        }

        // Idempotencia (§39): el mismo carrito, en la misma conversación, dentro
        // de la misma ventana de 10 minutos, es UN pedido. Sin esto, un webhook
        // reintentado o un cliente impaciente generan pedidos duplicados —y
        // descuentan stock dos veces.
        $normal = array_map(function ($i) {
            return (string)$i['producto_id'] . 'x' . (int)$i['cantidad'];
        }, $items);
        sort($normal);
        // La franja entra en la clave: el mismo carrito a las 9:00 y a las
        // 10:00 son DOS citas distintas, no un duplicado.
        $clave = hash('sha256', $conv['id'] . '|' . implode(',', $normal) . '|'
            . (($cita['inicio'] ?? '')) . '|' . floor(time() / 600));

        $ya = $this->db->fetch("SELECT pedido_id FROM wa_pedidos WHERE idempotency_key = ?", [$clave]);
        if ($ya) {
            return ['ok' => true, 'pedido_id' => (int)$ya['pedido_id'], 'duplicado' => true,
                    'nota' => 'Este pedido ya estaba creado; no se duplicó'];
        }

        // El negocio crea SU transacción con SUS reglas y reserva lo que haga
        // falta —descontar stock en un bar, marcar un IMEI en una tienda—
        // dentro de su propia transacción de base de datos.
        $datos = ['observaciones' => (string)($args['observaciones'] ?? '')] + $entrega;
        if ($cita) $datos['cita'] = $cita;
        $res   = $this->adapter->crearTransaccion($conv, $items, $datos);
        $trxId = (string)$res['id'];

        $this->db->insert(
            "INSERT INTO wa_pedidos (conversacion_id, pedido_id, modo_entrega, nombre, telefono, direccion,
                                     barrio, ciudad, referencias, costo_domicilio, estado_pago, idempotency_key)
             VALUES (?,?,?,?,?,?,?,?,?,?,'PAYMENT_PENDING',?)",
            [(int)$conv['id'], $trxId, $entrega['modo_entrega'],
             $entrega['nombre'] ?: null, $conv['telefono'], $entrega['direccion'] ?: null,
             $entrega['barrio'] ?: null, $entrega['ciudad'] ?: null, $entrega['referencias'] ?: null,
             $envio, $clave]);

        // Contra entrega no hay nada que cobrar antes: el negocio se pone a
        // trabajar ya (la comanda entra a cocina, el equipo pasa a despacho).
        //
        // Cuando el negocio acepta varias formas de pago no se puede adelantar
        // nada aquí: hasta que el cliente no elija, no se sabe si esto arranca
        // ahora o cuando el pago quede verificado. Eso lo cierra generar_pago
        // con el método elegido.
        $modo = $cfg['pago_modo'] ?? 'contra_entrega';
        if ($modo === 'contra_entrega') {
            $this->adapter->confirmarTransaccion($trxId);
            $this->db->query("UPDATE wa_pedidos SET enviado_cocina = 1 WHERE pedido_id = ?", [$trxId]);
        }

        $acepta = PaymentManager::metodosDe($modo);
        if ($modo === 'contra_entrega') {
            $siguiente = 'El pedido ya está en marcha; se paga al recibirlo.';
        } elseif (count($acepta) > 1) {
            $siguiente = 'Pregúntale al cliente cómo quiere pagar y llama a generar_pago con esa forma.';
        } else {
            $siguiente = 'Cierra el cobro con generar_pago y pásale al cliente lo que devuelva.';
        }

        return ['ok' => true, 'pedido_id' => $trxId,
                'subtotal' => $calc['subtotal'], 'domicilio' => $envio, 'total' => $calc['total'],
                'modo_pago' => $modo,
                'formas_de_pago_aceptadas' => $acepta,
                'siguiente_paso' => $siguiente];
    }

    /** Un cliente solo puede ver y tocar SUS pedidos. */
    private function pedidoEsDeLaConversacion(int $pedidoId, int $conversacionId): bool
    {
        try {
            $r = $this->db->fetch(
                "SELECT id FROM wa_pedidos WHERE pedido_id = ? AND conversacion_id = ?", [$pedidoId, $conversacionId]);
            return (bool)$r;
        } catch (\Throwable $e) { return false; }
    }

    private function money($v): string
    {
        return \ElkinLinan\WhatsappAiEngine\Engine::formato()->dinero((float)$v);
    }
}
