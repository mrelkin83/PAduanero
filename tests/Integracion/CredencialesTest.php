<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Excepciones\CredencialNoEncontradaException;
use App\Servicios\CredencialesAes;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Criterio de cierre de la Etapa 0: guardar una credencial, recuperarla
 * descifrada, y que la respuesta HTTP solo devuelva la máscara.
 */
#[Group('critica')]
final class CredencialesTest extends CasoBaseBd
{
    private const SECRETO = 'prv_prod_SECRETO123';
    private const USUARIO = '00000000-0000-0000-0000-000000000001';

    private CredencialesAes $credenciales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credenciales = new CredencialesAes(
            $this->bd,
            Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pedro-pruebas.log', 'error'),
        );
    }

    #[Test]
    public function laCredencialSeGuardaYSeRecuperaDescifrada(): void
    {
        $this->credenciales->guardar('wompi', 'private_key', self::SECRETO, 'produccion', self::USUARIO);

        self::assertSame(
            self::SECRETO,
            $this->credenciales->obtener('wompi', 'private_key', 'produccion'),
        );
    }

    #[Test]
    public function elValorSeGuardaCifradoEnLaBase(): void
    {
        $this->credenciales->guardar('wompi', 'private_key', self::SECRETO, 'produccion', self::USUARIO);

        $fila = $this->bd->pdo()
            ->query('SELECT valor_cifrado, mascara FROM credenciales')
            ->fetch();

        $blob = is_resource($fila['valor_cifrado'])
            ? (string) stream_get_contents($fila['valor_cifrado'])
            : (string) $fila['valor_cifrado'];

        self::assertStringNotContainsString(self::SECRETO, $blob, 'el secreto está en claro en la base');
        self::assertSame("\x01", $blob[0], 'el blob debe llevar el byte de versión v1');
        self::assertStringNotContainsString('SECRETO', $fila['mascara']);
    }

    #[Test]
    public function laApiSoloDevuelveLaMascara(): void
    {
        $resultado = $this->credenciales->guardar(
            'wompi', 'private_key', self::SECRETO, 'produccion', self::USUARIO
        );

        // Esto es exactamente lo que el panel serializa hacia el navegador.
        $json = json_encode($resultado, JSON_UNESCAPED_UNICODE);

        self::assertIsString($json);
        self::assertStringNotContainsString(self::SECRETO, $json);
        self::assertStringNotContainsString('prv_prod', $json);
        self::assertStringContainsString('123', $json);
    }

    #[Test]
    public function guardarDosVecesActualizaEnVezDeDuplicar(): void
    {
        $this->credenciales->guardar('wompi', 'private_key', self::SECRETO, 'produccion', self::USUARIO);
        $this->credenciales->guardar('wompi', 'private_key', 'prv_prod_NUEVA456', 'produccion', self::USUARIO);

        $total = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM credenciales')->fetchColumn();

        self::assertSame(1, $total, 'el índice único de (servicio, entorno, clave) debe imponerse');
        self::assertSame(
            'prv_prod_NUEVA456',
            $this->credenciales->obtener('wompi', 'private_key', 'produccion'),
        );
    }

    #[Test]
    public function pruebasYProduccionNoSePisan(): void
    {
        $this->credenciales->guardar('wompi', 'private_key', 'prv_test_AAA', 'pruebas', self::USUARIO);
        $this->credenciales->guardar('wompi', 'private_key', 'prv_prod_BBB', 'produccion', self::USUARIO);

        // Confundirlas es la causa habitual de "la firma del webhook no
        // valida" (docs/RUNBOOK.md §3.3).
        self::assertSame('prv_test_AAA', $this->credenciales->obtener('wompi', 'private_key', 'pruebas'));
        self::assertSame('prv_prod_BBB', $this->credenciales->obtener('wompi', 'private_key', 'produccion'));
    }

    #[Test]
    public function cadaLecturaQuedaAuditada(): void
    {
        $this->credenciales->guardar('wompi', 'private_key', self::SECRETO, 'produccion', self::USUARIO);
        $this->credenciales->obtener('wompi', 'private_key', 'produccion');

        $fila = $this->bd->pdo()->query(
            "SELECT detalle FROM auditoria WHERE entidad = 'credencial' AND accion = 'leer'"
        )->fetch();

        self::assertIsArray($fila, 'toda lectura del valor real debe quedar en auditoria');
        self::assertStringNotContainsString(
            self::SECRETO,
            (string) $fila['detalle'],
            'la auditoría registra que se leyó, nunca lo que se leyó',
        );
    }

    #[Test]
    public function unaCredencialInexistenteLanzaExcepcion(): void
    {
        $this->expectException(CredencialNoEncontradaException::class);
        $this->credenciales->obtener('wompi', 'no_existe', 'produccion');
    }

    #[Test]
    public function unaCredencialInactivaNoSeEntrega(): void
    {
        $this->credenciales->guardar('wompi', 'private_key', self::SECRETO, 'produccion', self::USUARIO);
        $this->bd->pdo()->exec('UPDATE credenciales SET activo = 0');

        $this->expectException(CredencialNoEncontradaException::class);
        $this->credenciales->obtener('wompi', 'private_key', 'produccion');
    }

    #[Test]
    public function rotarLaClaveMaestraReCifraYSubeKeyVersion(): void
    {
        $this->credenciales->guardar('wompi', 'private_key', self::SECRETO, 'produccion', self::USUARIO);

        $antes = $this->blobActual();
        $nueva = base64_encode(str_repeat("\x07", 32));

        $this->credenciales->rotarClaveMaestra($nueva);

        $despues = $this->blobActual();
        self::assertNotSame($antes, $despues, 'el blob debe cambiar tras la rotación');

        self::assertSame(
            2,
            (int) $this->bd->pdo()->query('SELECT key_version FROM credenciales')->fetchColumn(),
        );

        // El servicio nuevo, con la clave nueva, sigue leyendo el mismo valor.
        $conNueva = new CredencialesAes(
            $this->bd,
            new Cifrado($nueva, base64_encode(str_repeat("\x02", 32))),
            new Logger(sys_get_temp_dir() . '/pedro-pruebas.log', 'error'),
        );

        self::assertSame(self::SECRETO, $conNueva->obtener('wompi', 'private_key', 'produccion'));
    }

    #[Test]
    public function unValorVacioSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->credenciales->guardar('wompi', 'private_key', '   ', 'produccion', self::USUARIO);
    }

    // ── Probar conexión ──────────────────────────────────────────────────
    //
    // `docs/PRUEBAS.md` §7 fija 100 % para este servicio: es el que custodia
    // las llaves de la pasarela de pagos.

    /** @param array{ok:bool,mensaje:string} $resultado */
    private function conProbador(array $resultado, ?\Throwable $revienta = null): CredencialesAes
    {
        $probador = new class ($resultado, $revienta) implements \App\Servicios\Probadores\Probador {
            public function __construct(private array $resultado, private ?\Throwable $revienta)
            {
            }

            public function servicio(): string
            {
                return 'wompi';
            }

            public function clavesRequeridas(): array
            {
                return ['llave_publica', 'llave_privada'];
            }

            public function probar(array $credenciales, string $entorno): array
            {
                if ($this->revienta !== null) {
                    throw $this->revienta;
                }

                return $this->resultado;
            }
        };

        return new CredencialesAes(
            $this->bd,
            Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pedro-pruebas.log', 'error'),
            [$probador],
        );
    }

    private function guardarPar(): void
    {
        $this->credenciales->guardar('wompi', 'llave_publica', 'pub_test_AAA', 'pruebas', self::USUARIO);
        $this->credenciales->guardar('wompi', 'llave_privada', 'prv_test_BBB', 'pruebas', self::USUARIO);
    }

    #[Test]
    public function sinProbadorRegistradoLoDiceYNoMarcaNada(): void
    {
        $r = $this->credenciales->probar('paypal', 'produccion');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no hay probador', $r['mensaje']);

        // No se escribe un rojo por una integración que todavía no llega:
        // confundiría más de lo que ayuda.
        self::assertNull(
            $this->bd->pdo()->query('SELECT ultima_prueba_ok FROM credenciales')->fetchColumn() ?: null,
        );
    }

    #[Test]
    public function sinCredencialesGuardadasDiceCualFalta(): void
    {
        $r = $this->conProbador(['ok' => true, 'mensaje' => 'no debería llegar'])->probar('wompi', 'pruebas');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Faltan credenciales', $r['mensaje']);
        self::assertStringContainsString('llave_publica', $r['mensaje']);
    }

    #[Test]
    public function unaPruebaCorrectaSeRegistra(): void
    {
        $this->guardarPar();

        $r = $this->conProbador(['ok' => true, 'mensaje' => 'Conexión correcta.'])->probar('wompi', 'pruebas');

        self::assertTrue($r['ok']);

        $fila = $this->bd->pdo()->query(
            "SELECT ultima_prueba_ok, ultima_prueba_en FROM credenciales WHERE clave='llave_publica'"
        )->fetch();

        self::assertSame(1, (int) $fila['ultima_prueba_ok']);
        self::assertNotNull($fila['ultima_prueba_en']);
    }

    #[Test]
    public function unaPruebaFallidaTambienSeRegistra(): void
    {
        $this->guardarPar();

        $r = $this->conProbador(['ok' => false, 'mensaje' => 'La privada no vale.'])->probar('wompi', 'pruebas');

        self::assertFalse($r['ok']);
        self::assertSame(
            0,
            (int) $this->bd->pdo()->query("SELECT ultima_prueba_ok FROM credenciales WHERE clave='llave_publica'")->fetchColumn(),
        );
    }

    #[Test]
    public function unProbadorQueRevientaNoTumbaElPanel(): void
    {
        $this->guardarPar();

        // Y su mensaje podría llevar la credencial dentro, así que no se
        // propaga al usuario.
        $r = $this->conProbador([], new \RuntimeException('boom con prv_test_BBB dentro'))
            ->probar('wompi', 'pruebas');

        self::assertFalse($r['ok']);
        self::assertStringNotContainsString('prv_test_BBB', $r['mensaje']);
        self::assertStringContainsString('inesperada', $r['mensaje']);
    }

    #[Test]
    public function probarQuedaEnLaAuditoriaSinElValor(): void
    {
        $this->guardarPar();
        $this->conProbador(['ok' => true, 'mensaje' => 'ok'])->probar('wompi', 'pruebas');

        $fila = $this->bd->pdo()->query(
            "SELECT detalle FROM auditoria WHERE entidad='credencial' AND accion='probar'"
        )->fetch();

        self::assertIsArray($fila);
        self::assertStringNotContainsString('prv_test_BBB', (string) $fila['detalle']);
    }

    #[Test]
    public function elBlobSeLeeTantoComoCadenaComoComoFlujo(): void
    {
        // PDO devuelve los VARBINARY como cadena con el driver de MySQL, pero
        // como recurso con otras configuraciones. La rama del flujo no la
        // ejercita ninguna prueba de extremo a extremo por eso, y `docs/
        // PRUEBAS.md` §7 exige 100 % en este servicio: se llama directa.
        $metodo = new \ReflectionMethod($this->credenciales, 'comoBinario');
        $metodo->setAccessible(true);

        self::assertSame('abc', $metodo->invoke($this->credenciales, 'abc'));

        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, 'abc');
        rewind($flujo);

        self::assertSame('abc', $metodo->invoke($this->credenciales, $flujo));

        fclose($flujo);
    }

    #[Test]
    public function rotarConUnaClaveInvalidaNoDejaLaTablaAMedias(): void
    {
        $this->credenciales->guardar('wompi', 'a', 'valor-a', 'produccion', self::USUARIO);
        $this->credenciales->guardar('wompi', 'b', 'valor-b', 'produccion', self::USUARIO);

        try {
            $this->credenciales->rotarClaveMaestra(base64_encode('demasiado corta'));
            self::fail('debió rechazar la clave');
        } catch (\Throwable) {
            // Quedarse a medias dejaría media tabla con una clave y media con
            // otra, y ninguna podría leerla entera.
        }

        self::assertSame('valor-a', $this->credenciales->obtener('wompi', 'a', 'produccion'));
        self::assertSame('valor-b', $this->credenciales->obtener('wompi', 'b', 'produccion'));
    }

    private function blobActual(): string
    {
        $valor = $this->bd->pdo()->query('SELECT valor_cifrado FROM credenciales')->fetchColumn();

        return is_resource($valor) ? (string) stream_get_contents($valor) : (string) $valor;
    }
}
