<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;

/**
 * Bitácora. «Quién cambió qué, cuándo y por qué»
 * (docs/PANEL_ADMIN.md §2.8 y §2.9).
 */
final class AuditoriaControlador extends ControladorBase
{
    public function __construct(private readonly AuditoriaRepo $auditoria)
    {
    }

    public function listar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'auditoria.ver');

        $filtros = [
            'entidad' => (string) ($ctx->peticion->consulta['entidad'] ?? ''),
            'accion' => (string) ($ctx->peticion->consulta['accion'] ?? ''),
        ];

        $pagina = max(1, (int) ($ctx->peticion->consulta['pagina'] ?? 1));
        $porPagina = 50;

        return $this->vista('panel/auditoria', [
            'ctx' => $ctx,
            'filtros' => $filtros,
            'entidades' => $this->auditoria->valoresDe('entidad'),
            'acciones' => $this->auditoria->valoresDe('accion'),
            'registros' => $this->auditoria->listar($filtros, $porPagina, ($pagina - 1) * $porPagina),
            'historialConfig' => $this->auditoria->historialConfiguracion(30),
            'pagina' => $pagina,
            'avisos' => $this->avisos($ctx),
        ]);
    }
}
