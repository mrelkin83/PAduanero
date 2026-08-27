<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Soporte\Cifrado;
use App\Soporte\Logger;
use App\Wa\ConexionCompartida;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class ConexionCompartidaTest extends CasoBaseBd
{
    private ConexionCompartida $conexion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conexion = new ConexionCompartida(
            $this->bd,
            Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pa-conexion.log', 'error'),
            dirname(__DIR__, 2),
        );
    }

    #[Test]
    public function sinCredencialesDeWompiWompiDevuelveNull(): void
    {
        // La semilla de 0016 deja wa_config sin wompi_public_key.
        self::assertNull($this->conexion->wompi());
    }

    #[Test]
    public function conLlavePublicaConfiguradaWompiDevuelveUnAdaptador(): void
    {
        $this->bd->pdo()->exec("UPDATE wa_config SET wompi_public_key = 'pub_test_ejemplo' WHERE id = 1");

        self::assertNotNull($this->conexion->wompi());
    }

    #[Test]
    public function avisarPedroNoTruenaSinConfiguracionDeEvolution(): void
    {
        // La semilla de 0016 deja evolution_url en NULL. No debe lanzar
        // excepción ni intentar una petición HTTP real.
        $this->conexion->avisarPedro('Prueba de aviso');

        self::assertTrue(true);
    }

    protected function tearDown(): void
    {
        // wa_config no está en las semillas restauradas por CasoBaseBd
        // (ver TABLAS_SEMILLA y limpiar()): sin este reset, la llave pública
        // que deja conLlavePublicaConfiguradaWompiDevuelveUnAdaptador() se
        // filtraría a la siguiente clase de la misma corrida. Mismo hazard
        // que documenta PanelWhatsappTest::setUp().
        $this->bd->pdo()->exec("UPDATE wa_config SET wompi_public_key = NULL WHERE id = 1");

        parent::tearDown();
    }
}
