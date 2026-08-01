<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;

/**
 * Métricas del embudo por canal (Etapa 8).
 *
 * El canal de un contacto es su `utm_source` si la campaña lo trajo, o su
 * `canal_origen` si llegó directo. La atribución es de primer contacto y no
 * se recalcula: el UTM se congela al crear el contacto, que es como está
 * instrumentada la landing desde la Etapa 1.
 *
 * Todo sale de consultas agregadas sobre las tablas del motor — aquí no hay
 * contadores propios que se puedan desincronizar de la realidad. Si el
 * número del tablero no cuadra con la base, es que la consulta está mal,
 * no que un contador se quedó viejo; esa propiedad vale más que el
 * microsegundo que costaría cachearlo.
 */
final class Metricas
{
    public function __construct(private readonly BD $bd)
    {
    }

    /**
     * El embudo de un rango de fechas, por canal.
     *
     * `costo_por_lead` y `conversion` son NULL cuando el denominador es
     * cero: un canal sin inversión anotada no tiene costo por lead, y
     * pintarlo como $0 diría que los leads salieron gratis — un cero
     * creíble y falso, la clase de fallo que más caro cuesta detectar.
     *
     * @return list<array{
     *   canal:string, leads:int, casos:int, pagadas:int,
     *   ingresos_cop:int, inversion_cop:int,
     *   costo_por_lead_cop:?float, conversion:?float
     * }>
     */
    public function porCanal(string $desde, string $hasta): array
    {
        // Leads y casos por canal. COALESCE: la campaña manda, el canal de
        // origen respalda.
        $stmt = $this->bd->pdo()->prepare(
            "SELECT COALESCE(NULLIF(c.utm_source, ''), c.canal_origen) AS canal,
                    COUNT(DISTINCT c.id)  AS leads,
                    COUNT(DISTINCT k.id)  AS casos,
                    COUNT(DISTINCT IF(q.estado IN ('pagada','realizada'), q.id, NULL)) AS pagadas,
                    COALESCE(SUM(IF(p.estado = 'aprobado', p.monto_centavos, 0)), 0) AS ingresos_centavos
               FROM contactos c
               LEFT JOIN casos k     ON k.contacto_id = c.id
               LEFT JOIN consultas q ON q.contacto_id = c.id
               LEFT JOIN pagos p     ON p.consulta_id = q.id
              WHERE c.creado_en >= ? AND c.creado_en < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY canal
              ORDER BY leads DESC"
        );
        $stmt->execute([$desde, $hasta]);

        $inversion = $this->inversionPorCanal($desde, $hasta);
        $filas = [];

        foreach ($stmt->fetchAll() as $fila) {
            $canal = (string) $fila['canal'];
            $leads = (int) $fila['leads'];
            $pagadas = (int) $fila['pagadas'];
            $invertido = $inversion[$canal] ?? 0;

            $filas[] = [
                'canal' => $canal,
                'leads' => $leads,
                'casos' => (int) $fila['casos'],
                'pagadas' => $pagadas,
                // Centavos → pesos SOLO para presentar. La única columna en
                // centavos sigue siendo pagos.monto_centavos (ADR-010).
                'ingresos_cop' => intdiv((int) $fila['ingresos_centavos'], 100),
                'inversion_cop' => $invertido,
                'costo_por_lead_cop' => ($invertido > 0 && $leads > 0)
                    ? round($invertido / $leads, 2)
                    : null,
                'conversion' => $leads > 0 ? round($pagadas / $leads, 4) : null,
            ];
        }

        return $filas;
    }

    /**
     * Los eventos de la landing en el rango: cuánta gente vio y cuánta hizo
     * clic al WhatsApp, por canal. Es el techo del embudo, antes de que
     * exista contacto.
     *
     * @return list<array{canal:string,tipo:string,eventos:int}>
     */
    public function eventosLanding(string $desde, string $hasta): array
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

    /** Anota (o corrige) la inversión de un canal en un mes. */
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
    private function inversionPorCanal(string $desde, string $hasta): array
    {
        // La inversión es mensual; el rango puede cortar meses. Se prorratea
        // NO: se suma el mes completo si su primer día cae en el rango
        // ampliado a mes. Prorratear inventaría precisión que el dato manual
        // no tiene.
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
