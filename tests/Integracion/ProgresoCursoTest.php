<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cuenta\ProgresoCurso;
use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class ProgresoCursoTest extends CasoBaseBd
{
    private ProgresoCurso $progreso;
    private CertificadoRepo $certificados;
    private CompraCursoRepo $compras;
    private CompradorRepo $compradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->certificados = new CertificadoRepo($this->bd);
        $this->progreso = new ProgresoCurso($this->bd, $this->certificados);
        $this->compras = new CompraCursoRepo($this->bd);
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
    }

    /** @return array{compraId:string,compradorId:string,cursoId:string,leccionId1:string,leccionId2:string} */
    private function cursoDeDosLecciones(): array
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-progreso']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso progreso', 'curso-progreso', 'r', 'd', '[]', 250000, 'publicado']);
        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$moduloId, $cursoId, 'Módulo', 0]);
        $leccionId1 = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $leccionId2 = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId1, $moduloId, 'Lección 1', 0]);
        $this->bd->pdo()->prepare('INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)')
            ->execute([$leccionId2, $moduloId, 'Lección 2', 1]);

        $compradorId = $this->compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-progreso@ejemplo.com', 'clave123');
        $compraId = $this->compras->crear($cursoId, 'Ana', 'ana-progreso@ejemplo.com', 250000);
        $this->compras->marcarPagada($compraId);
        $this->compras->vincularComprador($compraId, $compradorId);

        return ['compraId' => $compraId, 'compradorId' => $compradorId, 'cursoId' => $cursoId, 'leccionId1' => $leccionId1, 'leccionId2' => $leccionId2];
    }

    #[Test]
    public function registrarLaMismaVistaDosVecesNoDuplicaLaFila(): void
    {
        $d = $this->cursoDeDosLecciones();

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);
        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);

        $total = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_progreso')->fetchColumn();
        self::assertSame(1, $total);
    }

    #[Test]
    public function conteoReflejaLasLeccionesVistasYElTotalDelCurso(): void
    {
        $d = $this->cursoDeDosLecciones();

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);

        $conteo = $this->progreso->conteo($d['compradorId'], $d['cursoId']);
        self::assertSame(['vistas' => 1, 'total' => 2], $conteo);
    }

    #[Test]
    public function completarLaUltimaLeccionEmiteElCertificadoUnaVez(): void
    {
        $d = $this->cursoDeDosLecciones();

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);
        self::assertFalse($this->progreso->estaCompleto($d['compradorId'], $d['cursoId']));
        self::assertNull($this->certificados->porCompra($d['compraId']));

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId2'], $d['cursoId'], $d['compraId']);

        self::assertTrue($this->progreso->estaCompleto($d['compradorId'], $d['cursoId']));
        $certificado = $this->certificados->porCompra($d['compraId']);
        self::assertNotNull($certificado);
        self::assertMatchesRegularExpression('/^PA-[0-9A-F]{8}$/', $certificado['codigo_verificacion']);
    }

    #[Test]
    public function volverAVerUnaLeccionYaCompletadaNoCambiaElCertificado(): void
    {
        $d = $this->cursoDeDosLecciones();
        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);
        $this->progreso->registrarVista($d['compradorId'], $d['leccionId2'], $d['cursoId'], $d['compraId']);
        $codigoOriginal = $this->certificados->porCompra($d['compraId'])['codigo_verificacion'];

        $this->progreso->registrarVista($d['compradorId'], $d['leccionId1'], $d['cursoId'], $d['compraId']);

        self::assertSame($codigoOriginal, $this->certificados->porCompra($d['compraId'])['codigo_verificacion']);
        $total = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM certificados')->fetchColumn();
        self::assertSame(1, $total);
    }
}
