<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Modelos\Contacto;
use App\Repositorios\ConsultaRepo;
use App\Soporte\Fechas;
use App\Soporte\Http;
use App\Soporte\Logger;

/**
 * Wompi, por Web Checkout firmado.
 *
 * Dos secretos con papeles distintos, y confundirlos es el error clásico de
 * esta integración:
 *
 *  · `clave_integridad` firma el ENLACE que se le da al contacto. Sin ella,
 *    cualquiera que conozca la llave pública puede armar un checkout por
 *    otro monto — pagar $4.000 por una asesoría de $400.000.
 *  · `clave_eventos` firma lo que Wompi NOS manda. Es la que hace cumplir la
 *    regla 6: sin checksum válido, el evento no toca la base.
 *
 * El enlace se construye firmado, sin llamar a la API: es la integración
 * documentada de Web Checkout y no depende de que Wompi responda en el
 * momento en que el bot está hablando.
 */
final class PagosWompi implements Pagos
{
    private const CHECKOUT = 'https://checkout.wompi.co/p/';

    private const API = [
        'produccion' => 'https://production.wompi.co/v1',
        'pruebas' => 'https://sandbox.wompi.co/v1',
    ];

    /** Estados de Wompi → estados de `pagos.estado`. */
    private const ESTADOS = [
        'APPROVED' => 'aprobado',
        'DECLINED' => 'rechazado',
        'VOIDED' => 'reversado',
        'ERROR' => 'rechazado',
        'PENDING' => 'pendiente',
    ];

    public function __construct(
        private readonly BD $bd,
        private readonly Credenciales $credenciales,
        private readonly ConsultaRepo $consultas,
        private readonly Config $config,
        private readonly Outbox $outbox,
        private readonly Http $http,
        private readonly Logger $log,
    ) {
    }

    public function crearLink(
        string $consultaId,
        int $montoPesos,
        string $descripcion,
        Contacto $contacto,
    ): array {
        $consulta = $this->consultas->porId($consultaId)
            ?? throw new \InvalidArgumentException("La consulta {$consultaId} no existe.");

        if ($consulta->estado !== 'reservada') {
            throw new \DomainException(
                "La consulta está «{$consulta->estado}»: solo una reservada genera enlace de pago.",
            );
        }

        // ADR-010: la ÚNICA multiplicación por 100 del sistema.
        $montoCentavos = $montoPesos * 100;

        // La referencia lleva el id de la consulta para que la conciliación
        // manual sea posible leyéndola, más un sufijo aleatorio para que
        // reintentar la creación no choque con el UNIQUE.
        $referencia = 'PA-' . substr(str_replace('-', '', $consultaId), 0, 12)
            . '-' . bin2hex(random_bytes(4));

        $llavePublica = $this->credenciales->obtener('wompi', 'llave_publica');
        $integridad = $this->credenciales->obtener('wompi', 'clave_integridad');

        // Firma de integridad documentada: referencia + monto + moneda + secreto.
        $firma = hash('sha256', $referencia . $montoCentavos . 'COP' . $integridad);

        $url = self::CHECKOUT . '?' . http_build_query([
            'public-key' => $llavePublica,
            'currency' => 'COP',
            'amount-in-cents' => $montoCentavos,
            'reference' => $referencia,
            'signature:integrity' => $firma,
        ]);

        $pdo = $this->bd->pdo();

        $stmt = $pdo->prepare(
            "INSERT INTO pagos (consulta_id, pasarela, referencia, monto_centavos, moneda, estado, url_checkout)
             VALUES (?, 'wompi', ?, ?, 'COP', 'pendiente', ?)"
        );
        $stmt->execute([$consultaId, $referencia, $montoCentavos, $url]);

        $stmt = $pdo->prepare('SELECT id FROM pagos WHERE referencia = ?');
        $stmt->execute([$referencia]);
        $pagoId = (string) $stmt->fetchColumn();

        // La expiración del enlace es la de la reserva: no tiene sentido un
        // link que sobreviva al cupo que paga.
        $expiraEn = $consulta->reservaExpira !== null
            ? Fechas::deUtc($consulta->reservaExpira)
            : Fechas::ahora()->modify(
                '+' . max(1, (int) $this->config->get('minutos_reserva_pago', 45)) . ' minutes',
            );

        $this->log->info('pagos.link_creado', [
            'referencia' => $referencia,
            'monto_centavos' => $montoCentavos,
        ]);

        return [
            'url' => $url,
            'referencia' => $referencia,
            'pagoId' => $pagoId,
            'expiraEn' => $expiraEn,
        ];
    }

    public function verificarWebhook(string $cuerpoCrudo, array $cabeceras): array
    {
        $nada = ['valido' => false, 'referencia' => '', 'estado' => ''];

        $evento = json_decode($cuerpoCrudo, true);

        if (!is_array($evento)) {
            return $nada;
        }

        $checksum = $evento['signature']['checksum'] ?? null;
        $propiedades = $evento['signature']['properties'] ?? null;
        $timestamp = $evento['timestamp'] ?? null;

        if (!is_string($checksum) || !is_array($propiedades) || $timestamp === null) {
            return $nada;
        }

        // El checksum de Wompi: los VALORES de las propiedades listadas, en
        // su orden, concatenados con el timestamp y el secreto de eventos.
        // Se leen del propio evento (`data`), que es exactamente lo que la
        // firma protege.
        $concatenado = '';

        foreach ($propiedades as $ruta) {
            $valor = $this->valorPorRuta($evento['data'] ?? [], (string) $ruta);

            if ($valor === null) {
                return $nada;
            }

            $concatenado .= $valor;
        }

        try {
            $secreto = $this->credenciales->obtener('wompi', 'clave_eventos');
        } catch (\App\Excepciones\CredencialNoEncontradaException) {
            // Sin secreto no hay forma de validar NADA: mejor rechazar todo
            // que aceptar todo. La alerta va al registro, no al atacante.
            $this->log->error('pagos.webhook_sin_secreto', []);

            return $nada;
        }

        $esperado = hash('sha256', $concatenado . $timestamp . $secreto);

        if (!hash_equals(strtolower($esperado), strtolower($checksum))) {
            return $nada;
        }

        $transaccion = $evento['data']['transaction'] ?? [];

        return [
            'valido' => true,
            'referencia' => (string) ($transaccion['reference'] ?? ''),
            'estado' => self::ESTADOS[(string) ($transaccion['status'] ?? '')] ?? 'pendiente',
        ];
    }

    public function procesarWebhook(string $cuerpoCrudo, array $cabeceras): array
    {
        $veredicto = $this->verificarWebhook($cuerpoCrudo, $cabeceras);

        if (!$veredicto['valido']) {
            // Regla 6 en acción: firma inválida = cero escrituras. Ni
            // siquiera se guarda el payload para «mirarlo después»: guardar
            // basura firmada por nadie es darle al atacante una vía de
            // escritura.
            $this->log->warn('pagos.webhook_rechazado', ['ip_origen' => 'ver access log']);

            return [...$veredicto, 'procesado' => false];
        }

        $pdo = $this->bd->pdo();
        $pdo->beginTransaction();

        try {
            // FOR UPDATE: dos entregas simultáneas del mismo evento (Wompi
            // reintenta) serializan aquí, y la segunda ve el estado que dejó
            // la primera.
            $stmt = $pdo->prepare('SELECT * FROM pagos WHERE referencia = ? FOR UPDATE');
            $stmt->execute([$veredicto['referencia']]);
            $pago = $stmt->fetch();

            if ($pago === false) {
                // Firma válida pero referencia desconocida: un evento de otro
                // entorno (sandbox contra producción) o de otra cuenta. Se
                // reconoce sin procesar para que Wompi no lo reintente.
                $pdo->rollBack();

                return [...$veredicto, 'procesado' => false];
            }

            $evento = json_decode($cuerpoCrudo, true);
            $montoEvento = (int) ($evento['data']['transaction']['amount_in_cents'] ?? -1);

            // El monto del evento tiene que ser el del pago. Sin esto, un
            // APPROVED legítimo de $4.000 confirmaría una asesoría de
            // $400.000 — el checkout firmado lo impide aguas arriba, pero la
            // defensa barata se pone en los dos extremos.
            if ($veredicto['estado'] === 'aprobado' && $montoEvento !== (int) $pago['monto_centavos']) {
                $pdo->rollBack();
                $this->log->error('pagos.monto_no_cuadra', [
                    'referencia' => $veredicto['referencia'],
                    'esperado' => (int) $pago['monto_centavos'],
                    'recibido' => $montoEvento,
                ]);

                return [...$veredicto, 'procesado' => false];
            }

            if ($pago['estado'] === 'aprobado') {
                // Idempotencia: ya se confirmó una vez. El duplicado se
                // reconoce y no vuelve a encolar nada.
                $pdo->rollBack();

                return [...$veredicto, 'procesado' => false];
            }

            $stmt = $pdo->prepare(
                'UPDATE pagos
                    SET estado = ?, firma_verificada = 1, payload_webhook = ?,
                        confirmado_en = IF(? = \'aprobado\', NOW(), confirmado_en)
                  WHERE id = ?'
            );
            $stmt->execute([
                $veredicto['estado'],
                json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $veredicto['estado'],
                $pago['id'],
            ]);

            $procesado = false;

            if ($veredicto['estado'] === 'aprobado') {
                $procesado = $this->confirmar((string) $pago['consulta_id']);
            }

            $pdo->commit();

            return [...$veredicto, 'procesado' => $procesado];
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    public function consultarEstado(string $referencia): array
    {
        $privada = $this->credenciales->obtener('wompi', 'llave_privada');
        $base = str_starts_with($privada, 'prv_test_') ? self::API['pruebas'] : self::API['produccion'];

        $respuesta = $this->http->pedir('GET', $base . '/transactions?reference=' . rawurlencode($referencia), [
            'Authorization' => 'Bearer ' . $privada,
            'accept' => 'application/json',
        ]);

        $datos = $respuesta->ok() ? ($respuesta->json()['data'] ?? []) : [];
        $transaccion = $datos[0] ?? null;

        if (!is_array($transaccion)) {
            return ['encontrado' => false, 'estado' => '', 'monto_centavos' => 0];
        }

        return [
            'encontrado' => true,
            'estado' => self::ESTADOS[(string) ($transaccion['status'] ?? '')] ?? 'pendiente',
            'monto_centavos' => (int) ($transaccion['amount_in_cents'] ?? 0),
        ];
    }

    /**
     * La consulta pasa a `pagada` y se encolan los tres efectos.
     *
     * Corre DENTRO de la transacción del webhook: encolar es una escritura
     * (ADR-004), así que confirmación y efectos son atómicos — o queda todo,
     * o no queda nada y Wompi reintenta.
     *
     * @return bool si la consulta quedó confirmada
     */
    private function confirmar(string $consultaId): bool
    {
        $consulta = $this->consultas->porId($consultaId);

        if ($consulta === null) {
            return false;
        }

        // El webhook tardío: el contacto guardó el enlace y pagó DESPUÉS de
        // que la reserva expirara y el cupo se liberara. El dinero es real y
        // queda registrado en `pagos` como aprobado, pero confirmar aquí
        // agendaría sobre un slot que otra persona pudo tomar. Eso es
        // conciliación humana —devolver o reagendar—, no automatismo.
        if ($consulta->estado !== 'reservada') {
            $this->outbox->encolar('alerta.pago_huerfano', [
                'estado_consulta' => $consulta->estado,
                'fecha' => $consulta->fecha,
                'hora' => Fechas::horaNatural($consulta->horaInicio),
                'chatwoot_conv_id' => $this->conversacionDeCaso($consulta->casoId),
            ]);

            $this->log->error('pagos.huerfano', [
                'consulta' => $consultaId,
                'estado' => $consulta->estado,
            ]);

            return false;
        }

        $this->consultas->cambiarEstado($consultaId, 'pagada');

        $conv = $this->conversacionDeCaso($consulta->casoId);

        // 1. Confirmación al contacto, por Chatwoot (ADR-001). Plantilla
        //    fija, no LLM: una confirmación no necesita criterio y no puede
        //    permitirse una alucinación.
        if ($conv !== null) {
            $this->outbox->encolar('chatwoot.entregar', [
                'chatwoot_conv_id' => $conv,
                'texto' => '✅ Su pago fue confirmado. La asesoría quedó agendada para el '
                    . Fechas::fechaNatural($consulta->fecha) . ' a las '
                    . Fechas::horaNatural($consulta->horaInicio)
                    . '. Un día antes le recordaremos la cita por este medio.',
            ]);
        }

        // 2. Recordatorio 24 h antes de la cita, programado desde ya. Si la
        //    cita es en menos de 24 h, sale de inmediato — mejor un
        //    recordatorio temprano que ninguno. `Fechas::combinar` y no un
        //    DateTimeImmutable a mano: la cita está en hora de Bogotá y el
        //    reloj del proceso puede no estarlo (CONTRATOS §Errores 17).
        if ($conv !== null) {
            $cita = Fechas::combinar($consulta->fecha, $consulta->horaInicio);
            $retraso = max(0, $cita->getTimestamp() - 86400 - Fechas::ahora()->getTimestamp());

            $this->outbox->encolar('chatwoot.entregar', [
                'chatwoot_conv_id' => $conv,
                'texto' => '📅 Le recordamos su asesoría con el Dr. Pedro: '
                    . Fechas::fechaNatural($consulta->fecha) . ' a las '
                    . Fechas::horaNatural($consulta->horaInicio) . ' (hora de Colombia).',
            ], $retraso);
        }

        // 3. Aviso a Pedro por su canal interno. Sin datos del caso: la
        //    referencia de la agenda basta (misma disciplina que la regla 14).
        $this->outbox->encolar('alerta.pago_confirmado', [
            'fecha' => $consulta->fecha,
            'hora' => Fechas::horaNatural($consulta->horaInicio),
            'chatwoot_conv_id' => $conv,
        ]);

        return true;
    }

    private function conversacionDeCaso(string $casoId): ?int
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT chatwoot_conv_id FROM conversacion_estado WHERE caso_id = ? LIMIT 1'
        );
        $stmt->execute([$casoId]);
        $conv = $stmt->fetchColumn();

        return $conv === false ? null : (int) $conv;
    }

    /** «transaction.id» → $data['transaction']['id'], como los lista Wompi. */
    private function valorPorRuta(array $datos, string $ruta): ?string
    {
        $actual = $datos;

        foreach (explode('.', $ruta) as $paso) {
            if (!is_array($actual) || !array_key_exists($paso, $actual)) {
                return null;
            }

            $actual = $actual[$paso];
        }

        return is_scalar($actual) ? (string) $actual : null;
    }
}
