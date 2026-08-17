<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Servicios\Config;

/**
 * Tablero.
 *
 * Encogió con el motor. Antes arrancaba con los dos interruptores de la IA
 * —pausa y modo sombra— y seguía con el embudo por canal hasta la asesoría
 * pagada. Sin motor ni pasarela, de ese embudo solo queda el tramo que
 * ocurre dentro de esta aplicación: qué canal trae visitas y cuántas de
 * ellas terminan pulsando el botón de WhatsApp.
 *
 * Lo que pase después de ese clic ya no se mide aquí, y conviene tenerlo
 * presente al leer los números: la conversión que muestra esta pantalla es
 * a conversación iniciada, no a cliente.
 */
final class TableroControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
        private readonly \App\Servicios\MetricasLanding $metricas,
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
            'precio' => (int) ($this->bd->pdo()
                ->query('SELECT precio_cop FROM modalidades_asesoria WHERE activo = 1 ORDER BY orden LIMIT 1')
                ->fetchColumn() ?: 0),
            'pendientes' => $this->pendientes(),
            'desde' => $desde,
            'hasta' => $hasta,
            'canales' => $this->metricas->porCanal($desde, $hasta),
            'inversion' => $this->metricas->inversionPorCanal($desde, $hasta),
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
     * Lo que falta para que la landing pueda salir a producción.
     *
     * La lista era otra: reembolsos, habeas data, WhatsApp de alertas y el
     * widget de Chatwoot, todo lo que bloqueaba el día que un cliente
     * intentara pagar. Sin motor ni pasarela nada de eso aplica —esta
     * aplicación ya no cobra ni persiste datos de un caso—, así que queda lo
     * que sí sigue bloqueando: que la página no salga indexada a medias.
     *
     * @return list<string>
     */
    private function pendientes(): array
    {
        $faltan = [];

        if (trim((string) $this->config->get('whatsapp_numero_negocio', '')) === '') {
            $faltan[] = 'Falta el número de WhatsApp del negocio: los botones de la landing no llevan a ninguna parte.';
        }

        if (!$this->config->get('landing_indexable', false)) {
            $faltan[] = 'La landing está marcada como no indexable: sale con «noindex» y no aparecerá en búsquedas.';
        }

        return $faltan;
    }
}
