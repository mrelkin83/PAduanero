<?php

declare(strict_types=1);

namespace Pruebas\Dobles;

use ElkinLinan\WhatsappAiEngine\Payments\PaymentAdapterInterface;

/**
 * Doble de PaymentAdapterInterface para pruebas: nunca hace peticiones HTTP.
 * Configurar las propiedades públicas antes de usar; leer `llamadasCrearCobro`
 * para verificar qué se le pidió.
 */
final class PaymentAdapterFalso implements PaymentAdapterInterface
{
    /** @var array{ok:bool,enlace:string,referencia:string,estado:string,externo_id?:string,error:string} */
    public array $respuestaCrearCobro = [
        'ok' => true, 'enlace' => 'https://checkout.wompi.co/l/falso123',
        'referencia' => 'wompi_ref_falsa', 'estado' => 'PAYMENT_INITIATED',
        'externo_id' => 'falso123', 'error' => '',
    ];

    /** @var array{ok:bool,estado:string,monto:float,transaccion_id:string,metodo:string,error:string} */
    public array $respuestaConsultar = [
        'ok' => true, 'estado' => 'PAYMENT_INITIATED', 'monto' => 0.0,
        'transaccion_id' => '', 'metodo' => '', 'error' => '',
    ];

    /** @var array{ok:bool,referencia:string,estado:string,monto:float,transaccion_id:string,evento_id:string,payment_link_id:string,error:string} */
    public array $respuestaVerificarWebhook = [
        'ok' => true, 'referencia' => '', 'estado' => 'PAYMENT_VERIFIED', 'monto' => 0.0,
        'transaccion_id' => 'txn_falso', 'evento_id' => 'evt_falso', 'payment_link_id' => '', 'error' => '',
    ];

    /** @var list<array{monto:float,referencia:string,descripcion:string,cliente:array,redirectUrl:?string}> */
    public array $llamadasCrearCobro = [];

    public function nombre(): string
    {
        return 'Falso';
    }

    public function requisitosFaltantes(): array
    {
        return [];
    }

    public function crearCobro(float $monto, string $referencia, string $descripcion, array $cliente = [], ?string $redirectUrl = null): array
    {
        $this->llamadasCrearCobro[] = [
            'monto' => $monto, 'referencia' => $referencia, 'descripcion' => $descripcion,
            'cliente' => $cliente, 'redirectUrl' => $redirectUrl,
        ];

        return $this->respuestaCrearCobro;
    }

    public function consultar(string $referencia): array
    {
        return $this->respuestaConsultar;
    }

    public function verificarWebhook(string $cuerpoCrudo, array $cabeceras): array
    {
        return $this->respuestaVerificarWebhook;
    }
}
