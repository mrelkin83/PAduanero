<?php

declare(strict_types=1);

namespace App\Excepciones;

/**
 * El blob no descifra: tag inválido, layout desconocido o clave equivocada.
 *
 * Nunca incluir el valor ni el blob en el mensaje — esta excepción termina
 * en los logs.
 */
final class CifradoException extends \RuntimeException
{
}
