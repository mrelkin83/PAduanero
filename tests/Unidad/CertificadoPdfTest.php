<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Cuenta\CertificadoPdf;
use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CertificadoPdfTest extends CasoBaseBd
{
    #[Test]
    public function generarProduceUnPdfNoVacio(): void
    {
        $compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
        $compradorId = $compradores->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-pdf@ejemplo.com', 'clave123');

        $pdf = new CertificadoPdf($compradores);
        $bytes = $pdf->generar($compradorId, 'Clasificación arancelaria', 'PA-ABCD1234', '2026-08-29');

        self::assertGreaterThan(1000, strlen($bytes));
        self::assertStringStartsWith('%PDF-', $bytes);
    }
}
