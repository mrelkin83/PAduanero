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
    /**
     * Cerrada: coincide con el comentario de la columna en el esquema.
     *
     * Los tres `perfil_*` son el embudo del diagnóstico. `perfil_paso`
     * guarda en `ruta` en qué paso ocurrió —`/perfil/antiguedad`—, que es lo
     * único que permite ver dónde abandona la gente el cuestionario; sin
     * eso, un diagnóstico que nadie termina se ve igual que uno que nadie
     * empieza.
     */
    public const TIPOS = [
        'vista', 'scroll_50', 'click_whatsapp', 'envio_form',
        'perfil_inicio', 'perfil_paso', 'perfil_resultado',
    ];

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

    // ── Lectura para el tablero ─────────────────────────────────────────
    //
    // Estos dos venían de `App\Servicios\Metricas`, que se retiró con el
    // motor. Aquella clase cruzaba `contactos`, `casos`, `consultas` y
    // `pagos` para dar el embudo por canal; sin motor esas tablas no vuelven
    // a recibir una fila, así que el embudo desapareció con ellas.
    //
    // Lo que quedó vivo son estos dos, y se mudan aquí en vez de dejar media
    // clase en pie: `eventos_landing` ya tenía dueña —esta— y tener dos
    // clases escribiendo y leyendo la misma tabla es como se separan los
    // criterios de qué cuenta como evento.

    /**
     * Los eventos de la landing en el rango, por canal y tipo: cuánta gente
     * vio, cuánta llegó a la mitad y cuánta hizo clic al WhatsApp.
     *
     * Antes esto era «el techo del embudo». Ahora es el embudo entero: lo
     * que pase después de ese clic ocurre dentro de WhatsApp, donde este
     * sistema ya no mira.
     *
     * @return list<array{canal:string,tipo:string,eventos:int}>
     */
    public function porCanal(string $desde, string $hasta): array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT COALESCE(NULLIF(utm_source, ''), 'directo') AS canal,
                    tipo,
                    COUNT(*) AS eventos
               FROM eventos_landing
              WHERE creado_en >= ? AND creado_en < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY canal, tipo
              ORDER BY canal, tipo"
        );
        $stmt->execute([$desde, $hasta]);

        return array_map(
            static fn (array $f): array => [
                'canal' => (string) $f['canal'],
                'tipo' => (string) $f['tipo'],
                'eventos' => (int) $f['eventos'],
            ],
            $stmt->fetchAll(),
        );
    }

    /**
     * Anota (o corrige) la inversión publicitaria de un canal en un mes.
     *
     * Sigue sirviendo sin motor: cruzada con `porCanal` da el costo por clic
     * a WhatsApp, que es lo más cerca de un costo por lead que este sistema
     * puede medir ya. Lo que no puede decir es cuántos de esos clics
     * terminaron en un caso — eso vive en WhatsApp.
     */
    public function anotarInversion(string $mes, string $canal, int $montoCop, ?string $usuarioId): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $mes) !== 1) {
            throw new \InvalidArgumentException('El mes va como AAAA-MM.');
        }

        if ($montoCop < 0) {
            throw new \InvalidArgumentException('La inversión no puede ser negativa.');
        }

        $this->bd->pdo()->prepare(
            'INSERT INTO inversion_canales (mes, canal, monto_cop, anotado_por)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE monto_cop = VALUES(monto_cop), anotado_por = VALUES(anotado_por)'
        )->execute([$mes . '-01', mb_strtolower(trim($canal)), $montoCop, $usuarioId]);
    }

    /** @return array<string,int> canal → pesos invertidos en el rango */
    public function inversionPorCanal(string $desde, string $hasta): array
    {
        // La inversión es mensual y el rango puede cortar meses. No se
        // prorratea: se suma el mes completo. Prorratear inventaría una
        // precisión que un dato tecleado a mano no tiene.
        $stmt = $this->bd->pdo()->prepare(
            "SELECT canal, SUM(monto_cop) AS monto
               FROM inversion_canales
              WHERE mes >= DATE_FORMAT(?, '%Y-%m-01')
                AND mes <= DATE_FORMAT(?, '%Y-%m-01')
              GROUP BY canal"
        );
        $stmt->execute([$desde, $hasta]);

        $resultado = [];

        foreach ($stmt->fetchAll() as $fila) {
            $resultado[(string) $fila['canal']] = (int) $fila['monto'];
        }

        return $resultado;
    }
}
