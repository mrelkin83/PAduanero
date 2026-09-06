<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Lo que la cola de correos necesita de un enviador. Lo implementa Smtp; la
 * interfaz existe para que la cola no dependa de esa clase concreta (y para
 * poder doblarla en pruebas, ya que Smtp es final).
 */
interface EnviadorCorreo
{
    public function enviarHtml(string $para, string $asunto, string $html, ?string $texto = null): bool;
}
