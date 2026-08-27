<?php

declare(strict_types=1);

namespace App\Wa;

use App\Core\BD;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use ElkinLinan\WhatsappAiEngine\Payments\WompiAdapter;

/**
 * Puente hacia lo que ya existe en `wa_config` para el motor de WhatsApp:
 * el cliente de Wompi y el envío de un WhatsApp suelto. Ni cursos ni su
 * checkout dependen de la conversación del motor — solo de estas dos
 * piezas, que ya son independientes de ella (ver el spec, §1).
 */
final class ConexionCompartida
{
    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
        private readonly Logger $log,
        private readonly string $raiz,
    ) {
    }

    public function wompi(): ?WompiAdapter
    {
        $db = MotorWa::conectar($this->bd, $this->cifrado, $this->log, $this->raiz);

        return WompiAdapter::desdeConfig(WaConfig::cargar($db));
    }

    public function avisarPedro(string $mensaje): void
    {
        $db = MotorWa::conectar($this->bd, $this->cifrado, $this->log, $this->raiz);
        $cfg = WaConfig::cargar($db);

        if ($cfg === null || empty($cfg['handoff_numero']) || empty($cfg['evolution_url'])) {
            $this->log->warn('cursos.aviso_no_enviado', ['razon' => 'wa_config sin numero de guardia o sin Evolution configurado']);

            return;
        }

        $evo = new EvolutionClient(
            (string) $cfg['evolution_url'],
            (string) ($cfg['evolution_instancia'] ?? ''),
            WaConfig::secreto($cfg, 'evolution_apikey'),
        );

        $evo->enviarTexto((string) $cfg['handoff_numero'], $mensaje);
    }
}
