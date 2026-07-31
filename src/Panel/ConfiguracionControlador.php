<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\Respuesta;
use App\Servicios\Config;

/**
 * Configuración general.
 *
 * El formulario se genera desde los metadatos de las propias filas —etiqueta,
 * ayuda, tipo, mínimo, máximo, opciones—, no desde código. Añadir un
 * parámetro nuevo es un INSERT (CLAUDE.md §9), y esta pantalla lo pinta sola.
 */
final class ConfiguracionControlador extends ControladorBase
{
    private const GRUPOS = [
        'motor' => 'Motor conversacional',
        'agenda' => 'Agenda',
        'pagos' => 'Pagos',
        'ia' => 'Inteligencia artificial',
        'legal' => 'Legal',
        'notificaciones' => 'Notificaciones',
        'landing' => 'Landing',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    public function listar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'config.ver');

        $grupos = [];
        foreach (self::GRUPOS as $clave => $titulo) {
            $filas = $this->config->getGrupo($clave);

            if ($filas !== []) {
                $grupos[$clave] = ['titulo' => $titulo, 'filas' => $filas];
            }
        }

        return $this->vista('panel/configuracion', [
            'ctx' => $ctx,
            'grupos' => $grupos,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'config.editar');

        $clave = $ctx->campo('clave');
        $motivo = $ctx->campo('motivo') !== '' ? $ctx->campo('motivo') : null;

        $fila = $this->buscar($clave);

        if ($fila === null) {
            return $this->redirigirCon('/panel/configuracion', 'error', 'Esa configuración no existe.');
        }

        // El «✔ (parcial)» del abogado: puede entrar al módulo, pero las
        // filas marcadas `super_admin` —las que apuntan a credenciales y
        // proveedores— no las toca.
        if (!$ctx->permisos->puedeEditarConfiguracion($ctx->usuario, $fila->rolMinimo)) {
            return $this->redirigirCon(
                '/panel/configuracion',
                'error',
                "«{$fila->etiqueta}» solo la puede cambiar un administrador técnico.",
            );
        }

        try {
            $this->config->set($clave, $this->valorEnviado($ctx, $fila->tipo), $ctx->usuario->id, $motivo);
        } catch (\InvalidArgumentException $e) {
            // El mensaje de la validación viene de la propia fila y está
            // escrito para leerse: se muestra tal cual.
            return $this->redirigirCon('/panel/configuracion', 'error', $e->getMessage());
        }

        return $this->redirigirCon(
            '/panel/configuracion',
            'ok',
            "«{$fila->etiqueta}» actualizada."
                . ($fila->requiereReinicio ? ' Requiere reiniciar el worker.' : ''),
        );
    }

    /** El navegador manda todo como texto; el tipo lo dicta la fila. */
    private function valorEnviado(Contexto $ctx, string $tipo): mixed
    {
        $crudo = $ctx->campo('valor');

        return match ($tipo) {
            'booleano' => $ctx->campo('valor', '0') === '1',
            'entero' => $crudo,
            'decimal' => $crudo,
            'json' => json_decode($crudo, true) ?? $crudo,
            default => $crudo,
        };
    }

    private function buscar(string $clave): ?\App\Modelos\Configuracion
    {
        foreach (array_keys(self::GRUPOS) as $grupo) {
            foreach ($this->config->getGrupo($grupo) as $fila) {
                if ($fila->clave === $clave) {
                    return $fila;
                }
            }
        }

        return null;
    }
}
