<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\ConfigMysql;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class ConfigTest extends CasoBaseBd
{
    private const USUARIO = '00000000-0000-0000-0000-000000000001';

    private ConfigMysql $config;
    private string $sentinela;
    private string $cache;

    protected function setUp(): void
    {
        // `CasoBaseBd` restaura las semillas de `configuraciones` entre
        // casos, así que cada prueba arranca con los valores sembrados.
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $this->sentinela = sys_get_temp_dir() . "/pedro-sentinela-{$sufijo}";
        $this->cache = sys_get_temp_dir() . "/pedro-cache-{$sufijo}.json";

        $this->config = new ConfigMysql($this->bd, $this->sentinela, $this->cache);
        $this->config->invalidarCache();
    }

    protected function tearDown(): void
    {
        @unlink($this->sentinela);
        @unlink($this->cache);
        parent::tearDown();
    }

    #[Test]
    public function leeLosValoresSembrados(): void
    {
        self::assertTrue($this->config->get('motor_modo_sombra'));
        self::assertSame(45, $this->config->get('minutos_reserva_pago'));
        self::assertSame(['aduanero'], $this->config->get('areas_practica'));
    }

    #[Test]
    public function devuelveElPorDefectoSiLaClaveNoExiste(): void
    {
        self::assertSame('nada', $this->config->get('clave_que_no_existe', 'nada'));
    }

    #[Test]
    public function escribirGuardaHistorialConAutor(): void
    {
        $this->config->set('minutos_reserva_pago', 120, self::USUARIO, 'mucha caída entre reserva y pago');

        self::assertSame(120, $this->config->get('minutos_reserva_pago'));

        $fila = $this->bd->pdo()->query(
            "SELECT valor_anterior, valor_nuevo, usuario_id, motivo
               FROM configuraciones_historial WHERE clave = 'minutos_reserva_pago'"
        )->fetch();

        self::assertIsArray($fila);
        self::assertSame(45, json_decode((string) $fila['valor_anterior'], true));
        self::assertSame(120, json_decode((string) $fila['valor_nuevo'], true));
        self::assertSame(self::USUARIO, $fila['usuario_id']);
        self::assertSame('mucha caída entre reserva y pago', $fila['motivo']);
    }

    #[Test]
    public function elRangoDeLaFilaSeRespeta(): void
    {
        // minutos_reserva_pago está sembrado con mínimo 10 y máximo 240.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no puede ser mayor/u');

        $this->config->set('minutos_reserva_pago', 9999, self::USUARIO);
    }

    #[Test]
    public function elTipoDeLaFilaSeRespeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->set('max_turnos_ia', 'muchos', self::USUARIO);
    }

    #[Test]
    public function lasOpcionesCerradasSeRespetan(): void
    {
        // pasarela_activa está sembrada con opciones wompi|bold|mercadopago.
        $this->expectException(\InvalidArgumentException::class);
        $this->config->set('pasarela_activa', 'paypal', self::USUARIO);
    }

    #[Test]
    public function unaClaveInexistenteNoSePuedeEscribir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->set('clave_inventada', 'x', self::USUARIO);
    }

    #[Test]
    public function elValorViejoNoSobreviveEnCacheTrasEscribir(): void
    {
        self::assertTrue($this->config->get('motor_ia_pausado') === false);

        $this->config->set('motor_ia_pausado', true, self::USUARIO);

        // El kill switch tiene que surtir efecto de inmediato: si la caché se
        // lo comiera, el bot seguiría hablando después de apagarlo.
        self::assertTrue($this->config->get('motor_ia_pausado'));
    }

    #[Test]
    public function elCentinelaPropagaElCambioAOtroProceso(): void
    {
        // Dos instancias = PHP-FPM y el worker del outbox, que no comparten
        // memoria. El centinela es lo único que los sincroniza.
        $worker = new ConfigMysql($this->bd, $this->sentinela, $this->cache);

        self::assertFalse($worker->get('motor_ia_pausado'));

        // El worker ya tiene el valor viejo cacheado cuando el panel escribe.
        $this->config->set('motor_ia_pausado', true, self::USUARIO);

        // `touch` tiene resolución de segundo: sin esperar, el mtime podría
        // no cambiar y la prueba pasaría por accidente.
        sleep(1);
        touch($this->sentinela);

        self::assertTrue($worker->get('motor_ia_pausado'), 'el worker no vio el kill switch');
    }

    #[Test]
    public function elGrupoTraeLosMetadatosParaElFormulario(): void
    {
        $grupo = $this->config->getGrupo('agenda');

        self::assertNotEmpty($grupo);

        $claves = array_map(static fn ($c) => $c->clave, $grupo);
        self::assertContains('minutos_reserva_pago', $claves);

        foreach ($grupo as $config) {
            self::assertNotSame('', $config->etiqueta, 'el panel pinta el formulario desde esto');
        }
    }
}
