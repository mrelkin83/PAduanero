<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\Contacto;

/**
 * Pasarela de cobro (docs/CONTRATOS.md §Pagos).
 *
 * Dos invariantes que no dependen de qué pasarela haya detrás:
 *
 *  · **Regla 6.** Una consulta solo pasa a `pagada` por webhook verificado
 *    por firma. Nunca por afirmación del contacto, del LLM, ni de nadie que
 *    escriba en un chat. Por eso `verificarWebhook()` existe separado de
 *    `procesarWebhook()`: la verificación es pura y se puede probar sin
 *    tocar la base.
 *
 *  · **ADR-010.** `crearLink()` es el único punto del sistema donde los
 *    pesos se convierten a centavos, y `pagos.monto_centavos` la única
 *    columna en centavos. Un error de factor 100 aquí le cobra $40.000.000
 *    a un cliente o $4.000 a Pedro.
 */
interface Pagos
{
    /**
     * Crea el enlace de pago de una consulta reservada.
     *
     * Recibe PESOS y convierte a centavos adentro: quien llama nunca
     * multiplica por 100.
     *
     * @return array{url:string,referencia:string,pagoId:string,expiraEn:\DateTimeImmutable}
     */
    public function crearLink(
        string $consultaId,
        int $montoPesos,
        string $descripcion,
        Contacto $contacto,
    ): array;

    /**
     * Valida la firma contra el CUERPO CRUDO, no contra el JSON parseado.
     *
     * NO toca la base de datos: solo dice si la firma cuadra y qué anuncia
     * el evento.
     *
     * @param  array<string,string> $cabeceras
     * @return array{valido:bool,referencia:string,estado:string}
     */
    public function verificarWebhook(string $cuerpoCrudo, array $cabeceras): array;

    /**
     * El webhook completo: verifica y, SOLO si la firma valida, registra el
     * pago y confirma la consulta. Si no valida, no escribe nada en ninguna
     * tabla.
     *
     * Idempotente por `pagos.referencia`: el mismo evento dos veces
     * confirma una.
     *
     * @param  array<string,string> $cabeceras
     * @return array{valido:bool,procesado:bool,referencia:string,estado:string}
     */
    public function procesarWebhook(string $cuerpoCrudo, array $cabeceras): array;

    /**
     * Estado real en la pasarela, consultado a su API.
     *
     * Para conciliación manual: cuando un contacto dice «ya pagué» y el
     * webhook no ha llegado, esto responde con la verdad de la pasarela sin
     * violar la regla 6 — confirmar sigue siendo trabajo del webhook o de
     * una persona mirando esto.
     *
     * @return array{encontrado:bool,estado:string,monto_centavos:int}
     */
    public function consultarEstado(string $referencia): array;
}
