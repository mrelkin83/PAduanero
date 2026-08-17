<?php

declare(strict_types=1);

namespace App\Excepciones;

/**
 * La aplicación no puede arrancar. No se atrapa: se deja subir hasta el
 * front controller, que responde 500 sin decir por qué.
 *
 * Arrancar sin MASTER_KEY significaría escribir datos
 * que después nadie podrá volver a leer. Es preferible no arrancar.
 */
final class ConfiguracionFatalException extends \RuntimeException
{
}
