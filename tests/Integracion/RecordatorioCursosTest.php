<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cuenta\ProgresoCurso;
use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Servicios\ColaCorreos;
use App\Servicios\RecordatorioCursos;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * El recordatorio de curso sin terminar: recuerda solo a quien lleva días sin
 * completar, una sola vez, y no molesta a quien ya terminó.
 */
final class RecordatorioCursosTest extends CasoBaseBd
{
    private RecordatorioCursos $servicio;
    private CompraCursoRepo $compras;
    private CompradorRepo $compradores;
    private string $cursoId;
    private string $leccionId1;
    private string $leccionId2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bd->pdo()->exec('DELETE FROM correos_cola');

        $this->compras = new CompraCursoRepo($this->bd);
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $progreso = new ProgresoCurso($this->bd, new CertificadoRepo($this->bd));
        $this->servicio = new RecordatorioCursos($this->bd, $progreso, new ColaCorreos($this->bd), 'https://pedroabogadoaduanero.com');

        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-recordatorio']);
        $this->cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->cursoId, $catId, 'Curso recordatorio', 'curso-recordatorio', 'r', 'd', '[]', 250000, 'publicado']);
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $this->cursoId, 'Módulo', 0]);
        $this->leccionId1 = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->leccionId2 = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$this->leccionId1, $moduloId, 'Lección 1', 0]);
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$this->leccionId2, $moduloId, 'Lección 2', 1]);
    }

    private function compraPagadaHace(int $dias, string $correo): string
    {
        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', $correo, 'clave123');
        $compraId = $this->compras->crear($this->cursoId, 'Ana', $correo, 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);
        // Envejece el pago para superar el umbral de días.
        $this->bd->pdo()->prepare('UPDATE compras_curso SET pagado_en = (NOW() - INTERVAL ? DAY) WHERE id = ?')
            ->execute([$dias, $compraId]);

        return $compraId;
    }

    #[Test]
    public function recuerdaAQuienComproHaceDiasYNoHaTerminado(): void
    {
        $this->compraPagadaHace(5, 'sin-terminar@ejemplo.com');

        $n = $this->servicio->procesar();

        self::assertSame(1, $n);
        self::assertSame(1, (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM correos_cola WHERE tipo = 'recordatorio' AND destinatario = 'sin-terminar@ejemplo.com'"
        )->fetchColumn());
    }

    #[Test]
    public function noRecuerdaDosVecesLaMismaCompra(): void
    {
        $this->compraPagadaHace(5, 'una-vez@ejemplo.com');

        self::assertSame(1, $this->servicio->procesar());
        self::assertSame(0, $this->servicio->procesar()); // segunda corrida: ya sellada
    }

    #[Test]
    public function noRecuerdaAQuienComproHacePoco(): void
    {
        $this->compraPagadaHace(1, 'reciente@ejemplo.com'); // menos que DIAS_ESPERA (3)

        self::assertSame(0, $this->servicio->procesar());
    }

    #[Test]
    public function noRecuerdaAQuienYaTermino(): void
    {
        $compraId = $this->compraPagadaHace(5, 'terminado@ejemplo.com');
        $compradorId = (string) $this->bd->pdo()->query(
            "SELECT comprador_id FROM compras_curso WHERE id = '{$compraId}'"
        )->fetchColumn();
        // Marca ambas lecciones como vistas: curso completo.
        $this->bd->pdo()->prepare('INSERT INTO curso_progreso (comprador_id, leccion_id) VALUES (?, ?), (?, ?)')
            ->execute([$compradorId, $this->leccionId1, $compradorId, $this->leccionId2]);

        $n = $this->servicio->procesar();

        self::assertSame(0, $n);
        // Aun así queda sellada, para no re-evaluarla cada día.
        self::assertNotNull($this->bd->pdo()->query(
            "SELECT recordatorio_en FROM compras_curso WHERE id = '{$compraId}'"
        )->fetchColumn());
    }
}
