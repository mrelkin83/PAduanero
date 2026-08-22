<?php
/**
 * ============================================================================
 * PaymentAdapterInterface — una pasarela de pago (§23)
 * ============================================================================
 * Mismo patrón que los proveedores LLM y que los emisores de la DIAN. Añadir
 * Nequi o Daviplata mañana es escribir otra implementación, no tocar el motor.
 *
 * Si un método NO permite verificación transaccional automática, su adapter
 * devuelve PAYMENT_REVIEW_REQUIRED y una persona decide. El §23 lo pide y es de
 * sentido común: no se puede dar por cobrado lo que no se puede comprobar.
 */

namespace ElkinLinan\WhatsappAiEngine\Payments;

interface PaymentAdapterInterface
{
    public function nombre(): string;

    /** Configuración incompleta: qué falta. */
    public function requisitosFaltantes(): array;

    /**
     * Crea el cobro.
     * @return ['ok'=>bool, 'enlace'=>string, 'referencia'=>string, 'estado'=>string, 'error'=>string]
     */
    public function crearCobro(float $monto, string $referencia, string $descripcion, array $cliente = []): array;

    /**
     * Consulta el estado REAL en la pasarela. Es la fuente de verdad (§21).
     * @return ['ok'=>bool, 'estado'=>string, 'monto'=>float, 'transaccion_id'=>string,
     *          'metodo'=>string, 'error'=>string]
     */
    public function consultar(string $referencia): array;

    /**
     * Verifica la firma de un webhook. Un evento sin firma válida NO se procesa.
     * @return ['ok'=>bool, 'referencia'=>string, 'estado'=>string, 'monto'=>float,
     *          'transaccion_id'=>string, 'evento_id'=>string, 'error'=>string]
     */
    public function verificarWebhook(string $cuerpoCrudo, array $cabeceras): array;
}
