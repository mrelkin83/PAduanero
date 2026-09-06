<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\ColaCorreos;
use App\Soporte\EnviadorCorreo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * La cola de correos: encolar, procesar con reintentos y la degradación
 * cuando no hay SMTP. El envío real no se prueba (no hay servidor SMTP en
 * pruebas); se inyecta un Smtp doble para controlar el resultado.
 */
final class ColaCorreosTest extends CasoBaseBd
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bd->pdo()->exec('DELETE FROM correos_cola');
    }

    #[Test]
    public function encolarUnCorreoValidoLoDejaPendiente(): void
    {
        $cola = new ColaCorreos($this->bd);
        $id = $cola->encolar('cliente@ejemplo.com', 'Asunto', '<p>Hola</p>', 'prueba');

        self::assertIsInt($id);
        $fila = $this->bd->pdo()->query('SELECT * FROM correos_cola WHERE id = ' . $id)->fetch();
        self::assertSame('pendiente', $fila['estado']);
        self::assertSame('prueba', $fila['tipo']);
    }

    #[Test]
    public function unCorreoConDestinatarioInvalidoNoSeEncola(): void
    {
        $cola = new ColaCorreos($this->bd);

        self::assertNull($cola->encolar('no-es-correo', 'Asunto', '<p>x</p>'));
        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM correos_cola')->fetchColumn());
    }

    #[Test]
    public function sinSmtpLosPendientesEsperanSinTocarse(): void
    {
        $cola = new ColaCorreos($this->bd);
        $cola->encolar('cliente@ejemplo.com', 'Asunto', '<p>Hola</p>');

        $r = $cola->procesarPendientes(null);

        self::assertSame(1, $r['pendientes_sin_smtp']);
        self::assertSame('pendiente', $this->bd->pdo()->query('SELECT estado FROM correos_cola')->fetchColumn());
    }

    #[Test]
    public function unEnvioExitosoMarcaEnviado(): void
    {
        $cola = new ColaCorreos($this->bd);
        $cola->encolar('cliente@ejemplo.com', 'Asunto', '<p>Hola</p>');

        $r = $cola->procesarPendientes($this->smtpQueSiempre(true));

        self::assertSame(1, $r['enviados']);
        $fila = $this->bd->pdo()->query('SELECT * FROM correos_cola')->fetch();
        self::assertSame('enviado', $fila['estado']);
        self::assertSame(1, (int) $fila['intentos']);
        self::assertNotNull($fila['enviado_en']);
    }

    #[Test]
    public function trasTresFallosElCorreoQuedaFallidoYNoSeReintentaMas(): void
    {
        $cola = new ColaCorreos($this->bd);
        $cola->encolar('cliente@ejemplo.com', 'Asunto', '<p>Hola</p>');
        $smtp = $this->smtpQueSiempre(false);

        $cola->procesarPendientes($smtp); // intento 1 → pendiente
        $cola->procesarPendientes($smtp); // intento 2 → pendiente
        $cola->procesarPendientes($smtp); // intento 3 → fallido

        $fila = $this->bd->pdo()->query('SELECT * FROM correos_cola')->fetch();
        self::assertSame('fallido', $fila['estado']);
        self::assertSame(ColaCorreos::MAX_INTENTOS, (int) $fila['intentos']);

        // Un cuarto tick ya no lo toca (agotó los reintentos).
        $r = $cola->procesarPendientes($smtp);
        self::assertSame(0, $r['enviados'] + $r['fallidos']);
    }

    #[Test]
    public function reintentarDevuelveUnFallidoALaCola(): void
    {
        $cola = new ColaCorreos($this->bd);
        $id = (int) $cola->encolar('cliente@ejemplo.com', 'Asunto', '<p>Hola</p>');
        $smtp = $this->smtpQueSiempre(false);
        $cola->procesarPendientes($smtp);
        $cola->procesarPendientes($smtp);
        $cola->procesarPendientes($smtp);

        self::assertTrue($cola->reintentar($id));

        $fila = $this->bd->pdo()->query('SELECT * FROM correos_cola WHERE id = ' . $id)->fetch();
        self::assertSame('pendiente', $fila['estado']);
        self::assertSame(0, (int) $fila['intentos']);
    }

    private function smtpQueSiempre(bool $resultado): EnviadorCorreo
    {
        return new class ($resultado) implements EnviadorCorreo {
            public function __construct(private readonly bool $resultado)
            {
            }

            public function enviarHtml(string $para, string $asunto, string $html, ?string $texto = null): bool
            {
                return $this->resultado;
            }
        };
    }
}
