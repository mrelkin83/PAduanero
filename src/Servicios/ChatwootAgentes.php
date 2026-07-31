<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Soporte\Http;
use App\Soporte\Logger;

/**
 * Alta de agentes en Chatwoot desde el panel.
 *
 * **Alcance deliberadamente estrecho.** Esto NO es `App\Servicios\Chatwoot`,
 * el contrato conversacional de `docs/CONTRATOS.md` —responder, notas
 * privadas, etiquetas, prioridad—, que se implementa en la Etapa 4. Aquí solo
 * está lo que la Etapa 3 necesita: que crear un usuario del panel cree
 * también su agente, y no haya que darlo de alta dos veces
 * (docs/PANEL_ADMIN.md §2.9).
 *
 * Cuando llegue la Etapa 4, ambos comparten `App\Soporte\Http` y no habrá
 * transporte duplicado.
 */
final class ChatwootAgentes
{
    public function __construct(
        private readonly Http $http,
        private readonly Logger $log,
        private readonly string $url,
        private readonly string $cuentaId,
        private readonly string $token,
    ) {
    }

    public function configurado(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    /**
     * @param  string $rol `agent` o `administrator` en Chatwoot
     * @return int|null    id del agente, o null si no se pudo
     */
    public function crearAgente(string $email, string $nombre, string $rol = 'agent'): ?int
    {
        if (!$this->configurado()) {
            return null;
        }

        $r = $this->http->pedir(
            'POST',
            "{$this->url}/api/v1/accounts/{$this->cuentaId}/agents",
            ['api_access_token' => $this->token],
            ['name' => $nombre, 'email' => $email, 'role' => $rol],
        );

        if ($r->ok()) {
            $id = $r->json()['id'] ?? null;

            return is_int($id) ? $id : null;
        }

        // El caso frecuente: el agente ya existe de un alta anterior. No es
        // un error que deba alarmar, pero sí quedar registrado.
        $this->log->warn('chatwoot.alta_agente_fallida', [
            'estado' => $r->estado,
            'error_red' => $r->errorRed,
        ]);

        return null;
    }

    /** Para el verificador de canales y el tablero. */
    public function responde(): bool
    {
        if (!$this->configurado()) {
            return false;
        }

        return $this->http->pedir(
            'GET',
            "{$this->url}/api/v1/accounts/{$this->cuentaId}/inboxes",
            ['api_access_token' => $this->token],
        )->ok();
    }
}
