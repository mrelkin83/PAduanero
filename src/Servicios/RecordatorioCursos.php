<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Cuenta\ProgresoCurso;
use App\Soporte\PlantillaCorreo;
use App\Soporte\Vista;

/**
 * Recordatorio a quien compró un curso y no lo ha terminado, pasados unos
 * días. Lo dispara el cron bin/recordar-cursos.php; la lógica vive aquí para
 * poder probarla sin el cron.
 *
 * Cada compra se evalúa UNA vez: al recordar —o al comprobar que ya terminó—
 * se sella `recordatorio_en`, así nadie recibe dos recordatorios ni se revisa
 * en cada corrida.
 */
final class RecordatorioCursos
{
    /** Días desde el pago antes de recordar: tiempo razonable para empezar. */
    public const DIAS_ESPERA = 3;

    public function __construct(
        private readonly BD $bd,
        private readonly ProgresoCurso $progreso,
        private readonly ColaCorreos $correos,
        private readonly string $urlBase,
    ) {
    }

    /**
     * Encola recordatorios para las compras que llevan sin terminar más de
     * DIAS_ESPERA. Devuelve cuántos recordatorios se encolaron.
     */
    public function procesar(int $dias = self::DIAS_ESPERA): int
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT cc.id, cc.comprador_id, cc.curso_id, co.correo, co.nombres, cu.titulo, cu.slug
               FROM compras_curso cc
               JOIN compradores co ON co.id = cc.comprador_id
               JOIN cursos cu ON cu.id = cc.curso_id
              WHERE cc.estado = ? AND cc.comprador_id IS NOT NULL
                AND cc.recordatorio_en IS NULL
                AND cc.pagado_en IS NOT NULL
                AND cc.pagado_en <= (NOW() - INTERVAL ? DAY)'
        );
        $stmt->execute(['pagada', max(0, $dias)]);
        $filas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $encolados = 0;
        foreach ($filas as $f) {
            $completo = $this->progreso->estaCompleto((string) $f['comprador_id'], (string) $f['curso_id']);

            // Sella la evaluación pase lo que pase: si ya terminó, no se le
            // molesta; si no, se recuerda una sola vez.
            if (!$completo && ($f['correo'] ?? '') !== '') {
                $this->encolar($f);
                $encolados++;
            }

            $this->bd->pdo()->prepare('UPDATE compras_curso SET recordatorio_en = NOW() WHERE id = ?')
                ->execute([$f['id']]);
        }

        return $encolados;
    }

    /** @param array<string,mixed> $f */
    private function encolar(array $f): void
    {
        $e = Vista::e(...);
        $url = rtrim($this->urlBase, '/') . '/mis-cursos/' . rawurlencode((string) $f['slug']);
        $cuerpo = '<p>Hola ' . $e((string) $f['nombres']) . ',</p>'
            . '<p>Vimos que empezaste el curso <strong>' . $e((string) $f['titulo']) . '</strong> '
            . 'pero aún no lo has terminado. ¡Retómalo cuando quieras — tu certificado te espera al completarlo!</p>';

        $this->correos->encolar(
            (string) $f['correo'],
            'Continúa tu curso: ' . (string) $f['titulo'],
            PlantillaCorreo::envolver('Retoma tu curso', $cuerpo, ['texto' => 'Volver al curso', 'url' => $url]),
            'recordatorio',
        );
    }
}
