<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;

/**
 * Registra los eventos de la landing en `eventos_landing`.
 *
 * Sin cookies y sin IP: el `sesion_hash` lo genera el navegador al azar y se
 * guarda hasheado. No identifica a nadie y no se puede correlacionar con un
 * contacto — que es justo lo que pide `docs/PANEL_ADMIN.md` §5, y lo que
 * evita que este endpoint público se convierta en un registro de visitantes
 * bajo la Ley 1581 de 2012.
 *
 * Lo que sí interesa es la atribución: sin `utm_campaign` en el clic de
 * WhatsApp no hay forma de saber qué campaña de Meta trae clientes que pagan,
 * que es la métrica que decide dónde va el presupuesto.
 */
final class MetricasLanding
{
    /** Cerrada: coincide con el comentario de la columna en el esquema. */
    public const TIPOS = ['vista', 'scroll_50', 'click_whatsapp', 'envio_form'];

    private const MAX_UTM = 100;
    private const MAX_RUTA = 250;

    public function __construct(
        private readonly BD $bd,
        private readonly Config $config,
    ) {
    }

    /**
     * @param  array<string,mixed> $datos payload crudo del navegador
     * @return bool `false` si se descartó (tipo inválido o tope superado)
     */
    public function registrar(array $datos): bool
    {
        $tipo = is_string($datos['tipo'] ?? null) ? $datos['tipo'] : '';

        if (!in_array($tipo, self::TIPOS, true)) {
            return false;
        }

        $sesion = $this->hashSesion($datos['sesion'] ?? null);

        if ($sesion === null || $this->superaElTope($sesion)) {
            return false;
        }

        $stmt = $this->bd->pdo()->prepare(
            'INSERT INTO eventos_landing
               (sesion_hash, tipo, ruta, utm_source, utm_medium, utm_campaign, utm_content, dispositivo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $sesion,
            $tipo,
            $this->recortar($datos['ruta'] ?? null, self::MAX_RUTA),
            $this->recortar($datos['utm_source'] ?? null, self::MAX_UTM),
            $this->recortar($datos['utm_medium'] ?? null, self::MAX_UTM),
            $this->recortar($datos['utm_campaign'] ?? null, self::MAX_UTM),
            $this->recortar($datos['utm_content'] ?? null, self::MAX_UTM),
            $this->dispositivo($datos['dispositivo'] ?? null),
        ]);

        return true;
    }

    /**
     * El navegador manda un identificador aleatorio; aquí se guarda su
     * SHA-256. Si alguien accede a la tabla, no puede volver al valor que
     * tiene el visitante en `sessionStorage`.
     *
     * No lleva pepper a propósito: no hay nada que proteger contra fuerza
     * bruta — el original ya es aleatorio de 128 bits, no un dato personal
     * adivinable como sí lo es un teléfono (ADR-012).
     */
    private function hashSesion(mixed $sesion): ?string
    {
        if (!is_string($sesion) || preg_match('/^[a-f0-9]{32}$/', $sesion) !== 1) {
            return null;
        }

        return hash('sha256', $sesion);
    }

    /**
     * Freno a bucles accidentales del JavaScript, que es el fallo realista:
     * un `scroll` mal desconectado puede meter miles de filas en un minuto.
     *
     * El abuso deliberado no se ataja aquí — quien quiera puede rotar el
     * identificador. Eso es trabajo de `limit_req` en Nginx, en su capa.
     */
    private function superaElTope(string $sesion): bool
    {
        $tope = (int) $this->config->get('landing_eventos_por_sesion', 60);

        $stmt = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM eventos_landing
              WHERE sesion_hash = ? AND creado_en > UTC_TIMESTAMP() - INTERVAL 1 HOUR'
        );
        $stmt->execute([$sesion]);

        return (int) $stmt->fetchColumn() >= $tope;
    }

    private function recortar(mixed $valor, int $maximo): ?string
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }

        // Los UTM vienen de la URL, así que vienen de fuera: se recortan y se
        // limpian de caracteres de control antes de tocar la base.
        $limpio = preg_replace('/[\x00-\x1F\x7F]/u', '', $valor) ?? '';

        return $limpio === '' ? null : mb_substr($limpio, 0, $maximo);
    }

    private function dispositivo(mixed $valor): ?string
    {
        return in_array($valor, ['movil', 'tablet', 'escritorio'], true) ? $valor : null;
    }
}
