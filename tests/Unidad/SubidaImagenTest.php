<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\SubidaImagen;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `SubidaImagen` es la única puerta por la que un archivo que llega del
 * navegador termina en `public/img/`. No confía en el nombre ni la extensión
 * que manda el cliente: estas pruebas defienden justo eso — que un archivo
 * que no es una imagen real, o que pesa de más, nunca llegue a escribirse,
 * sin importar cómo venga disfrazado.
 *
 * `move_uploaded_file` siempre falla fuera de una petición HTTP real, así
 * que aquí se inyecta `copy(...)` como el «mover» — es el punto de prueba
 * que la propia clase deja para esto (ver su docblock).
 */
final class SubidaImagenTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/subida_imagen_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->carpeta)) {
            foreach (glob($this->carpeta . '/*') ?: [] as $archivo) {
                @unlink($archivo);
            }
            @rmdir($this->carpeta);
        }
    }

    private function archivoTemporal(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'su');
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    private function jpegDeUnPixel(): string
    {
        // El JPEG válido más pequeño posible: un pixel, generado con GD.
        $img = imagecreatetruecolor(1, 1);
        ob_start();
        imagejpeg($img);
        $bin = (string) ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    #[Test]
    public function sinArchivoNoEsUnErrorYNoHaceNada(): void
    {
        $r = SubidaImagen::guardar([], $this->carpeta, 'curso', copy(...));

        self::assertFalse($r['ok']);
        self::assertSame('', $r['error']);
        self::assertSame('', $r['nombre']);
    }

    #[Test]
    public function unaImagenValidaSeGuardaConNombreGenerado(): void
    {
        $tmp = $this->archivoTemporal($this->jpegDeUnPixel());

        $r = SubidaImagen::guardar([
            'name' => 'foto.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ], $this->carpeta, 'Clasificación arancelaria', copy(...));

        self::assertTrue($r['ok']);
        self::assertSame('', $r['error']);
        self::assertStringEndsWith('.jpg', $r['nombre']);
        self::assertStringStartsWith('clasificacion-arancelaria-', $r['nombre']);
        self::assertFileExists($this->carpeta . '/' . $r['nombre']);

        @unlink($tmp);
    }

    #[Test]
    public function unArchivoQueNoEsImagenSeRechazaAunqueDigaSerJpg(): void
    {
        $tmp = $this->archivoTemporal('esto no es una imagen, es texto plano');

        $r = SubidaImagen::guardar([
            'name' => 'malicioso.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ], $this->carpeta, 'curso', copy(...));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Formato no soportado', $r['error']);
        self::assertDirectoryDoesNotExist($this->carpeta);

        @unlink($tmp);
    }

    #[Test]
    public function unArchivoDemasiadoGrandeSeRechaza(): void
    {
        $tmp = $this->archivoTemporal($this->jpegDeUnPixel());

        $r = SubidaImagen::guardar([
            'name' => 'foto.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => 6 * 1024 * 1024,
        ], $this->carpeta, 'curso', copy(...));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('5 MB', $r['error']);

        @unlink($tmp);
    }

    #[Test]
    public function unErrorDeSubidaDeCualquierOtroTipoDaMensajeClaro(): void
    {
        $r = SubidaImagen::guardar([
            'name' => 'foto.jpg',
            'error' => UPLOAD_ERR_INI_SIZE,
        ], $this->carpeta, 'curso', copy(...));

        self::assertFalse($r['ok']);
        self::assertNotSame('', $r['error']);
    }
}
