<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\ConfigMysql;
use App\Servicios\MetricasLanding;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Criterio de cierre de la Etapa 1: un clic en el botón de WhatsApp registra
 * el evento con su `utm_campaign`.
 *
 * Sin eso no hay atribución, y sin atribución no se puede saber qué campaña
 * de Meta trae clientes que pagan — que es la única pregunta que decide el
 * presupuesto de publicidad.
 */
final class MetricasLandingTest extends CasoBaseBd
{
    private const SESION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private MetricasLanding $metricas;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pedro-sent-{$sufijo}",
            sys_get_temp_dir() . "/pedro-cache-{$sufijo}.json",
        );

        $this->metricas = new MetricasLanding($this->bd, $config);
    }

    #[Test]
    #[Group('critica')]
    public function elClicEnWhatsappSeRegistraConSuCampana(): void
    {
        $registrado = $this->metricas->registrar([
            'tipo' => 'click_whatsapp',
            'sesion' => self::SESION,
            'ruta' => '/',
            'utm_source' => 'facebook',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'aprehension-agosto',
            'utm_content' => 'video-30s',
            'dispositivo' => 'movil',
        ]);

        self::assertTrue($registrado);

        $fila = $this->bd->pdo()->query(
            "SELECT * FROM eventos_landing WHERE tipo = 'click_whatsapp'"
        )->fetch();

        self::assertIsArray($fila, 'el clic no quedó registrado');
        self::assertSame('aprehension-agosto', $fila['utm_campaign']);
        self::assertSame('facebook', $fila['utm_source']);
        self::assertSame('cpc', $fila['utm_medium']);
        self::assertSame('video-30s', $fila['utm_content']);
        self::assertSame('movil', $fila['dispositivo']);
    }

    #[Test]
    public function elIdentificadorDeSesionSeGuardaHasheado(): void
    {
        $this->metricas->registrar(['tipo' => 'vista', 'sesion' => self::SESION]);

        $guardado = $this->bd->pdo()->query('SELECT sesion_hash FROM eventos_landing')->fetchColumn();

        // Si alguien accede a la tabla, no puede volver al valor que el
        // visitante tiene en sessionStorage.
        self::assertNotSame(self::SESION, $guardado);
        self::assertSame(hash('sha256', self::SESION), $guardado);
    }

    #[Test]
    public function noSeGuardaNingunDatoIdentificable(): void
    {
        $this->metricas->registrar([
            'tipo' => 'vista',
            'sesion' => self::SESION,
            // Aunque el cliente los mande, no hay columna donde meterlos ni
            // código que los lea: la tabla es deliberadamente anónima.
            'ip' => '190.85.1.1',
            'user_agent' => 'Mozilla/5.0',
            'email' => 'alguien@ejemplo.com',
        ]);

        $fila = $this->bd->pdo()->query('SELECT * FROM eventos_landing')->fetch();
        $serializada = json_encode($fila, JSON_UNESCAPED_UNICODE);

        self::assertIsString($serializada);
        self::assertStringNotContainsString('190.85.1.1', $serializada);
        self::assertStringNotContainsString('Mozilla', $serializada);
        self::assertStringNotContainsString('ejemplo.com', $serializada);
    }

    #[Test]
    public function unTipoFueraDelCatalogoSeDescarta(): void
    {
        self::assertFalse($this->metricas->registrar([
            'tipo' => 'DROP TABLE',
            'sesion' => self::SESION,
        ]));

        self::assertSame(0, $this->contar());
    }

    #[Test]
    public function unaSesionMalFormadaSeDescarta(): void
    {
        // El endpoint es público: cualquiera puede mandar lo que quiera.
        foreach (['', 'corta', str_repeat('z', 32), '<script>', null] as $sesion) {
            self::assertFalse($this->metricas->registrar([
                'tipo' => 'vista',
                'sesion' => $sesion,
            ]));
        }

        self::assertSame(0, $this->contar());
    }

    #[Test]
    public function losUtmDemasiadoLargosSeRecortan(): void
    {
        // Vienen de la URL, así que vienen de fuera. La columna admite 100.
        $this->metricas->registrar([
            'tipo' => 'vista',
            'sesion' => self::SESION,
            'utm_campaign' => str_repeat('x', 500),
        ]);

        $guardado = $this->bd->pdo()->query('SELECT utm_campaign FROM eventos_landing')->fetchColumn();

        self::assertSame(100, mb_strlen((string) $guardado));
    }

    #[Test]
    public function elTopePorSesionFrenaLosBucles(): void
    {
        // Un `scroll` mal desconectado puede meter miles de filas en un
        // minuto. La semilla deja el tope en 60.
        for ($i = 0; $i < 65; $i++) {
            $this->metricas->registrar(['tipo' => 'vista', 'sesion' => self::SESION]);
        }

        self::assertSame(60, $this->contar());
    }

    #[Test]
    public function unaVisitaSinUtmSeRegistraIgual(): void
    {
        // El tráfico directo y el orgánico también cuentan: sin ellos, el
        // denominador de la conversión queda mal.
        self::assertTrue($this->metricas->registrar([
            'tipo' => 'vista',
            'sesion' => self::SESION,
            'ruta' => '/',
        ]));

        $fila = $this->bd->pdo()->query('SELECT * FROM eventos_landing')->fetch();

        self::assertNull($fila['utm_campaign']);
        self::assertSame('/', $fila['ruta']);
    }

    private function contar(): int
    {
        return (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM eventos_landing')->fetchColumn();
    }
}
