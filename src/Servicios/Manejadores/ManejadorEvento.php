<?php

declare(strict_types=1);

namespace App\Servicios\Manejadores;

use App\Modelos\EventoOutbox;

/**
 * Despacha un tipo de evento del outbox.
 *
 * Uno por `eventos_outbox.tipo`, enchufables en el worker. Añadir un efecto
 * nuevo —un correo, un recordatorio, un webhook a un tercero— no debe tocar
 * el worker ni el outbox.
 *
 * Contrato de fallo, y es lo que gobierna la cola:
 *
 *  · **Excepción** → el worker reprograma con el siguiente escalón del
 *    backoff. Para lo transitorio.
 *  · **`EventoDescartado`** → se marca fallido sin más reintentos. Para lo
 *    que va a fallar igual dentro de seis horas: un payload inválido, una
 *    conversación que ya no existe.
 *
 * Sin esa distinción, un evento imposible se reintenta cinco veces y tapa la
 * cola de los que sí podían salir.
 */
interface ManejadorEvento
{
    /**
     * Valores de `eventos_outbox.tipo` que atiende.
     *
     * Lista y no una cadena porque hay manejadores que cubren varios avisos
     * de la misma naturaleza —todo lo que va al WhatsApp de Pedro, por
     * ejemplo— y partirlos en clases distintas solo para tener un tipo por
     * clase duplicaría el transporte sin ganar nada.
     *
     * @return list<string>
     */
    public function tipos(): array;

    /**
     * @throws EventoDescartado si el evento no debe reintentarse
     * @throws \Throwable       para que el worker lo reprograme
     */
    public function manejar(EventoOutbox $evento): void;
}
