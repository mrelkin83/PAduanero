<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\SubidaMaterial;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubidaMaterialTest extends TestCase
{
    private string $carpetaTmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpetaTmp = sys_get_temp_dir() . '/pa-materiales-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->carpetaTmp)) {
            array_map('unlink', glob($this->carpetaTmp . '/*') ?: []);
            rmdir($this->carpetaTmp);
        }
        parent::tearDown();
    }

    private function archivoFalso(string $contenido, string $nombre): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pa-mat-');
        file_put_contents($tmp, $contenido);

        return ['name' => $nombre, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contenido)];
    }

    #[Test]
    public function sinArchivoNoEsError(): void
    {
        $resultado = SubidaMaterial::guardar(['error' => UPLOAD_ERR_NO_FILE], $this->carpetaTmp, copy(...));

        self::assertFalse($resultado['ok']);
        self::assertSame('', $resultado['error']);
    }

    #[Test]
    public function unaExtensionNoPermitidaSeRechaza(): void
    {
        $archivo = $this->archivoFalso('contenido', 'virus.exe');

        $resultado = SubidaMaterial::guardar($archivo, $this->carpetaTmp, copy(...));

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('no permitido', $resultado['error']);
    }

    #[Test]
    public function unArchivoMasPesadoQueElLimiteSeRechaza(): void
    {
        $archivo = $this->archivoFalso('contenido', 'grande.pdf');
        $archivo['size'] = 31 * 1024 * 1024;

        $resultado = SubidaMaterial::guardar($archivo, $this->carpetaTmp, copy(...));

        self::assertFalse($resultado['ok']);
        self::assertStringContainsString('30 MB', $resultado['error']);
    }

    #[Test]
    public function unPdfValidoSeGuardaConNombreGenerado(): void
    {
        $archivo = $this->archivoFalso('%PDF-1.4 contenido', 'Plantilla de Solicitud.pdf');

        $resultado = SubidaMaterial::guardar($archivo, $this->carpetaTmp, copy(...));

        self::assertTrue($resultado['ok']);
        self::assertSame('', $resultado['error']);
        self::assertSame('pdf', $resultado['extension']);
        self::assertNotSame('Plantilla de Solicitud.pdf', $resultado['archivo']);
        self::assertFileExists($this->carpetaTmp . '/' . $resultado['archivo'] . '.pdf');
        self::assertSame(strlen('%PDF-1.4 contenido'), $resultado['tamanioBytes']);
    }
}
