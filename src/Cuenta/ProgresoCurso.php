<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\BD;
use App\Repositorios\CertificadoRepo;

/**
 * Registra qué lecciones ya vio cada comprador, y emite el certificado en el
 * mismo momento en que detecta que un curso quedó completo. No hay cron ni
 * cola: la emisión ocurre síncrona, dentro de la misma petición que registra
 * la última vista que faltaba.
 */
final class ProgresoCurso
{
    public function __construct(
        private readonly BD $bd,
        private readonly CertificadoRepo $certificados,
    ) {
    }

    public function registrarVista(string $compradorId, string $leccionId, string $cursoId, string $compraId): void
    {
        $this->bd->pdo()->prepare(
            'INSERT IGNORE INTO curso_progreso (comprador_id, leccion_id) VALUES (?, ?)'
        )->execute([$compradorId, $leccionId]);

        // Un certificado ya emitido nunca se toca de nuevo — ni siquiera se
        // vuelve a evaluar estaCompleto() si porCompra() ya encontró uno.
        if ($this->certificados->porCompra($compraId) !== null) {
            return;
        }

        if ($this->estaCompleto($compradorId, $cursoId)) {
            $this->certificados->crear($compraId, $this->codigoUnico());
        }
    }

    public function estaCompleto(string $compradorId, string $cursoId): bool
    {
        $conteo = $this->conteo($compradorId, $cursoId);

        return $conteo['total'] > 0 && $conteo['vistas'] >= $conteo['total'];
    }

    /** @return array{vistas:int,total:int} */
    public function conteo(string $compradorId, string $cursoId): array
    {
        $stmtTotal = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cm.curso_id = ?'
        );
        $stmtTotal->execute([$cursoId]);
        $total = (int) $stmtTotal->fetchColumn();

        $stmtVistas = $this->bd->pdo()->prepare(
            'SELECT COUNT(DISTINCT cp.leccion_id) FROM curso_progreso cp
               JOIN curso_lecciones cl ON cl.id = cp.leccion_id
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cp.comprador_id = ? AND cm.curso_id = ?'
        );
        $stmtVistas->execute([$compradorId, $cursoId]);
        $vistas = (int) $stmtVistas->fetchColumn();

        return ['vistas' => $vistas, 'total' => $total];
    }

    private function codigoUnico(): string
    {
        do {
            $codigo = 'PA-' . strtoupper(bin2hex(random_bytes(4)));
        } while ($this->certificados->porCodigo($codigo) !== null);

        return $codigo;
    }
}
