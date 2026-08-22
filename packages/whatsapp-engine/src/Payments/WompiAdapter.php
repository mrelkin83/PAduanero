<?php
/**
 * ============================================================================
 * WompiAdapter — cobro en línea con Wompi (§22)
 * ============================================================================
 * VERIFICADO CONTRA LA API REAL DE SANDBOX el 2026-08-13. Lo comprobado:
 *
 *   · Bases: https://sandbox.wompi.co/v1 y https://production.wompi.co/v1
 *   · `GET /merchants/{llave_publica}` → 200 SIN autenticación
 *   · `GET /transactions?reference=…` → **401 con la llave PÚBLICA**, 200 con la
 *     PRIVADA. Este detalle importa más que ninguno: es la consulta que decide
 *     si un pago está verificado, y con la llave equivocada nunca respondía.
 *   · `POST /payment_links` con la privada → 201
 *   · `GET /transactions/{id}` existe (404 si no está)
 *   · Firma del webhook: SHA-256 de los valores de `signature.properties` en su
 *     orden + `timestamp` + secreto de eventos. Las rutas son relativas a `data`.
 *   · Estados de transacción: APPROVED · DECLINED · VOIDED · ERROR (+ PENDING)
 *   · Los montos van en CENTAVOS
 *   · Prefijos de llave: pub_test_/pub_prod_, prv_test_/prv_prod_,
 *     test_events_/prod_events_, test_integrity_/prod_integrity_
 *
 * LA TRAMPA DEL ENLACE DE PAGO: `POST /payment_links` **descarta la referencia
 * que uno le manda** y genera la suya (algo como `test_HB3ZI5_1786…_UOSscodOL`),
 * que es la que acabará llevando la transacción. Emparejar el webhook por la
 * referencia propia no habría casado nunca. Por eso, tras crear el enlace se
 * relee y se guarda LA REFERENCIA DE WOMPI como clave de emparejamiento.
 *
 * Queda sin verificar (necesita un pago real de punta a punta en sandbox): la
 * caducidad de un enlace y la forma exacta del evento entrante.
 */

namespace ElkinLinan\WhatsappAiEngine\Payments;

use ElkinLinan\WhatsappAiEngine\Core\Http;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;


class WompiAdapter implements PaymentAdapterInterface
{
    const BASE_SANDBOX = 'https://sandbox.wompi.co/v1';
    const BASE_PROD    = 'https://production.wompi.co/v1';
    const CHECKOUT     = 'https://checkout.wompi.co/l/';

    private $ambiente;
    private $publicKey;
    private $privateKey;
    private $eventsSecret;
    private $integritySecret;

    public function __construct(string $ambiente, string $publicKey, string $privateKey,
                                string $eventsSecret, string $integritySecret)
    {
        $this->ambiente        = $ambiente === 'produccion' ? 'produccion' : 'sandbox';
        $this->publicKey       = $publicKey;
        $this->privateKey      = $privateKey;
        $this->eventsSecret    = $eventsSecret;
        $this->integritySecret = $integritySecret;
    }

    public static function desdeConfig(?array $cfg): ?self
    {
        if (!$cfg || empty($cfg['wompi_public_key'])) return null;
        return new self(
            (string)($cfg['wompi_ambiente'] ?? 'sandbox'),
            (string)$cfg['wompi_public_key'],
            WaConfig::secreto($cfg, 'wompi_private_key'),
            WaConfig::secreto($cfg, 'wompi_events_secret'),
            WaConfig::secreto($cfg, 'wompi_integrity_secret')
        );
    }

    public function nombre(): string
    {
        return 'Wompi (' . $this->ambiente . ')';
    }

    private function base(): string
    {
        return $this->ambiente === 'produccion' ? self::BASE_PROD : self::BASE_SANDBOX;
    }

    public function requisitosFaltantes(): array
    {
        $faltan = [];
        if ($this->publicKey === '')       $faltan[] = 'Llave pública de Wompi';
        if ($this->privateKey === '')      $faltan[] = 'Llave privada de Wompi';
        if ($this->eventsSecret === '')    $faltan[] = 'Secreto de eventos (firma del webhook)';
        if ($this->integritySecret === '') $faltan[] = 'Secreto de integridad';

        // Credenciales del ambiente equivocado. Se revisan LAS CUATRO, no solo
        // la pública: es fácil copiar las llaves de sandbox y, sin darse cuenta,
        // los secretos de producción — y entonces ninguna firma valida y los
        // cobros fallan de un modo que no se parece en nada a su causa.
        $esperado = $this->ambiente === 'produccion' ? 'prod' : 'test';
        $contrario = $esperado === 'prod' ? 'test' : 'prod';
        $etiqueta = $this->ambiente === 'produccion' ? 'PRODUCCIÓN' : 'PRUEBAS';
        $credenciales = [
            'Llave pública'         => [$this->publicKey,       'pub_'],
            'Llave privada'         => [$this->privateKey,      'prv_'],
            'Secreto de eventos'    => [$this->eventsSecret,    ''],
            'Secreto de integridad' => [$this->integritySecret, ''],
        ];
        foreach ($credenciales as $nombre => [$valor, $prefijo]) {
            if ($valor === '') continue;
            $marcaContraria = $prefijo !== '' ? ($prefijo . $contrario . '_') : ($contrario . '_');
            if (strpos($valor, $marcaContraria) === 0 || strpos($valor, '_' . $contrario . '_') !== false) {
                $faltan[] = 'El ambiente dice ' . $etiqueta . ' pero «' . $nombre . '» es del otro ambiente';
                continue;
            }
            // Y que TENGA la forma de una credencial de Wompi. Sin esto, una URL
            // pegada por error en el campo (pasó de verdad el 2026-08-14) se
            // guardaba sin protesta y el fallo aparecía al cobrar, como un 401.
            $marcaPropia = $prefijo !== '' ? ($prefijo . $esperado . '_') : ($esperado . '_');
            if (strpos($valor, $marcaPropia) !== 0) {
                $faltan[] = '«' . $nombre . '» no tiene forma de credencial de Wompi (debería empezar por ' . $marcaPropia . ')';
            }
        }
        return $faltan;
    }

    /** Wompi trabaja en CENTAVOS. Un peso de más aquí es un cobro mal hecho. */
    private static function aCentavos(float $monto): int
    {
        return (int)round($monto * 100);
    }

    public function crearCobro(float $monto, string $referencia, string $descripcion, array $cliente = []): array
    {
        $out = ['ok' => false, 'enlace' => '', 'referencia' => $referencia,
                'estado' => 'PAYMENT_PENDING', 'error' => ''];

        // Solo se exige lo que ESTE camino necesita de verdad. Pedir aquí los
        // cuatro secretos impedía generar un cobro por faltar el secreto de
        // eventos, que no interviene hasta que la pasarela avisa del pago.
        // requisitosFaltantes() sigue reportándolo todo para el panel.
        if ($this->privateKey === '') { $out['error'] = 'Falta la llave privada de Wompi'; return $out; }
        if ($this->publicKey === '')  { $out['error'] = 'Falta la llave pública de Wompi'; return $out; }
        $centavos = self::aCentavos($monto);
        if ($centavos <= 0) { $out['error'] = 'Monto inválido'; return $out; }

        $cuerpo = [
            'name'                    => mb_substr($descripcion, 0, 60),
            'description'             => mb_substr($descripcion, 0, 200),
            'single_use'              => true,
            'collect_shipping'        => false,
            'currency'                => 'COP',
            'amount_in_cents'         => $centavos,
            'reference'               => $referencia,
        ];
        $r = Http::json('POST', $this->base() . '/payment_links',
            ['Authorization: Bearer ' . $this->privateKey], $cuerpo, 45);

        if ($r['ok'] && !empty($r['json']['data']['id'])) {
            $id = (string)$r['json']['data']['id'];

            // Wompi DESCARTA la referencia enviada y genera la suya, que es la
            // que llevará la transacción. Hay que quedarse con esa o el webhook
            // no casará nunca. La respuesta del POST no la trae; el GET sí.
            $refWompi = (string)($r['json']['data']['reference'] ?? '');
            if ($refWompi === '') {
                $g = Http::json('GET', $this->base() . '/payment_links/' . rawurlencode($id),
                    ['Authorization: Bearer ' . $this->privateKey], null, 30);
                $refWompi = (string)($g['json']['data']['reference'] ?? '');
            }

            $out['ok']         = true;
            $out['enlace']     = self::CHECKOUT . $id;
            $out['referencia'] = $refWompi ?: $referencia;
            $out['externo_id'] = $id;
            $out['estado']     = 'PAYMENT_INITIATED';
            if ($refWompi === '') {
                // Sin referencia de Wompi el emparejamiento automático queda en
                // el aire: mejor decirlo que descubrirlo cuando alguien pague.
                $out['error'] = 'No se pudo leer la referencia del enlace; la confirmación automática puede fallar';
            }
            return $out;
        }

        // Respaldo: URL de Checkout Web firmada. Si el endpoint de enlaces cambió
        // de forma o de nombre, el cobro sigue siendo posible por esta vía — y
        // esta sí conserva la referencia propia. Necesita el secreto de
        // integridad; sin él no se puede firmar y no hay respaldo que valga.
        if ($this->integritySecret === '') {
            $out['error'] = 'No se pudo crear el enlace de pago (' . $r['error']
                          . ') y sin el secreto de integridad no hay alternativa';
            return $out;
        }
        $firma = hash('sha256', $referencia . $centavos . 'COP' . $this->integritySecret);
        $params = [
            'public-key'           => $this->publicKey,
            'currency'             => 'COP',
            'amount-in-cents'      => $centavos,
            'reference'            => $referencia,
            'signature:integrity'  => $firma,
        ];
        if (!empty($cliente['telefono'])) $params['customer-data:phone-number'] = preg_replace('/\D+/', '', $cliente['telefono']);
        if (!empty($cliente['nombre']))   $params['customer-data:full-name']    = $cliente['nombre'];

        $out['ok']     = true;
        $out['enlace'] = 'https://checkout.wompi.co/p/?' . http_build_query($params);
        $out['estado'] = 'PAYMENT_INITIATED';
        $out['error']  = 'Enlace generado por Checkout Web (el endpoint de payment_links falló: ' . $r['error'] . ')';
        return $out;
    }

    public function consultar(string $referencia): array
    {
        $out = ['ok' => false, 'estado' => 'PAYMENT_PENDING', 'monto' => 0.0,
                'transaccion_id' => '', 'metodo' => '', 'error' => ''];
        if ($this->privateKey === '') { $out['error'] = 'Wompi sin configurar'; return $out; }

        // CON LA LLAVE PRIVADA. Con la pública este endpoint responde 401
        // (comprobado contra sandbox), y como esta es la consulta que decide si
        // un pago está verificado, el fallo se habría manifestado como "el
        // cliente pagó pero el pedido nunca entra a cocina".
        $r = Http::json('GET', $this->base() . '/transactions?reference=' . urlencode($referencia),
            ['Authorization: Bearer ' . $this->privateKey], null, 30);
        if (!$r['ok']) { $out['error'] = $r['error']; return $out; }

        $datos = $r['json']['data'] ?? [];
        if (!$datos) {
            // Aún no ha pagado: no es un error, es que no hay transacción.
            $out['ok'] = true;
            $out['estado'] = 'PAYMENT_INITIATED';
            return $out;
        }
        // Puede haber varios intentos sobre la misma referencia; manda el aprobado.
        $elegida = null;
        foreach ($datos as $t) {
            if (($t['status'] ?? '') === 'APPROVED') { $elegida = $t; break; }
            if ($elegida === null) $elegida = $t;
        }
        $out['ok']             = true;
        $out['estado']         = self::mapearEstado((string)($elegida['status'] ?? ''));
        $out['monto']          = ((int)($elegida['amount_in_cents'] ?? 0)) / 100;
        $out['transaccion_id'] = (string)($elegida['id'] ?? '');
        $out['metodo']         = (string)($elegida['payment_method_type'] ?? '');
        return $out;
    }

    public function verificarWebhook(string $cuerpoCrudo, array $cabeceras): array
    {
        $out = ['ok' => false, 'referencia' => '', 'estado' => '', 'monto' => 0.0,
                'transaccion_id' => '', 'evento_id' => '', 'error' => ''];

        $j = json_decode($cuerpoCrudo, true);
        if (!is_array($j)) { $out['error'] = 'Cuerpo no es JSON'; return $out; }
        if ($this->eventsSecret === '') { $out['error'] = 'Sin secreto de eventos: no se puede verificar'; return $out; }

        $props     = $j['signature']['properties'] ?? [];
        $checksum  = strtoupper((string)($j['signature']['checksum'] ?? ''));
        $timestamp = (string)($j['timestamp'] ?? '');
        if (!$props || $checksum === '') { $out['error'] = 'Evento sin firma'; return $out; }

        // La firma es SHA-256 de los valores de las propiedades listadas, en su
        // orden, + timestamp + secreto de eventos.
        //
        // OJO CON LA RAÍZ: las rutas vienen como "transaction.id", relativas a
        // `data`, NO al objeto completo. Resolverlas desde la raíz da null en
        // todas, la concatenación sale vacía y entonces NINGUNA firma legítima
        // valida — el webhook de pago quedaría muerto sin dar un solo error.
        $raiz = $j['data'] ?? $j;
        $concat = '';
        foreach ($props as $ruta) {
            $valor = $raiz;
            foreach (explode('.', (string)$ruta) as $paso) {
                $valor = is_array($valor) && array_key_exists($paso, $valor) ? $valor[$paso] : null;
                if ($valor === null) break;
            }
            $concat .= (string)$valor;
        }
        $calculada = strtoupper(hash('sha256', $concat . $timestamp . $this->eventsSecret));

        if (!hash_equals($calculada, $checksum)) {
            $out['error'] = 'Firma inválida';
            return $out;
        }

        $t = $j['data']['transaction'] ?? [];
        $out['ok']             = true;
        $out['referencia']     = (string)($t['reference'] ?? '');
        $out['estado']         = self::mapearEstado((string)($t['status'] ?? ''));
        $out['monto']          = ((int)($t['amount_in_cents'] ?? 0)) / 100;
        $out['transaccion_id'] = (string)($t['id'] ?? '');
        // CON QUÉ pagó. Sin esto el evento no dice el medio y el cobro entraba a
        // caja con el valor por defecto: un pago con TARJETA quedaba registrado
        // como transferencia y descuadraba el arqueo. Lo cazó el primer pago de
        // punta a punta en sandbox (2026-08-14).
        $out['metodo']         = (string)($t['payment_method_type'] ?? '');
        // Sin id de evento propio, la transacción + su estado identifican el
        // evento lo bastante bien para no aplicarlo dos veces.
        $out['evento_id']      = (string)($j['event'] ?? 'transaction') . ':' . $out['transaccion_id'] . ':' . ($t['status'] ?? '');
        return $out;
    }

    /** Estados de Wompi → los nueve estados del §24. */
    private static function mapearEstado(string $wompi): string
    {
        switch (strtoupper($wompi)) {
            case 'APPROVED': return 'PAYMENT_VERIFIED';
            case 'DECLINED':
            case 'ERROR':    return 'PAYMENT_REJECTED';
            case 'VOIDED':   return 'PAYMENT_REFUNDED';
            case 'PENDING':  return 'PAYMENT_VALIDATING';
            default:         return 'PAYMENT_PENDING';
        }
    }

    /** Método de pago de Wompi → el ENUM de `pagos.metodo`, que no se altera. */
    public static function metodoInterno(string $metodoWompi): string
    {
        $m = strtoupper($metodoWompi);
        if ($m === 'CARD') return 'tarjeta';
        return 'transferencia';   // PSE, NEQUI, BANCOLOMBIA_TRANSFER, etc.
    }
}
