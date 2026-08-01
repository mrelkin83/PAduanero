<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Servicios\Config;

/**
 * Tablero.
 *
 * Los dos interruptores del motor arriba de todo —«que nunca se olvide
 * encendida o apagada por descuido» (docs/PANEL_ADMIN.md §2.1)— y, desde la
 * Etapa 8, el embudo por canal: costo por lead y conversión a asesoría
 * pagada, que son los dos números que dicen si la pauta paga.
 */
final class TableroControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
        private readonly \App\Servicios\Metricas $metricas,
    ) {
    }

    public function inicio(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'tablero.ver');

        // Últimos 30 días por defecto; el rango se cambia por la URL.
        $hasta = (string) ($ctx->peticion->consulta['hasta'] ?? \App\Soporte\Fechas::hoy());
        $desde = (string) ($ctx->peticion->consulta['desde']
            ?? \App\Soporte\Fechas::ahora()->modify('-30 days')->format('Y-m-d'));

        $reFecha = '/^\d{4}-\d{2}-\d{2}$/';

        if (preg_match($reFecha, $desde) !== 1 || preg_match($reFecha, $hasta) !== 1) {
            $hasta = \App\Soporte\Fechas::hoy();
            $desde = \App\Soporte\Fechas::ahora()->modify('-30 days')->format('Y-m-d');
        }

        return $this->vista('panel/tablero', [
            'ctx' => $ctx,
            'iaPausada' => (bool) $this->config->get('motor_ia_pausado', false),
            'modoSombra' => (bool) $this->config->get('motor_modo_sombra', true),
            'precio' => (int) ($this->bd->pdo()
                ->query('SELECT precio_cop FROM modalidades_asesoria WHERE activo = 1 ORDER BY orden LIMIT 1')
                ->fetchColumn() ?: 0),
            'pasarela' => (string) $this->config->get('pasarela_activa', ''),
            'pendientes' => $this->pendientes(),
            'desde' => $desde,
            'hasta' => $hasta,
            'embudo' => $this->metricas->porCanal($desde, $hasta),
            'landing' => $this->metricas->eventosLanding($desde, $hasta),
            'puedeAnotarInversion' => $ctx->puede('config.editar'),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /** Anota la inversión mensual de un canal, para el costo por lead. */
    public function anotarInversion(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'config.editar');

        try {
            $this->metricas->anotarInversion(
                $ctx->campo('mes'),
                $ctx->campo('canal'),
                (int) $ctx->campo('monto_cop', '0'),
                $ctx->usuario?->id,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirigirCon('/panel', 'error', $e->getMessage());
        }

        return $this->redirigirCon('/panel', 'ok', 'Inversión anotada.');
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
