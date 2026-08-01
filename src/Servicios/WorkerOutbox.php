<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Servicios\Manejadores\EventoDescartado;
use App\Servicios\Manejadores\ManejadorEvento;
use App\Soporte\Logger;

/**
 * Saca los eventos de la cola y los despacha.
 *
 * Está en `Servicios/` y no dentro de `bin/` para que se pueda probar sin
 * lanzar un proceso: `bin/worker-outbox.php` es cuatro líneas que construyen
 * esto y llaman a `pasada()`.
 *
 * Lo que **no** hace y es deliberado: no se queda en un bucle infinito. Lo
 * relanza el cron cada minuto. Un demonio propio tendría que resolver por su
 * cuenta el reinicio tras un fallo, la rotación de logs y la fuga de memoria
 * de un proceso PHP de días; el cron ya resuelve las tres.
 */
final class WorkerOutbox
{
    /** @var array<string,ManejadorEvento> por tipo */
    private array $manejadores = [];

    /** @param iterable<ManejadorEvento> $manejadores */
    public function __construct(
        private readonly Outbox $outbox,
        private readonly Logger $log,
        iterable $manejadores,
    ) {
        foreach ($manejadores as $manejador) {
            foreach ($manejador->tipos() as $tipo) {
                $this->manejadores[$tipo] = $manejador;
            }
        }
    }

    /**
     * Una pasada completa.
     *
     * @return array{recuperados:int,despachados:int,reprogramados:int,descartados:int}
     */
    public function pasada(int $limite = 20, int $minutosAtasco = 15): array
    {
        // Primero lo atascado: un worker que murió a mitad dejó eventos en
        // `procesando` que nadie va a tocar. Si esto no corriera antes de
        // tomar, seguirían ahí mientras la cola parece sana.
        $recuperados = $this->outbox->recuperarAtascados($minutosAtasco);

        if ($recuperados > 0) {
            $this->log->warn('outbox.recuperados', ['n' => $recuperados]);
        }

        $despachados = 0;
        $reprogramados = 0;
        $descartados = 0;

        foreach ($this->outbox->tomar($limite) as $evento) {
            $manejador = $this->manejadores[$evento->tipo] ?? null;

            if ($manejador === null) {
                // Un tipo sin manejador no es transitorio: reintentarlo cinco
                // veces no va a hacer que aparezca la clase que falta.
                $this->outbox->marcarFallido($evento->id, "Sin manejador para «{$evento->tipo}»");
                $this->log->error('outbox.sin_manejador', ['tipo' => $evento->tipo]);
                $descartados++;

                continue;
            }

            try {
                $manejador->manejar($evento);
                $this->outbox->marcarEnviado($evento->id);
                $despachados++;
            } catch (EventoDescartado $e) {
                $this->outbox->marcarFallido($evento->id, $e->getMessage());
                $this->log->warn('outbox.descartado', [
                    'id' => $evento->id,
                    'tipo' => $evento->tipo,
                    'motivo' => $e->getMessage(),
                ]);
                $descartados++;
            } catch (\Throwable $e) {
                $this->outbox->reprogramar($evento->id, $e->getMessage());
                $this->log->warn('outbox.reprogramado', [
                    'id' => $evento->id,
                    'tipo' => $evento->tipo,
                    'intento' => $evento->intentos,
                    'motivo' => $e->getMessage(),
                ]);
                $reprogramados++;
            }
        }

        return [
            'recuperados' => $recuperados,
            'despachados' => $despachados,
            'reprogramados' => $reprogramados,
            'descartados' => $descartados,
        ];
    }
}
