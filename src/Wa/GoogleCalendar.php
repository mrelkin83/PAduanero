<?php

declare(strict_types=1);

namespace App\Wa;

use App\Core\BD;
use App\Soporte\Cifrado;
use App\Soporte\Logger;

/**
 * Google Calendar del abogado, con curl y sin SDK: mismo criterio que el
 * resto del sistema — cero dependencias de producción.
 *
 * Autenticación: OAuth de la CUENTA de Pedro. Se guarda el refresh token
 * cifrado (ADR-011, envuelto en base64) en `wa_google` y de él se derivan
 * access tokens de una hora. Se eligió sobre una cuenta de servicio porque
 * con Gmail personal solo así los eventos salen a nombre del abogado y con
 * enlace de Meet.
 *
 * Tolerancia a fallos, decidida y no accidental: si Google no responde, la
 * DISPONIBILIDAD cae al calendario local (wa_citas + horario del panel) y se
 * deja constancia en el log. Preferimos el riesgo de proponer una hora que
 * Pedro tiene ocupada por fuera —la invitación le llega y puede moverla— al
 * de un bot que no puede agendar porque Google tuvo un mal minuto.
 */
final class GoogleCalendar
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const API = 'https://www.googleapis.com/calendar/v3';
    private const SCOPE = 'https://www.googleapis.com/auth/calendar';
    private const ZONA = 'America/Bogota';

    private ?string $accessToken = null;
    private ?array $fila = null;

    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
        private readonly Logger $log,
    ) {
    }

    private function fila(): array
    {
        if ($this->fila === null) {
            $st = $this->bd->pdo()->prepare('SELECT * FROM wa_google WHERE id = 1');
            $st->execute();
            $this->fila = $st->fetch() ?: [];
        }

        return $this->fila;
    }

    public function conectado(): bool
    {
        $f = $this->fila();

        return !empty($f['client_id']) && !empty($f['refresh_token_cifrado']);
    }

    /* ── Flujo de autorización (lo consume el panel / bin) ────────────── */

    public function urlAutorizacion(string $redirectUri): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => (string) ($this->fila()['client_id'] ?? ''),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            // Sin esto Google solo entrega refresh_token la primera vez y
            // una reconexión posterior quedaría sin token en silencio.
            'prompt' => 'consent',
        ]);
    }

    public function guardarCliente(string $clientId, string $clientSecret): void
    {
        $st = $this->bd->pdo()->prepare(
            'UPDATE wa_google SET client_id = ?, client_secret_cifrado = ? WHERE id = 1'
        );
        $st->execute([$clientId, base64_encode($this->cifrado->cifrar($clientSecret))]);
        $this->fila = null;
    }

    /** Canjea el código del flujo OAuth y deja guardado el refresh token. */
    public function canjearCodigo(string $codigo, string $redirectUri): bool
    {
        $f = $this->fila();
        $r = $this->http('POST', self::TOKEN_URL, http_build_query([
            'code' => $codigo,
            'client_id' => (string) ($f['client_id'] ?? ''),
            'client_secret' => $this->secreto('client_secret_cifrado'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]), 'application/x-www-form-urlencoded');

        if (empty($r['refresh_token'])) {
            $this->log->error('wa.google_canje_fallido', ['respuesta' => array_keys($r)]);

            return false;
        }

        $correo = null;
        if (!empty($r['access_token'])) {
            $this->accessToken = (string) $r['access_token'];
            $info = $this->http('GET', 'https://www.googleapis.com/oauth2/v2/userinfo', null, null, $this->accessToken);
            $correo = $info['email'] ?? null;
        }

        $st = $this->bd->pdo()->prepare(
            'UPDATE wa_google SET refresh_token_cifrado = ?, correo_cuenta = ?, conectado_en = NOW() WHERE id = 1'
        );
        $st->execute([base64_encode($this->cifrado->cifrar((string) $r['refresh_token'])), $correo]);
        $this->fila = null;

        return true;
    }

    /* ── Operación ────────────────────────────────────────────────────── */

    /**
     * Rangos ocupados entre dos instantes locales 'Y-m-d H:i'.
     *
     * @return array{ok:bool,ocupado:array<array{desde:int,hasta:int}>} epochs
     */
    public function ocupado(string $desde, string $hasta): array
    {
        if (!$this->conectado()) {
            return ['ok' => false, 'ocupado' => []];
        }

        $token = $this->token();
        if ($token === null) {
            return ['ok' => false, 'ocupado' => []];
        }

        $r = $this->http('POST', self::API . '/freeBusy', json_encode([
            'timeMin' => $this->rfc3339($desde),
            'timeMax' => $this->rfc3339($hasta),
            'timeZone' => self::ZONA,
            'items' => [['id' => $this->calendario()]],
        ]), 'application/json', $token);

        $busy = $r['calendars'][$this->calendario()]['busy'] ?? null;
        if (!is_array($busy)) {
            $this->log->warn('wa.google_freebusy_fallido', ['claves' => array_keys($r)]);

            return ['ok' => false, 'ocupado' => []];
        }

        $rangos = [];
        foreach ($busy as $b) {
            $d = strtotime((string) ($b['start'] ?? ''));
            $h = strtotime((string) ($b['end'] ?? ''));
            if ($d !== false && $h !== false) {
                $rangos[] = ['desde' => $d, 'hasta' => $h];
            }
        }

        return ['ok' => true, 'ocupado' => $rangos];
    }

    /**
     * Crea el evento de la cita, con Meet e invitación al correo del cliente.
     *
     * @return array{ok:bool,event_id:?string,meet:?string}
     */
    public function crearEvento(array $cita): array
    {
        if (!$this->conectado()) {
            return ['ok' => false, 'event_id' => null, 'meet' => null];
        }
        $token = $this->token();
        if ($token === null) {
            return ['ok' => false, 'event_id' => null, 'meet' => null];
        }

        $inicio = (string) $cita['inicio'];
        $fin = date('Y-m-d H:i', strtotime($inicio) + 60 * (int) ($cita['duracion_min'] ?? 60));

        $cuerpo = [
            'summary' => 'Asesoría — ' . ($cita['nombre'] ?? 'cliente'),
            'description' => trim(
                'Agendada por el asistente de WhatsApp.'
                . "\nTeléfono: " . ($cita['telefono'] ?? '')
                . (empty($cita['motivo']) ? '' : "\nMotivo: " . $cita['motivo'])
            ),
            'start' => ['dateTime' => $this->rfc3339($inicio), 'timeZone' => self::ZONA],
            'end' => ['dateTime' => $this->rfc3339($fin), 'timeZone' => self::ZONA],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => 'pa-cita-' . ($cita['id'] ?? uniqid()),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];
        if (!empty($cita['correo'])) {
            $cuerpo['attendees'] = [['email' => (string) $cita['correo']]];
        }

        $r = $this->http(
            'POST',
            self::API . '/calendars/' . rawurlencode($this->calendario())
                . '/events?conferenceDataVersion=1&sendUpdates=all',
            json_encode($cuerpo),
            'application/json',
            $token,
        );

        if (empty($r['id'])) {
            // El detalle del error, no las claves: un 400 «Bad Request» pelado
            // costó una noche de diagnóstico porque aquí solo quedaba la forma
            // de la respuesta.
            $this->log->error('wa.google_evento_fallido', [
                'codigo' => $r['error']['code'] ?? null,
                'detalle' => $r['error']['message'] ?? 'sin detalle',
            ]);

            return ['ok' => false, 'event_id' => null, 'meet' => null];
        }

        $meet = $r['hangoutLink'] ?? null;
        foreach (($r['conferenceData']['entryPoints'] ?? []) as $ep) {
            if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) {
                $meet = (string) $ep['uri'];
            }
        }

        return ['ok' => true, 'event_id' => (string) $r['id'], 'meet' => $meet];
    }

    public function cancelarEvento(string $eventId): void
    {
        if ($eventId === '' || !$this->conectado()) {
            return;
        }
        $token = $this->token();
        if ($token === null) {
            return;
        }
        $this->http(
            'DELETE',
            self::API . '/calendars/' . rawurlencode($this->calendario())
                . '/events/' . rawurlencode($eventId) . '?sendUpdates=all',
            null,
            null,
            $token,
        );
    }

    /* ── Interno ──────────────────────────────────────────────────────── */

    private function calendario(): string
    {
        return (string) ($this->fila()['calendar_id'] ?? 'primary') ?: 'primary';
    }

    private function secreto(string $columna): string
    {
        $v = (string) ($this->fila()[$columna] ?? '');
        if ($v === '') {
            return '';
        }
        $blob = base64_decode($v, true);
        if ($blob === false) {
            return '';
        }

        try {
            return $this->cifrado->descifrar($blob);
        } catch (\Throwable) {
            return '';
        }
    }

    private function token(): ?string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $f = $this->fila();
        $r = $this->http('POST', self::TOKEN_URL, http_build_query([
            'client_id' => (string) ($f['client_id'] ?? ''),
            'client_secret' => $this->secreto('client_secret_cifrado'),
            'refresh_token' => $this->secreto('refresh_token_cifrado'),
            'grant_type' => 'refresh_token',
        ]), 'application/x-www-form-urlencoded');

        if (empty($r['access_token'])) {
            $this->log->error('wa.google_token_fallido', ['error' => $r['error'] ?? 'sin detalle']);

            return null;
        }

        return $this->accessToken = (string) $r['access_token'];
    }

    /**
     * Hora local → RFC3339 con el desfase de Bogotá (fijo, sin DST).
     *
     * Acepta 'Y-m-d H:i' Y 'Y-m-d H:i:s': el free/busy manda la primera,
     * pero `crearEvento` recibe el `inicio` tal cual sale de la base — CON
     * segundos. La versión anterior concatenaba ':00' a ciegas y producía
     * '09:00:00:00', que Google rechazaba con un 400 pelado: ningún evento
     * de calendario llegó a crearse hasta que un pago aprobado lo delató.
     */
    private function rfc3339(string $local): string
    {
        $t = strtotime($local);
        if ($t === false) {
            return str_replace(' ', 'T', $local) . '-05:00';
        }

        return date('Y-m-d\TH:i:s', $t) . '-05:00';
    }

    private function http(string $metodo, string $url, ?string $cuerpo, ?string $tipo, ?string $token = null): array
    {
        $ch = curl_init($url);
        $cab = [];
        if ($tipo !== null) {
            $cab[] = 'Content-Type: ' . $tipo;
        }
        if ($token !== null) {
            $cab[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => $cab,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }

        $res = curl_exec($ch);
        curl_close($ch);

        if (!is_string($res) || $res === '') {
            return [];
        }
        $json = json_decode($res, true);

        return is_array($json) ? $json : [];
    }
}
