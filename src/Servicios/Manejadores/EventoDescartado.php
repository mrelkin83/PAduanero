<?php

declare(strict_types=1);

namespace App\Servicios\Manejadores;

/**
 * Este evento no se va a poder entregar nunca. No lo reintentes.
 *
 * La distinción importa por la cola, no por el evento: reintentar cinco veces
 * algo imposible retrasa a los que sí podían salir, y esconde el problema
 * real entre reintentos que parecen actividad normal.
 */
final class EventoDescartado extends \RuntimeException
{
}
