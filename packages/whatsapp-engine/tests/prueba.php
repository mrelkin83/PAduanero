<?php
/**
 * ============================================================================
 * La prueba que decide si este paquete es reutilizable
 * ============================================================================
 * Corre el motor contra un negocio de mentira —una TIENDA, no un bar— sin
 * ControlBarMax delante: sin su base de datos, sin sus helpers, sin sus
 * constantes. Solo el paquete y siete puertos de juguete.
 *
 *     php packages/whatsapp-engine/tests/prueba.php
 *
 * Si esto pasa, MayTech POS puede escribir su adaptador con la tranquilidad de
 * que no va a tener que tocar el motor. Si falla, el contrato está mal y hay
 * que arreglarlo AQUÍ, no en cada proyecto.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/adaptador_falso.php';

use ElkinLinan\WhatsappAiEngine\Core\Scope;
use ElkinLinan\WhatsappAiEngine\Engine;
use ElkinLinan\WhatsappAiEngine\Ports\DomainAdapter;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaGarantias;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaMenuDelDia;
use ElkinLinan\WhatsappAiEngine\Tests\ArchivoFalso;
use ElkinLinan\WhatsappAiEngine\Tests\ConfigFalso;
use ElkinLinan\WhatsappAiEngine\Tests\DbFalso;
use ElkinLinan\WhatsappAiEngine\Tests\FormatoFalso;
use ElkinLinan\WhatsappAiEngine\Tests\NegocioCompartido;
use ElkinLinan\WhatsappAiEngine\Tests\SecretoFalso;
use ElkinLinan\WhatsappAiEngine\Tests\TiendaFalsa;

$ok = 0; $fallos = 0;
function titulo(string $t): void { echo "\n\e[1m── $t " . str_repeat('─', max(1, 54 - mb_strlen($t))) . "\e[0m\n"; }
function chequeo(string $q, bool $paso, string $detalle = ''): void {
    global $ok, $fallos;
    if ($paso) { $ok++; echo "  \e[32m✓\e[0m $q\n"; }
    else { $fallos++; echo "  \e[31m✗ $q\e[0m" . ($detalle !== '' ? "  — $detalle" : '') . "\n"; }
}

/* ── Arranque ───────────────────────────────────────────────────────────── */
titulo('El motor arranca sin su proyecto de origen');

$db     = new DbFalso();
$tienda = new TiendaFalsa();
Engine::reiniciar();
Engine::arrancar([
    'db'      => $db,
    'secreto' => new SecretoFalso(),
    'archivo' => new ArchivoFalso(),
    'formato' => new FormatoFalso(),
    'config'  => new ConfigFalso(),
    'dominio' => $tienda,
]);

chequeo('Arranca con los puertos mínimos', Engine::db() === $db);
chequeo('El dominio es el que se le pasó', Engine::dominio() === $tienda);

// Los puertos que NO se pasaron caen a un valor sensato: eso es lo que hace
// barato conectar el motor a un proyecto nuevo.
chequeo('Sin planes de suscripción, todo está permitido',
        Engine::funciones()->tiene('lo_que_sea') === true);
chequeo('Sin multi-negocio, no hay id de negocio',
        Engine::negocio()->id() === null && Engine::negocio()->esMultiNegocio() === false);

// Y los que no tienen valor razonable fallan RUIDOSAMENTE, en vez de inventar.
Engine::reiniciar();
$grito = false;
try { Engine::db(); } catch (\RuntimeException $e) { $grito = strpos($e->getMessage(), "'db'") !== false; }
chequeo('Sin base de datos, el motor grita en vez de inventar', $grito);

Engine::arrancar([
    'db' => $db, 'secreto' => new SecretoFalso(), 'archivo' => new ArchivoFalso(),
    'formato' => new FormatoFalso(), 'config' => new ConfigFalso(), 'dominio' => $tienda,
]);

/* ── El formato NO está quemado en el motor ─────────────────────────────── */
titulo('Nada del proyecto de origen quedó dentro');

chequeo('El dinero se escribe como diga el proyecto, no en pesos',
        Engine::formato()->dinero(1200) === 'US$1,200.00',
        Engine::formato()->dinero(1200));

$secreto = 'clave-secreta-123';
chequeo('Los secretos viajan por el puerto del proyecto',
        Engine::secretos()->descifrar(Engine::secretos()->cifrar($secreto)) === $secreto);

/* ── El contrato de dominio ─────────────────────────────────────────────── */
titulo('Una TIENDA cumple el contrato igual que un bar');

chequeo('La tienda implementa el contrato', $tienda instanceof DomainAdapter);
chequeo('…y declara solo lo que tiene', $tienda->capacidades() === ['garantias', 'configurables', 'armado_en_pantalla'],
        implode(', ', $tienda->capacidades()));
chequeo('No finge tener menú del día', !($tienda instanceof SoportaMenuDelDia));
chequeo('Sí tiene garantías', $tienda instanceof SoportaGarantias);

$items = $tienda->buscarItems('Teléfono');
chequeo('Busca en su catálogo', count($items) === 1 && $items[0]['id'] === 'p1');
chequeo('Informa disponibilidad', $tienda->disponibilidad('p1') === 2);
chequeo('Y distingue «no lo controlo» de «se agotó»', $tienda->disponibilidad('inexistente') === null);

/* ── Una venta completa ─────────────────────────────────────────────────── */
titulo('Vender sin cocina');

$venta = $tienda->crearTransaccion(['telefono' => '573001112233'], [['producto_id' => 'p1', 'cantidad' => 1]]);
chequeo('Crea la transacción', !empty($venta['id']) && $venta['total'] === 1200.0);
chequeo('…y reserva de verdad (queda una menos)', $tienda->disponibilidad('p1') === 1);
chequeo('Antes de confirmar, está esperando pago',
        ($tienda->estadoTransaccion($venta['id'])['estado'] ?? '') === 'esperando pago');
chequeo('Confirmar la manda a DESPACHO, no a cocina', $tienda->confirmarTransaccion($venta['id']));
chequeo('…y el estado lo refleja',
        ($tienda->estadoTransaccion($venta['id'])['estado'] ?? '') === 'en despacho');

$mensaje = '';
try {
    $tienda->crearTransaccion([], [['producto_id' => 'p2', 'cantidad' => 5]]);
} catch (\InvalidArgumentException $e) { $mensaje = $e->getMessage(); }
chequeo('Sin existencias, el error se le puede enseñar al cliente',
        strpos($mensaje, 'Solo queda') === 0, $mensaje);

/* ── La capacidad propia de este dominio ────────────────────────────────── */
titulo('Lo que el bar no tiene');

chequeo('Consulta una garantía vigente', $tienda->consultarGarantia('355000000000001')['vigente'] === true);
chequeo('Y una que no existe', $tienda->consultarGarantia('000')['vigente'] === false);

/* ── Las clases del motor cargan y funcionan ────────────────────────────── */
titulo('Las piezas del motor, en un proyecto que no es el suyo');

$clases = [
    'Core\\WaConfig', 'Core\\ToolEngine', 'Core\\PromptComposer', 'Core\\RateLimiter',
    'Core\\ConversationManager', 'Core\\HumanHandoff', 'Core\\AgentManager', 'Core\\AiOrchestrator',
    'Providers\\LlmProviderManager', 'Providers\\OpenAiAdapter', 'Providers\\AnthropicAdapter',
    'Channel\\EvolutionClient', 'Media\\MediaProcessor', 'Media\\SttManager', 'Media\\TtsManager',
    'Media\\VisionManager', 'Payments\\PaymentManager', 'Payments\\WompiAdapter',
];
$faltan = [];
foreach ($clases as $c) {
    if (!class_exists('ElkinLinan\\WhatsappAiEngine\\' . $c)) $faltan[] = $c;
}
chequeo('Las ' . count($clases) . ' clases principales cargan solas', !$faltan, implode(', ', $faltan));

/* ── El motor operando contra un adaptador que SOLO cumple el contrato ──── */
//
// Esta es la parte que faltaba y por la que el paquete se creyó reutilizable
// sin serlo: la prueba construía un ToolEngine y no lo ejecutaba nunca. Por
// dentro llamaba a menu(), disponibleWhatsapp(), crearPedido(), enviarACocina()
// —diez métodos del bar que el contrato no promete— y consultaba `productos`
// con SQL escrito dentro del motor. Contra este adaptador, que implementa el
// contrato y nada más, todo eso revienta. Aquí se ejecuta de verdad.
titulo('Las herramientas, ejecutadas contra el contrato pelado');

$tools = new \ElkinLinan\WhatsappAiEngine\Core\ToolEngine($db, $tienda);
$conv = ['id' => 1, 'estado' => 'IA_ACTIVA', 'telefono' => '573001112233',
         'nombre_contacto' => 'Ana', 'contexto' => null, 'cliente_id' => null];
$cfgFalsa = ['pago_modo' => 'contra_entrega', 'entrega_modos' => 'recoger',
             'costo_domicilio' => 0, 'pedido_minimo' => 0, 'horario_atencion' => null];
$ctx = ['conversacion' => $conv, 'config' => $cfgFalsa, 'agente' => null, 'canal' => null];

$r = $tools->ejecutar('consultar_menu', [], $ctx);
chequeo('consultar_menu sale del catálogo del negocio',
        !empty($r['ok']) && count($r['productos']) === 3, json_encode($r));

$r = $tools->ejecutar('consultar_menu', ['busqueda' => 'Cargador'], $ctx);
chequeo('…y admite búsqueda', !empty($r['ok']) && count($r['productos']) === 1);

$r = $tools->ejecutar('consultar_stock', ['producto_id' => 'p3'], $ctx);
chequeo('consultar_stock pregunta el nombre AL NEGOCIO, no a una tabla `productos`',
        !empty($r['ok']) && $r['producto'] === 'Cargador 65 W' && $r['disponible'] === 50,
        json_encode($r));

$r = $tools->ejecutar('calcular_total', ['items' => [['producto_id' => 'p3', 'cantidad' => 2]]], $ctx);
chequeo('calcular_total lo calcula el servidor', !empty($r['ok']) && $r['total'] === 90.0, json_encode($r));

$r = $tools->ejecutar('consultar_garantia', ['identificador' => '355000000000001'], $ctx);
chequeo('La garantía es una herramienta REAL, no solo una interfaz',
        !empty($r['ok']) && $r['garantia']['vigente'] === true, json_encode($r));

$antes = $tienda->disponibilidad('p3');
$r = $tools->ejecutar('crear_pedido', ['items' => [['producto_id' => 'p3', 'cantidad' => 1]]], $ctx);
chequeo('crear_pedido crea la transacción del negocio', !empty($r['ok']) && !empty($r['pedido_id']), json_encode($r));
chequeo('…reservando de verdad', $tienda->disponibilidad('p3') === $antes - 1);
chequeo('…y contra entrega la pone en marcha ya', in_array($r['pedido_id'] ?? '', $tienda->confirmadas, true));

/* ── Solo se ofrece lo que el negocio declaró ───────────────────────────── */
titulo('El catálogo de herramientas se recorta por capacidades');

$nombres = array_column($tools->definiciones(null, $cfgFalsa), 'name');
chequeo('La tienda ve su herramienta de garantías', in_array('consultar_garantia', $nombres, true));
chequeo('…y NO ve el almuerzo del día de un bar', !in_array('consultar_almuerzo_del_dia', $nombres, true),
        implode(', ', $nombres));
chequeo('…ni el servicio técnico, que no declaró', !in_array('crear_orden_servicio', $nombres, true));
chequeo('Transferir a una persona no se puede quitar', in_array('transferir_a_humano', $nombres, true));

$r = $tools->ejecutar('consultar_almuerzo_del_dia', [], $ctx);
chequeo('Y si el modelo la pide igual, se le dice que no', empty($r['ok']));


/* ── Productos que el cliente arma por partes ───────────────────────────── */
titulo('Un producto configurable, sin saber nada del negocio');

$r = $tools->ejecutar('consultar_opciones', ['producto_id' => 2], $ctx);
chequeo('El motor pregunta al negocio cómo se arma', !empty($r['configurable']));
chequeo('…y recibe los componentes con sus reglas',
        count($r['componentes'] ?? []) === 2
        && $r['componentes'][0]['required'] === 1
        && count($r['componentes'][0]['opciones']) === 2,
        json_encode($r['componentes'] ?? []));

$r = $tools->ejecutar('consultar_opciones', ['producto_id' => 3], $ctx);
chequeo('Lo que no se configura lo dice sin inventar', empty($r['configurable']) && !empty($r['ok']));

$nombres = array_column($tools->definiciones(null, $cfgFalsa), 'name');
chequeo('La herramienta solo existe si el negocio la declara',
        in_array('consultar_opciones', $nombres, true));


/* ── Armar en pantalla en vez de en cinco preguntas ─────────────────────── */
titulo('El enlace para armar el producto lo pone el NEGOCIO');

$r = $tools->ejecutar('enviar_enlace_para_armar', ['producto_id' => 2, 'cantidad' => 2], $ctx);
chequeo('El motor pide el enlace y lo devuelve', !empty($r['ok']) && !empty($r['enlace']));
chequeo('…pasándole a quién y cuántos, no solo el producto',
        count($tienda->enlacesPedidos) === 1
        && $tienda->enlacesPedidos[0]['cantidad'] === 2
        && $tienda->enlacesPedidos[0]['telefono'] !== '',
        json_encode($tienda->enlacesPedidos));

$r = $tools->ejecutar('enviar_enlace_para_armar', ['producto_id' => 3], $ctx);
chequeo('Si el negocio no da enlace, no se le promete una pantalla al cliente',
        empty($r['ok']) && stripos($r['error'] ?? '', 'consultar_opciones') !== false,
        $r['error'] ?? '');

/* ── La transferencia avisa DE VERDAD ───────────────────────────────────── */
titulo('«Le paso tu caso a una persona» tiene que avisar a alguien');

$r = $tools->ejecutar('transferir_a_humano', ['motivo' => 'El cliente reclama'], $ctx);
chequeo('La herramienta transfiere', !empty($r['transferido']));
chequeo('…y el NEGOCIO se entera por su propio sistema',
        count($tienda->avisos) === 1 && $tienda->avisos[0]['motivo'] === 'El cliente reclama',
        json_encode($tienda->avisos));

/* ── Varios negocios en la misma base ───────────────────────────────────── */
titulo('El otro modo de ser multi-negocio: una base, una columna');

chequeo('Con una base por negocio no se filtra nada', Scope::y() === '' && Scope::mas([1]) === [1]);

Engine::arrancar(['negocio' => new NegocioCompartido(7)]);
chequeo('Con una base compartida, las consultas se acotan al negocio',
        Scope::y() === ' AND empresa_id = ?' && Scope::mas(['x']) === ['x', 7], Scope::y());

$db2 = new DbFalso();
(new \ElkinLinan\WhatsappAiEngine\Core\ConversationManager($db2))->existeViva('573001112233');
$ultima = end($db2->consultas);
chequeo('La conversación se busca dentro del negocio, no en toda la tabla',
        strpos($ultima, 'empresa_id = ?') !== false, $ultima);

// Se deja como estaba para no contaminar lo que venga después.
Engine::arrancar(['negocio' => new \ElkinLinan\WhatsappAiEngine\Defecto\NegocioUnico()]);

// Un proveedor de IA se construye sin tocar nada del proyecto.
$llm = \ElkinLinan\WhatsappAiEngine\Providers\LlmProviderManager::crear('openai', 'clave-de-mentira', 'gpt-4o-mini');
chequeo('Se puede construir un proveedor de IA', $llm !== null);

/* ── Resultado ──────────────────────────────────────────────────────────── */
echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULTADO: \e[32m$ok correctas\e[0m, " . ($fallos ? "\e[31m$fallos fallidas\e[0m" : '0 fallidas') . "\n";
if (!$fallos) {
    echo "El paquete corre sin ControlBarMax: está listo para otro proyecto.\n";
}
echo str_repeat('=', 60) . "\n";
exit($fallos > 0 ? 1 : 0);
