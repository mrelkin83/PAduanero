<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Servicios\Config;

/**
 * Tablero.
 *
 * En la Etapa 3 todavía no hay casos ni conversaciones, así que las cifras de
 * embudo se quedan para la Etapa 8. Lo que sí muestra ya, y es lo importante,
 * son los dos interruptores del motor: «que nunca se olvide encendida o
 * apagada por descuido» (docs/PANEL_ADMIN.md §2.1).
 */
final class TableroControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
    ) {
    }

    public function inicio(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'tablero.ver');

        return $this->vista('panel/tablero', [
            'ctx' => $ctx,
            'iaPausada' => (bool) $this->config->get('motor_ia_pausado', false),
            'modoSombra' => (bool) $this->config->get('motor_modo_sombra', true),
            'precio' => (int) ($this->bd->pdo()
                ->query('SELECT precio_cop FROM modalidades_asesoria WHERE activo = 1 ORDER BY orden LIMIT 1')
                ->fetchColumn() ?: 0),
            'pasarela' => (string) $this->config->get('pasarela_activa', ''),
            'pendientes' => $this->pendientes(),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /**
     * Lo que falta para poder cobrar.
     *
     * Se pinta en el tablero porque son las cosas que bloquean la puesta en
     * marcha y que, si no están a la vista, se descubren el día que un
     * cliente intenta pagar.
     *
     * @return list<string>
     */
    private function pendientes(): array
    {
        $faltan = [];

        if (trim((string) $this->config->get('politica_reembolso', '')) === '') {
            $faltan[] = 'La política de reembolso está vacía. Debe redactarse antes de cobrar el primer peso.';
        }

        if (trim((string) $this->config->get('texto_aviso_habeas_data', '')) === '') {
            $faltan[] = 'El aviso de habeas data está vacío. Sin él el motor no puede persistir datos de un caso.';
        }

        if (trim((string) $this->config->get('whatsapp_alertas_abogado', '')) === '') {
            $faltan[] = 'Falta el WhatsApp de alertas internas, y debe ser distinto del número del negocio.';
        }

        if (trim((string) $this->config->get('chatwoot_widget_token', '')) === '') {
            $faltan[] = 'El widget de Chatwoot no está configurado: la landing no lo emite.';
        }

        return $faltan;
    }
}
