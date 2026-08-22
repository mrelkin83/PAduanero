<?php
/**
 * ============================================================================
 * PaymentManager — la máquina de estados del §24 y la regla del §21
 * ============================================================================
 * LA REGLA QUE NO SE NEGOCIA:
 *
 *   Una captura de pantalla NUNCA aprueba un pago.
 *   La visión LEE y EXTRAE. Quien decide es la consulta a la pasarela.
 *   Sin fuente transaccional verificable → PAYMENT_REVIEW_REQUIRED → una persona.
 *
 * Y la consecuencia práctica: el pedido solo sale a cocina con PAYMENT_VERIFIED
 * (o en modo contra entrega). Aquí es donde el dinero y la comida se tocan.
 */

namespace ElkinLinan\WhatsappAiEngine\Payments;

use ElkinLinan\WhatsappAiEngine\Core\WaConfig;


class PaymentManager
{
    const ESTADOS = ['PAYMENT_PENDING', 'PAYMENT_INITIATED', 'PAYMENT_SCREENSHOT_RECEIVED',
                     'PAYMENT_VALIDATING', 'PAYMENT_VERIFIED', 'PAYMENT_REJECTED',
                     'PAYMENT_REVIEW_REQUIRED', 'PAYMENT_EXPIRED', 'PAYMENT_REFUNDED'];

    private $db;
    private $log;
    private $adapterDominio;

    public function __construct($db, $log, $adapterDominio)
    {
        $this->db = $db;
        $this->log = $log;
        $this->adapterDominio = $adapterDominio;
    }

    /** ¿Este negocio tiene pasarela disponible, sea cual sea el modo? */
    private function pasarela(): ?PaymentAdapterInterface
    {
        $cfg = WaConfig::cargar($this->db);
        if (!$cfg) return null;
        $modo = $cfg['pago_modo'] ?? 'contra_entrega';
        if ($modo === 'wompi' || $modo === 'mixto' || $modo === 'todos') return WompiAdapter::desdeConfig($cfg);
        return null;
    }

    /**
     * Métodos que el cliente puede elegir según cómo cobre el negocio.
     * Con 'todos' están los tres; con los demás modos, el que corresponda.
     */
    public static function metodosDe(string $modo): array
    {
        switch ($modo) {
            case 'wompi':  return ['wompi'];
            case 'manual': return ['transferencia'];
            case 'mixto':  return ['wompi', 'transferencia'];
            case 'todos':  return ['wompi', 'transferencia', 'contra_entrega'];
            default:       return ['contra_entrega'];
        }
    }

    /**
     * Genera el cobro de un pedido y devuelve el enlace para el cliente.
     *
     * $metodo es la elección del CLIENTE, no la del negocio: con el modo 'todos'
     * (o 'mixto') el mismo negocio acepta enlace y transferencia, así que decidir
     * por el modo global generaba un enlace de Wompi al que dijo "yo transfiero".
     * Vacío = el único método que el modo permite.
     */
    public function generar(int $pedidoId, array $conv, string $metodo = ''): array
    {
        $wp = $this->db->fetch("SELECT * FROM wa_pedidos WHERE pedido_id = ?", [$pedidoId]);
        if (!$wp) return ['ok' => false, 'error' => 'Pedido no encontrado'];
        if ($wp['estado_pago'] === 'PAYMENT_VERIFIED') {
            return ['ok' => true, 'ya_pagado' => true, 'estado' => 'PAYMENT_VERIFIED',
                    'mensaje' => 'Este pedido ya está pagado'];
        }
        // El importe lo dice EL NEGOCIO, no un `SELECT total FROM pedidos`
        // escrito aquí dentro: esa consulta daba por sentado que la transacción
        // del negocio vive en una tabla llamada `pedidos`.
        $est = $this->adapterDominio->estadoTransaccion((string)$pedidoId);
        if (!$est || !isset($est['total'])) return ['ok' => false, 'error' => 'Pedido no encontrado'];
        $total = (float)$est['total'] + (float)$wp['costo_domicilio'];

        // Un enlace vivo se reutiliza: generar uno nuevo por cada "¿y el pago?"
        // llena la pasarela de referencias huérfanas.
        $existente = $this->db->fetch(
            "SELECT * FROM wa_pagos WHERE wa_pedido_id = ? AND estado IN ('PAYMENT_INITIATED','PAYMENT_VALIDATING')
             ORDER BY id DESC LIMIT 1", [(int)$wp['id']]);
        if ($existente && !empty($existente['enlace_pago'])) {
            return ['ok' => true, 'enlace' => $existente['enlace_pago'], 'monto' => (float)$existente['monto'],
                    'estado' => $existente['estado'], 'referencia' => $existente['referencia']];
        }

        $cfg   = WaConfig::cargar($this->db);
        $modo  = $cfg['pago_modo'] ?? 'contra_entrega';
        $acepta = self::metodosDe($modo);

        // Con un solo método permitido no hay nada que preguntar; con varios, la
        // elección es del cliente y el modelo tiene que traerla.
        if ($metodo === '') $metodo = count($acepta) === 1 ? $acepta[0] : '';
        if ($metodo === '') {
            return ['ok' => false, 'metodos_aceptados' => $acepta,
                    'error' => 'Este negocio acepta varias formas de pago: pregúntale al cliente cuál prefiere y vuelve a llamar indicándola'];
        }
        if (!in_array($metodo, $acepta, true)) {
            return ['ok' => false, 'metodos_aceptados' => $acepta,
                    'error' => 'Este negocio no acepta esa forma de pago'];
        }

        // Contra entrega no hay nada que cobrar antes: el pedido sale a cocina ya
        // y el cobro queda pendiente hasta la entrega.
        if ($metodo === 'contra_entrega') {
            if ((int)$wp['enviado_cocina'] === 1) {
                return ['ok' => true, 'enlace' => null, 'monto' => $total, 'estado' => 'PAYMENT_PENDING',
                        'metodo' => 'contra_entrega', 'nota' => 'El pedido ya está en cocina; se paga al recibirlo'];
            }
            $ref = $this->nuevaReferencia($pedidoId);
            $this->registrarPago((int)$wp['id'], 'contra_entrega', 'PAYMENT_PENDING', $total, $ref, null);
            $this->cambiarEstado($pedidoId, 'PAYMENT_PENDING', 'Contra entrega: se cobra al entregar');
            $this->adapterDominio->confirmarTransaccion((string)$pedidoId);
            $this->db->query("UPDATE wa_pedidos SET enviado_cocina = 1 WHERE pedido_id = ?", [$pedidoId]);
            return ['ok' => true, 'enlace' => null, 'monto' => $total, 'referencia' => $ref,
                    'estado' => 'PAYMENT_PENDING', 'metodo' => 'contra_entrega',
                    'nota' => 'El pedido ya está en cocina. Se le cobra al cliente al entregárselo.'];
        }

        $pasarela = $metodo === 'wompi' ? $this->pasarela() : null;
        if (!$pasarela) {
            // Transferencia (o modo sin pasarela): comprobante y revisión humana.
            // Los datos de la cuenta salen de la configuración del negocio, no de
            // lo que el modelo recuerde: dictar un Nequi inventado manda la plata
            // de un cliente a otra persona.
            $ref = $this->nuevaReferencia($pedidoId);
            $this->registrarPago((int)$wp['id'], 'transferencia', 'PAYMENT_PENDING', $total, $ref, null);
            $this->cambiarEstado($pedidoId, 'PAYMENT_PENDING', 'Cobro manual: se espera comprobante');
            $datos = trim((string)($cfg['pago_datos_transferencia'] ?? ''));
            return ['ok' => true, 'enlace' => null, 'monto' => $total, 'referencia' => $ref,
                    'estado' => 'PAYMENT_PENDING', 'metodo' => 'transferencia',
                    'datos_para_transferir' => $datos !== '' ? $datos : null,
                    'instrucciones' => $datos !== ''
                        ? 'Dale al cliente EXACTAMENTE estos datos para transferir y pídele la captura del comprobante por este mismo chat.'
                        : 'Pídele al cliente que haga la transferencia y envíe la captura por este mismo chat. El negocio no dejó datos de cuenta configurados: si el cliente los pide, transfiere a una persona.'];
        }
        $faltan = $pasarela->requisitosFaltantes();
        if ($faltan) return ['ok' => false, 'error' => 'Falta configurar el cobro: ' . implode(', ', $faltan)];

        $ref = $this->nuevaReferencia($pedidoId);
        $r = $pasarela->crearCobro($total, $ref, 'Pedido #' . $pedidoId, [
            'telefono' => $conv['telefono'] ?? '', 'nombre' => $wp['nombre'] ?? '']);
        if (!$r['ok']) {
            $this->log->error('No se pudo generar el cobro: ' . $r['error'], ['pedido' => $pedidoId]);
            return ['ok' => false, 'error' => 'No se pudo generar el enlace de pago'];
        }

        // La referencia que MANDA es la que devuelve la pasarela, no la que se
        // pidió: Wompi genera la suya para los enlaces de pago, y es la que
        // llevará la transacción. Guardar la propia dejaría el webhook sin con
        // qué emparejar.
        $refFinal = !empty($r['referencia']) ? (string)$r['referencia'] : $ref;
        if ($refFinal !== $ref) {
            $this->log->log('pago', 'La pasarela asignó su propia referencia',
                ['pedido' => $pedidoId, 'solicitada' => $ref, 'asignada' => $refFinal]);
        }
        if (!empty($r['error'])) {
            $this->log->error('Aviso al generar el cobro: ' . $r['error'], ['pedido' => $pedidoId]);
        }

        $this->registrarPago((int)$wp['id'], 'wompi', 'PAYMENT_INITIATED', $total, $refFinal, $r['enlace']);
        $this->cambiarEstado($pedidoId, 'PAYMENT_INITIATED', 'Enlace de pago generado');

        return ['ok' => true, 'enlace' => $r['enlace'], 'monto' => $total, 'referencia' => $refFinal,
                'estado' => 'PAYMENT_INITIATED',
                'nota' => 'Pásale el enlace al cliente. El pedido entra a cocina cuando el pago quede confirmado.'];
    }

    /**
     * Consulta el estado REAL. Esto es lo que puede aprobar un pago; nada más.
     */
    public function consultar(int $pedidoId): array
    {
        $pago = $this->pagoDe($pedidoId);
        if (!$pago) return ['ok' => false, 'error' => 'Este pedido no tiene un cobro generado'];
        if ($pago['estado'] === 'PAYMENT_VERIFIED') {
            return ['ok' => true, 'estado' => 'PAYMENT_VERIFIED', 'pagado' => true];
        }

        // Se pregunta a la pasarela SOLO si este cobro salió por ella. En un
        // negocio que acepta enlace y transferencia a la vez, mirar el modo
        // global haría consultarle a Wompi por una referencia que nunca vio.
        $pasarela = ($pago['proveedor'] === 'wompi') ? $this->pasarela() : null;
        if (!$pasarela) {
            return ['ok' => true, 'estado' => $pago['estado'], 'pagado' => false,
                    'nota' => $pago['proveedor'] === 'contra_entrega'
                        ? 'Es contra entrega: se cobra al entregar el pedido'
                        : 'El cobro es manual: lo revisa una persona del negocio'];
        }

        $r = $pasarela->consultar((string)$pago['referencia']);
        if (!$r['ok']) return ['ok' => false, 'error' => 'No se pudo consultar el pago ahora mismo'];

        if ($r['estado'] === 'PAYMENT_VERIFIED') {
            // El monto TIENE que cuadrar. Una transacción aprobada por menos
            // plata no aprueba el pedido: pasa a revisión humana.
            if (abs($r['monto'] - (float)$pago['monto']) > 0.5) {
                $this->aplicar($pedidoId, 'PAYMENT_REVIEW_REQUIRED',
                    'Pago aprobado por un monto distinto: se esperaban ' . $pago['monto'] . ' y llegaron ' . $r['monto'],
                    ['transaccion_id' => $r['transaccion_id'], 'verificado_por' => 'consulta_api']);
                return ['ok' => true, 'estado' => 'PAYMENT_REVIEW_REQUIRED', 'pagado' => false,
                        'nota' => 'El monto no coincide; lo revisa una persona'];
            }
            $this->aplicar($pedidoId, 'PAYMENT_VERIFIED', 'Pago verificado por consulta a la pasarela',
                ['transaccion_id' => $r['transaccion_id'], 'metodo' => $r['metodo'], 'verificado_por' => 'consulta_api']);
            return ['ok' => true, 'estado' => 'PAYMENT_VERIFIED', 'pagado' => true];
        }

        $this->aplicar($pedidoId, $r['estado'], 'Estado consultado a la pasarela',
            ['transaccion_id' => $r['transaccion_id'], 'verificado_por' => 'consulta_api']);
        return ['ok' => true, 'estado' => $r['estado'], 'pagado' => false];
    }

    /**
     * Llega una captura. Se guarda, se lee y —esto es lo importante— se CONCILIA
     * contra la pasarela. La captura por sí sola no mueve nada (§21).
     */
    public function procesarComprobante(int $pedidoId, string $rutaMedia, ?array $extraido): array
    {
        $pago = $this->pagoDe($pedidoId);
        if (!$pago) return ['ok' => false, 'error' => 'Este pedido no tiene un cobro generado'];

        $this->db->query(
            "UPDATE wa_pagos SET comprobante_media_ruta = ?, comprobante_extraido = ?, estado = 'PAYMENT_SCREENSHOT_RECEIVED',
                    intentos = intentos + 1 WHERE id = ?",
            [$rutaMedia, $extraido ? json_encode($extraido, JSON_UNESCAPED_UNICODE) : null, (int)$pago['id']]);
        $this->cambiarEstado($pedidoId, 'PAYMENT_SCREENSHOT_RECEIVED', 'Comprobante recibido');

        // Camino 1: el cobro salió por la pasarela → la verdad la tiene ella.
        $pasarela = ($pago['proveedor'] === 'wompi') ? $this->pasarela() : null;
        if ($pasarela) {
            $r = $this->consultar($pedidoId);
            if (($r['estado'] ?? '') === 'PAYMENT_VERIFIED') {
                return ['ok' => true, 'estado' => 'PAYMENT_VERIFIED', 'pagado' => true,
                        'mensaje' => 'El pago quedó confirmado'];
            }
        }

        // Camino 2: sin fuente transaccional (Nequi, Daviplata, transferencia).
        // Se deja lo extraído para que la persona decida en dos segundos, pero
        // NO se aprueba nada por más que la captura parezca auténtica.
        $motivo = 'Comprobante recibido sin poder verificarlo automáticamente';
        if ($extraido && isset($extraido['valor']) && $extraido['valor'] !== null) {
            $dif = abs((float)$extraido['valor'] - (float)$pago['monto']);
            $motivo .= $dif > 0.5
                ? ('. OJO: la captura dice ' . $extraido['valor'] . ' y el pedido es de ' . $pago['monto'])
                : ('. El valor de la captura coincide con el pedido');
        }
        $this->aplicar($pedidoId, 'PAYMENT_REVIEW_REQUIRED', $motivo, []);

        return ['ok' => true, 'estado' => 'PAYMENT_REVIEW_REQUIRED', 'pagado' => false,
                'extraido' => $extraido,
                'mensaje' => 'Recibimos tu comprobante. Lo está revisando una persona del equipo y te confirmamos en un momento.'];
    }

    /**
     * Aplica un evento del webhook. Idempotente: el mismo evento dos veces no
     * acredita dos veces (§39).
     */
    public function aplicarWebhook(array $verificado): array
    {
        $ref = $verificado['referencia'] ?? '';
        if ($ref === '') return ['ok' => false, 'error' => 'Evento sin referencia'];

        $pago = $this->db->fetch("SELECT * FROM wa_pagos WHERE referencia = ?", [$ref]);
        if (!$pago) return ['ok' => false, 'error' => 'Referencia desconocida'];

        if (!empty($pago['evento_externo_id']) && $pago['evento_externo_id'] === ($verificado['evento_id'] ?? '')) {
            return ['ok' => true, 'duplicado' => true];
        }

        $wp = $this->db->fetch("SELECT pedido_id FROM wa_pedidos WHERE id = ?", [(int)$pago['wa_pedido_id']]);
        if (!$wp) return ['ok' => false, 'error' => 'Pedido no encontrado'];
        $pedidoId = (int)$wp['pedido_id'];

        $estado = $verificado['estado'] ?? 'PAYMENT_PENDING';
        if ($estado === 'PAYMENT_VERIFIED' && abs((float)$verificado['monto'] - (float)$pago['monto']) > 0.5) {
            $estado = 'PAYMENT_REVIEW_REQUIRED';
        }

        $this->db->query("UPDATE wa_pagos SET evento_externo_id = ? WHERE id = ?",
            [$verificado['evento_id'] ?? '', (int)$pago['id']]);
        $this->aplicar($pedidoId, $estado, 'Evento del webhook de la pasarela',
            ['transaccion_id' => $verificado['transaccion_id'] ?? '',
             'metodo'         => $verificado['metodo'] ?? '',
             'verificado_por' => 'webhook']);

        return ['ok' => true, 'pedido_id' => $pedidoId, 'estado' => $estado];
    }

    /** Aprobación a mano desde el panel. Queda quién la hizo. */
    public function aprobarManual(int $pedidoId, int $usuarioId, string $nota = ''): array
    {
        $pago = $this->pagoDe($pedidoId);
        if (!$pago) return ['ok' => false, 'error' => 'Este pedido no tiene un cobro'];
        $this->aplicar($pedidoId, 'PAYMENT_VERIFIED',
            'Aprobado a mano por un usuario del negocio' . ($nota !== '' ? (': ' . $nota) : ''),
            ['verificado_por' => 'humano', 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'estado' => 'PAYMENT_VERIFIED'];
    }

    /**
     * Vence los cobros abandonados: cancela el pedido y DEVUELVE EL STOCK.
     * Sin esto, un carrito que nadie pagó se queda con las últimas unidades
     * retenidas para siempre. Lo llama el cron.
     */
    public function vencerAbandonados(): int
    {
        $cfg = WaConfig::cargar($this->db);
        $min = (int)($cfg['pago_expira_minutos'] ?? 30);
        if ($min <= 0) return 0;

        $filas = $this->db->fetchAll(
            "SELECT w.pedido_id FROM wa_pedidos w
             WHERE w.estado_pago IN ('PAYMENT_PENDING','PAYMENT_INITIATED')
               AND w.enviado_cocina = 0
               AND w.created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)", [$min]);

        $n = 0;
        foreach ($filas as $f) {
            $pid = (int)$f['pedido_id'];
            $r = $this->adapterDominio->cancelarTransaccion((string)$pid);
            if (!empty($r['ok'])) {
                $this->aplicar($pid, 'PAYMENT_EXPIRED', 'Sin pagar en ' . $min . ' minutos: pedido cancelado y stock devuelto', []);
                $n++;
            }
        }
        return $n;
    }

    // ── Interno ─────────────────────────────────────────────────────────

    /**
     * Cambia el estado y, si toca, MANDA EL PEDIDO A COCINA.
     * Es el único punto del motor donde un pedido pasa a producción.
     */
    private function aplicar(int $pedidoId, string $estado, string $motivo, array $extra): void
    {
        if (!in_array($estado, self::ESTADOS, true)) $estado = 'PAYMENT_PENDING';

        $sets = ['estado = ?']; $vals = [$estado];
        if (!empty($extra['transaccion_id'])) { $sets[] = 'transaccion_externa_id = ?'; $vals[] = $extra['transaccion_id']; }
        if (!empty($extra['metodo']))         { $sets[] = 'metodo_detectado = ?';       $vals[] = $extra['metodo']; }
        if (!empty($extra['verificado_por'])) { $sets[] = 'verificado_por = ?';         $vals[] = $extra['verificado_por']; }
        if (!empty($extra['usuario_id']))     { $sets[] = 'verificado_por_usuario_id = ?'; $vals[] = (int)$extra['usuario_id']; }

        $pago = $this->pagoDe($pedidoId);
        if ($pago) {
            $vals[] = (int)$pago['id'];
            $this->db->query("UPDATE wa_pagos SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
        }
        $this->cambiarEstado($pedidoId, $estado, $motivo);

        if ($estado === 'PAYMENT_VERIFIED') {
            // El paso a cocina se reclama de forma ATÓMICA: el UPDATE condicional
            // marca enviado_cocina en la misma operación que lo comprueba, y solo
            // quien afecta la fila (afectadas === 1) emite la comanda y registra
            // en caja. Antes se leía enviado_cocina y se actualizaba en dos pasos,
            // así que dos webhooks APPROVED simultáneos pasaban ambos el guard y
            // mandaban el pedido a cocina —y a caja— dos veces.
            $afectadas = $this->db->query(
                "UPDATE wa_pedidos SET enviado_cocina = 1 WHERE pedido_id = ? AND enviado_cocina = 0",
                [$pedidoId]);
            if ($afectadas > 0) {
                $this->adapterDominio->confirmarTransaccion((string)$pedidoId);
                // SE RELEE: $pago se cargó ANTES del UPDATE de arriba, así que su
                // metodo_detectado en memoria es el viejo. Pasarlo tal cual metía
                // en caja el método por defecto aunque el pago fuera con tarjeta.
                $this->registrarEnCaja($pedidoId, $this->pagoDe($pedidoId) ?: $pago);
            }
        }
    }

    /**
     * Deja constancia del cobro en la contabilidad del negocio.
     *
     * Aquí había un `INSERT INTO pagos (pedido_id, cliente_mesa_id, …)`: la
     * caja de un bar, con una columna que en otro proyecto no existe. El motor
     * sabe SI el pago está verificado —lo consulta a la pasarela—, pero no
     * dónde apunta el dinero cada negocio. Eso lo pone quien lo sepa.
     *
     * El método de pago se traduce ANTES de entregarlo, y de lo que informa la
     * pasarela (`payment_method_type`), no del modo configurado: es lo que hacía
     * entrar una tarjeta a caja como transferencia y descuadrar el arqueo.
     */
    private function registrarEnCaja(int $pedidoId, ?array $pago): void
    {
        if (!$pago) return;
        if (!($this->adapterDominio instanceof \ElkinLinan\WhatsappAiEngine\Ports\SoportaRegistroDePago)) {
            return;
        }
        try {
            $this->adapterDominio->registrarPagoEnCaja((string)$pedidoId, [
                'monto'      => (float)$pago['monto'],
                'referencia' => (string)$pago['referencia'],
                'metodo'     => WompiAdapter::metodoInterno((string)($pago['metodo_detectado'] ?? '')),
                // También el valor CRUDO de la pasarela (`CARD`, `NEQUI`, `PSE`…):
                // `metodo` está reducido a tarjeta/transferencia, que es lo que
                // admite el enum de un proyecto, y otro puede tener más casillas
                // —Nequi y Daviplata separadas, por ejemplo— y quedarse sin poder
                // distinguirlas si solo se le da la versión reducida.
                'metodo_pasarela' => (string)($pago['metodo_detectado'] ?? ''),
                'proveedor'  => (string)($pago['proveedor'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->log->error('El pago no se pudo registrar en caja: ' . $e->getMessage(), ['pedido' => $pedidoId]);
        }
    }

    private function cambiarEstado(int $pedidoId, string $estado, string $motivo): void
    {
        $this->db->query("UPDATE wa_pedidos SET estado_pago = ? WHERE pedido_id = ?", [$estado, $pedidoId]);
        $this->log->log('pago', 'Pedido #' . $pedidoId . ' → ' . $estado, ['motivo' => $motivo]);
    }

    private function pagoDe(int $pedidoId): ?array
    {
        $r = $this->db->fetch(
            "SELECT p.* FROM wa_pagos p JOIN wa_pedidos w ON w.id = p.wa_pedido_id
             WHERE w.pedido_id = ? ORDER BY p.id DESC LIMIT 1", [$pedidoId]);
        return $r ?: null;
    }

    private function registrarPago(int $waPedidoId, string $proveedor, string $estado,
                                   float $monto, string $ref, ?string $enlace): void
    {
        $this->db->insert(
            "INSERT INTO wa_pagos (wa_pedido_id, proveedor, estado, monto, moneda, referencia, enlace_pago)
             VALUES (?,?,?,?,'COP',?,?)",
            [$waPedidoId, $proveedor, $estado, $monto, $ref, $enlace]);
    }

    /** Referencia única y trazable: se ve el pedido de un vistazo en la pasarela. */
    private function nuevaReferencia(int $pedidoId): string
    {
        $tid = \ElkinLinan\WhatsappAiEngine\Engine::negocio()->id() ?: 0;
        return 'CBM-' . $tid . '-' . $pedidoId . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
