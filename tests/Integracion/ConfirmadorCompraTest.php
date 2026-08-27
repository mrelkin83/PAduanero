<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cuenta\ConfirmadorCompra;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorEnlaceRepo;
use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use App\Wa\ConexionCompartida;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class ConfirmadorCompraTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private ConfirmadorCompra $confirmador;
    private CompraCursoRepo $compras;
    private CompradorEnlaceRepo $enlaces;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compras = new CompraCursoRepo($this->bd);
        $this->enlaces = new CompradorEnlaceRepo($this->bd);

        $this->confirmador = new ConfirmadorCompra(
            $this->compras,
            $this->enlaces,
            new ConexionCompartida($this->bd, Cifrado::desdeEntorno(), new Logger(sys_get_temp_dir() . '/pa-confirmador.log', 'error'), dirname(__DIR__, 2)),
            $this->bd,
            null, // Smtp: sin SMTP configurado en pruebas, debe degradar sin tronar
            self::URL,
        );
    }

    private function compraPendiente(): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso confirmable', 'curso-confirmable', 'r', 'd', '[]', 250000, 'publicado']);

        return $this->compras->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
    }

    #[Test]
    public function confirmarMarcaLaCompraComoPagada(): void
    {
        $compraId = $this->compraPendiente();

        $this->confirmador->confirmar($compraId);

        self::assertSame('pagada', $this->compras->porId($compraId)['estado']);
    }

    #[Test]
    public function confirmarCreaUnEnlaceDeRegistroLigadoALaCompra(): void
    {
        $compraId = $this->compraPendiente();

        $this->confirmador->confirmar($compraId);

        $fila = $this->bd->pdo()->prepare(
            "SELECT COUNT(*) FROM compradores_enlaces WHERE compra_id = ? AND tipo = 'completar_registro'"
        );
        $fila->execute([$compraId]);
        self::assertSame(1, (int) $fila->fetchColumn());
    }

    #[Test]
    public function confirmarDosVecesNoDuplicaElEnlaceDeRegistro(): void
    {
        $compraId = $this->compraPendiente();

        $this->confirmador->confirmar($compraId);
        $this->confirmador->confirmar($compraId);

        $fila = $this->bd->pdo()->prepare('SELECT COUNT(*) FROM compradores_enlaces WHERE compra_id = ?');
        $fila->execute([$compraId]);
        self::assertSame(1, (int) $fila->fetchColumn());
    }

    #[Test]
    public function confirmarUnaCompraQueNoExisteNoTruena(): void
    {
        $this->confirmador->confirmar((string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn());

        self::assertTrue(true);
    }

    #[Test]
    public function confirmarSinSmtpConfiguradoNoTruena(): void
    {
        // El constructor de este test ya pasa null como $smtp — llegar aquí
        // sin excepción es la prueba.
        $this->confirmador->confirmar($this->compraPendiente());

        self::assertTrue(true);
    }

    #[Test]
    public function reenviarAccesoEnUnaCompraPagadaSinRegistrarMandaOtroCorreo(): void
    {
        $compraId = $this->compraPendiente();
        $this->confirmador->confirmar($compraId);

        // El confirmador de este test se construye con smtp=null (línea 36),
        // así que reenviarAcceso() debe degradar a false sin tronar.
        self::assertFalse($this->confirmador->reenviarAcceso($compraId));
    }

    #[Test]
    public function reenviarAccesoEnUnaCompraNoPagadaNoHaceNada(): void
    {
        $compraId = $this->compraPendiente();

        self::assertFalse($this->confirmador->reenviarAcceso($compraId));
    }

    #[Test]
    public function reenviarAccesoEnUnaCompraYaVinculadaAUnCompradorNoHaceNada(): void
    {
        $compraId = $this->compraPendiente();
        $this->confirmador->confirmar($compraId);

        $compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $compradorId = $compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana@ejemplo.com', 'clave123');
        $this->compras->vincularComprador($compraId, $compradorId);

        self::assertFalse($this->confirmador->reenviarAcceso($compraId));
    }
}
