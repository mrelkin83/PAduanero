<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\Logger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El error 7 de docs/CONTRATOS.md: nunca registrar contenido de mensajes, NIT
 * ni credenciales. Aquí es donde se comprueba que la redacción funciona sobre
 * lo que de verdad llega, no solo sobre casos de laboratorio.
 */
#[Group('critica')]
final class LoggerRedaccionTest extends TestCase
{
    #[Test]
    public function elContenidoDelMensajeDelContactoNoSeRegistra(): void
    {
        $limpio = Logger::redactar([
            'chatwoot_conv_id' => 123,
            'mensaje' => 'Me aprehendieron 200 millones en Buenaventura',
        ]);

        self::assertSame(123, $limpio['chatwoot_conv_id']);
        self::assertSame('[redactado]', $limpio['mensaje']);
    }

    #[Test]
    public function lasCredencialesNoSeRegistran(): void
    {
        $limpio = Logger::redactar([
            'servicio' => 'wompi',
            'api_key' => 'prv_prod_SECRETO123',
            'authorization' => 'Bearer abc.def.ghi',
        ]);

        self::assertSame('wompi', $limpio['servicio']);
        self::assertSame('[redactado]', $limpio['api_key']);
        self::assertSame('[redactado]', $limpio['authorization']);
    }

    #[Test]
    public function laRedaccionAlcanzaArraysAnidados(): void
    {
        $limpio = Logger::redactar([
            'payload' => ['caso' => ['id' => 'abc', 'descripcion_cliente' => 'texto sensible']],
        ]);

        self::assertSame('abc', $limpio['payload']['caso']['id']);
        self::assertSame('[redactado]', $limpio['payload']['caso']['descripcion_cliente']);
    }

    #[Test]
    public function elTelefonoSeRedactaAunqueVengaDentroDeUnTexto(): void
    {
        // Este es el caso que la redacción por nombre de clave no atrapa: el
        // dato llega incrustado en el mensaje de una excepción.
        $limpio = Logger::redactarTexto('Fallo al notificar a 573159923676 desde el worker');

        self::assertStringNotContainsString('573159923676', $limpio);
        self::assertStringContainsString('[tel]', $limpio);
    }

    #[Test]
    public function elNitSeRedactaEnTextoLibre(): void
    {
        self::assertStringNotContainsString(
            '900.123.456-7',
            Logger::redactarTexto('Cliente con NIT 900.123.456-7 sin levante'),
        );
    }

    #[Test]
    public function laPasswordDeUnDsnNoSeRegistra(): void
    {
        $limpio = Logger::redactarTexto('mysql://pedro:sup3rSecreta@127.0.0.1:3306/pedro_aduanero');

        self::assertStringNotContainsString('sup3rSecreta', $limpio);
        self::assertStringContainsString('pedro:[redactado]@', $limpio);
    }

    #[Test]
    public function elLogEscritoNoContieneDatosSensibles(): void
    {
        $ruta = sys_get_temp_dir() . '/pedro-log-' . bin2hex(random_bytes(4)) . '.log';

        try {
            (new Logger($ruta, 'debug'))->info('caso.registrado', [
                'caso_id' => 'abc-123',
                'telefono' => '573159923676',
                'descripcion' => 'aprehensión en Buenaventura',
            ]);

            $contenido = (string) file_get_contents($ruta);

            self::assertStringContainsString('abc-123', $contenido);
            self::assertStringNotContainsString('573159923676', $contenido);
            self::assertStringNotContainsString('Buenaventura', $contenido);
        } finally {
            @unlink($ruta);
        }
    }

    #[Test]
    public function elNivelFiltraLoQueNoLlegaAlUmbral(): void
    {
        $ruta = sys_get_temp_dir() . '/pedro-log-' . bin2hex(random_bytes(4)) . '.log';

        try {
            $log = new Logger($ruta, 'warn');
            $log->info('no.deberia.aparecer');
            $log->error('si.aparece');

            $contenido = is_file($ruta) ? (string) file_get_contents($ruta) : '';

            self::assertStringNotContainsString('no.deberia.aparecer', $contenido);
            self::assertStringContainsString('si.aparece', $contenido);
        } finally {
            @unlink($ruta);
        }
    }
}
