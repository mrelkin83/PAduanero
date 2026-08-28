<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Core\Respuesta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RespuestaArchivoTest extends TestCase
{
    #[Test]
    public function archivoTraeElCuerpoYLasCabecerasDeDescarga(): void
    {
        $r = Respuesta::archivo('contenido del archivo', 'plantilla.pdf', 'application/pdf');

        self::assertSame('contenido del archivo', $r->cuerpo);
        self::assertSame(200, $r->estado);
        self::assertSame('application/pdf', $r->cabeceras['Content-Type']);
        self::assertSame('attachment; filename="plantilla.pdf"', $r->cabeceras['Content-Disposition']);
        self::assertSame((string) strlen('contenido del archivo'), $r->cabeceras['Content-Length']);
    }
}
