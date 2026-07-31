<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\Respuesta;

abstract class ControladorBase
{
    protected function redirigir(string $destino): Respuesta
    {
        return new Respuesta('', 302, ['Location' => $destino]);
    }

    /**
     * Redirección con mensaje.
     *
     * Va por la URL y no por sesión a propósito: el panel no arrastra estado
     * entre peticiones más allá de la sesión de autenticación, y un mensaje
     * en la query no obliga a montar mensajería intermedia. Las plantillas lo
     * escapan.
     */
    protected function redirigirCon(string $destino, string $tipo, string $mensaje): Respuesta
    {
        return $this->redirigir(
            $destino . (str_contains($destino, '?') ? '&' : '?')
            . http_build_query([$tipo => $mensaje])
        );
    }

    /** @param array<string,mixed> $datos */
    protected function vista(string $plantilla, array $datos, int $estado = 200): Respuesta
    {
        return Respuesta::vista($plantilla, $datos, $estado);
    }

    /** Mensajes de la query, ya recortados para que no quepan novelas. */
    protected function avisos(Contexto $ctx): array
    {
        return [
            'ok' => mb_substr((string) ($ctx->peticion->consulta['ok'] ?? ''), 0, 300),
            'error' => mb_substr((string) ($ctx->peticion->consulta['error'] ?? ''), 0, 300),
        ];
    }
}
