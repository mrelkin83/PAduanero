<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\CatalogoModelos;
use App\Servicios\Credenciales;
use App\Servicios\Descubridores\Descubridor;
use App\Servicios\Descubridores\DescubrimientoFallido;
use App\Servicios\Descubridores\ModeloDescubierto;
use App\Soporte\Logger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Descubrimiento automático, adopción manual.
 *
 * Lo que estas pruebas defienden no es que la sincronización funcione —eso es
 * lo fácil— sino que **no ascienda nada**. Un modelo que se pone primario
 * solo cambiaría lo que el bot le dice a los clientes de Pedro sin que
 * aparezca una firma en `auditoria`, y sería la única pieza del sistema capaz
 * de hacerlo (ADR-008).
 */
#[Group('critica')]
final class CatalogoModelosTest extends CasoBaseBd
{
    /** @param list<ModeloDescubierto>|\Throwable $resultado */
    private function catalogo(array|\Throwable $resultado): CatalogoModelos
    {
        $descubridor = new class ($resultado) implements Descubridor {
            /** @param list<ModeloDescubierto>|\Throwable $resultado */
            public function __construct(private readonly array|\Throwable $resultado)
            {
            }

            public function formato(): string
            {
                return 'anthropic';
            }

            public function claveCredencial(): ?string
            {
                return 'api_key';
            }

            public function listar(string $baseUrl, ?string $secreto): array
            {
                if ($this->resultado instanceof \Throwable) {
                    throw $this->resultado;
                }

                return $this->resultado;
            }
        };

        return new CatalogoModelos(
            $this->bd,
            $this->credencialesFalsas(),
            Logger::desdeEntorno(),
            [$descubridor],
        );
    }

    private function credencialesFalsas(): Credenciales
    {
        return new class implements Credenciales {
            public function obtener(string $servicio, string $clave, string $entorno = 'produccion'): string
            {
                return 'sk-de-prueba';
            }

            public function guardar(
                string $servicio,
                string $clave,
                string $valor,
                string $entorno,
                string $usuarioId,
            ): array {
                return ['mascara' => '****'];
            }

            public function probar(string $servicio, string $entorno): array
            {
                return ['ok' => true, 'mensaje' => ''];
            }

            public function rotarClaveMaestra(string $nuevaClave): void
            {
            }
        };
    }

    private function modelo(string $identificador): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM modelos_ia WHERE identificador = ?');
        $stmt->execute([$identificador]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    // ── Lo que sí es automático ──────────────────────────────────────────

    #[Test]
    public function unModeloNuevoAparecerSinTocarCodigo(): void
    {
        // El caso literal que pidió el PO.
        self::assertNull($this->modelo('claude-opus-6'));

        $this->catalogo([
            new ModeloDescubierto('claude-opus-6', 'Claude Opus 6', ventanaContexto: 2000000),
        ])->sincronizarTodo();

        $nuevo = $this->modelo('claude-opus-6');

        self::assertNotNull($nuevo);
        self::assertSame('Claude Opus 6', $nuevo['nombre_visible']);
        self::assertSame('descubierto', $nuevo['origen']);
        self::assertSame(2000000, (int) $nuevo['ventana_contexto']);
        self::assertNotNull($nuevo['descubierto_en']);
    }

    // ── Lo que NO es automático ──────────────────────────────────────────

    #[Test]
    public function loNuevoNaceInactivoSinCostoYSinSerPrimario(): void
    {
        $this->catalogo([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')])
            ->sincronizarTodo();

        $nuevo = $this->modelo('claude-opus-6');

        self::assertSame(0, (int) $nuevo['activo'], 'un modelo descubierto no puede nacer activo');
        self::assertSame(0, (int) $nuevo['es_primario'], 'ni ascender solo');
        self::assertSame(0, (int) $nuevo['costos_verificados']);
        self::assertNull($nuevo['costo_entrada_usd_1m']);
    }

    #[Test]
    public function laBaseImpideQueUnModeloSinCostoVerificadoSeaPrimario(): void
    {
        // La invariante vive en el CHECK y no solo en el controlador: tiene
        // que sobrevivir al script que alguien corra a mano.
        $this->catalogo([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')])
            ->sincronizarTodo();

        $id = $this->modelo('claude-opus-6')['id'];

        $this->expectException(\PDOException::class);

        $this->bd->pdo()
            ->prepare('UPDATE modelos_ia SET es_primario = 1, activo = 1 WHERE id = ?')
            ->execute([$id]);
    }

    #[Test]
    public function laSincronizacionNoTocaLoQueUnaPersonaDecidio(): void
    {
        $catalogo = $this->catalogo([
            new ModeloDescubierto('claude-opus-5', 'Claude Opus 5 (renombrado)'),
        ]);

        // Alguien verificó el costo y lo ascendió, como haría desde el panel.
        $id = $this->modelo('claude-opus-5')['id'];
        // `dorado_estado` incluido: desde 0008 un primario de conversación
        // exige corrida dorada en verde. Aquí no se está probando el gate
        // —eso es GateDoradoTest— sino que la sincronización respete lo que
        // una persona decidió, así que se deja el modelo tal y como habría
        // quedado tras pasar por el panel.
        $this->bd->pdo()->prepare(
            'UPDATE modelos_ia
                SET costo_entrada_usd_1m = 5, costo_salida_usd_1m = 25,
                    costos_verificados = 1, activo = 1,
                    dorado_estado = \'verde\', dorado_en = NOW(),
                    es_primario = 1
              WHERE id = ?'
        )->execute([$id]);

        $catalogo->sincronizarTodo();

        $tras = $this->modelo('claude-opus-5');

        self::assertSame(1, (int) $tras['es_primario'], 'la sincronización no puede degradar el primario');
        self::assertSame(1, (int) $tras['activo']);
        self::assertSame('5.0000', $tras['costo_entrada_usd_1m']);
        self::assertSame(1, (int) $tras['costos_verificados']);
        // Los metadatos del proveedor sí se refrescan: son suyos.
        self::assertSame('Claude Opus 5 (renombrado)', $tras['nombre_visible']);
    }

    #[Test]
    public function sincronizarDosVecesNoDuplica(): void
    {
        $catalogo = $this->catalogo([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')]);

        $catalogo->sincronizarTodo();
        $segunda = $catalogo->sincronizarTodo();

        $stmt = $this->bd->pdo()->prepare('SELECT COUNT(*) FROM modelos_ia WHERE identificador = ?');
        $stmt->execute(['claude-opus-6']);

        self::assertSame(1, (int) $stmt->fetchColumn());
        self::assertSame(0, $segunda[0]['nuevos']);
        self::assertGreaterThan(0, $segunda[0]['vistos']);
    }

    // ── Retiros ──────────────────────────────────────────────────────────

    #[Test]
    public function loQueElProveedorDejaDeListarSeMarcaRetiradoSinBorrarse(): void
    {
        $catalogo = $this->catalogo([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')]);
        $catalogo->sincronizarTodo();

        // Al día siguiente el proveedor ya no lo lista.
        $resumen = $this->catalogo([new ModeloDescubierto('claude-opus-7', 'Claude Opus 7')])
            ->sincronizarTodo();

        $retirado = $this->modelo('claude-opus-6');

        self::assertNotNull($retirado, 'no se borra: consumo_ia lo referencia');
        self::assertNotNull($retirado['retirado_en']);
        self::assertSame(1, $resumen[0]['retirados']);
    }

    #[Test]
    public function unModeloNuncaVistoNoSeMarcaComoRetirado(): void
    {
        // Las semillas de 0007 son `origen = manual` y sin `visto_en`. Si la
        // primera sincronización no las devuelve, no significa que las hayan
        // retirado: significa que nunca fueron confirmadas. Marcarlas de otro
        // modo sería inventar un hecho.
        $antes = $this->modelo('claude-haiku-4-5');
        self::assertNull($antes['visto_en']);

        $this->catalogo([new ModeloDescubierto('claude-opus-5', 'Claude Opus 5')])
            ->sincronizarTodo();

        self::assertNull($this->modelo('claude-haiku-4-5')['retirado_en']);
    }

    #[Test]
    public function unRetiroSeRevierteSiElModeloVuelveAAparecer(): void
    {
        $this->catalogo([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')])->sincronizarTodo();
        $this->catalogo([])->sincronizarTodo();
        self::assertNotNull($this->modelo('claude-opus-6')['retirado_en']);

        // Un corte de red del lado del proveedor no debe dejar el catálogo
        // marcado como muerto para siempre.
        $this->catalogo([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')])->sincronizarTodo();

        self::assertNull($this->modelo('claude-opus-6')['retirado_en']);
    }

    #[Test]
    public function elCronPuedeRetirarElModeloQueEstaEnUso(): void
    {
        // Esta es la razón de que `retirado_en` NO esté en el CHECK. Si
        // estuviera, el UPDATE violaría la restricción y la sincronización
        // fallaría entera justo en el caso que más importa registrar.
        $this->catalogo([new ModeloDescubierto('claude-opus-5', 'Claude Opus 5')])->sincronizarTodo();

        $id = $this->modelo('claude-opus-5')['id'];
        // `dorado_estado` incluido: desde 0008 un primario de conversación
        // exige corrida dorada en verde. Aquí no se está probando el gate
        // —eso es GateDoradoTest— sino que la sincronización respete lo que
        // una persona decidió, así que se deja el modelo tal y como habría
        // quedado tras pasar por el panel.
        $this->bd->pdo()->prepare(
            'UPDATE modelos_ia
                SET costo_entrada_usd_1m = 5, costo_salida_usd_1m = 25,
                    costos_verificados = 1, activo = 1,
                    dorado_estado = \'verde\', dorado_en = NOW(),
                    es_primario = 1
              WHERE id = ?'
        )->execute([$id]);

        $resumen = $this->catalogo([])->sincronizarTodo();

        self::assertTrue($resumen[0]['ok']);
        self::assertSame(1, $resumen[0]['retirados']);

        $tras = $this->modelo('claude-opus-5');
        self::assertNotNull($tras['retirado_en']);
        // Sigue siendo primario: bajarlo sería decidir por una persona. La
        // cascada de `orden_fallback` cubre el servicio mientras tanto.
        self::assertSame(1, (int) $tras['es_primario']);
    }

    // ── Fallos ───────────────────────────────────────────────────────────

    #[Test]
    public function unProveedorCaidoNoRetiraNada(): void
    {
        $this->catalogo([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')])->sincronizarTodo();

        $resumen = $this->catalogo(new DescubrimientoFallido('Anthropic respondió 503'))
            ->sincronizarTodo();

        self::assertFalse($resumen[0]['ok']);
        self::assertSame('Anthropic respondió 503', $resumen[0]['error']);
        self::assertNull(
            $this->modelo('claude-opus-6')['retirado_en'],
            'un 503 no es un retiro del catálogo',
        );
    }

    #[Test]
    public function cadaCorridaDejaFilaEnLaBitacoraIncluidaLaQueFalla(): void
    {
        $this->catalogo([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')])->sincronizarTodo();
        $this->catalogo(new DescubrimientoFallido('credencial rechazada'))->sincronizarTodo();

        $filas = $this->bd->pdo()
            ->query('SELECT ok, nuevos, error FROM sincronizaciones_modelos ORDER BY id')
            ->fetchAll();

        self::assertCount(2, $filas);
        self::assertSame(1, (int) $filas[0]['ok']);
        self::assertSame(1, (int) $filas[0]['nuevos']);
        self::assertSame(0, (int) $filas[1]['ok']);
        self::assertSame('credencial rechazada', $filas[1]['error']);
    }

    #[Test]
    public function unProveedorSinDescubridorNoRompeLaCorrida(): void
    {
        // El descubridor de prueba solo atiende `anthropic`. Se activa el de
        // OpenAI para comprobar que un formato sin descubridor se registra
        // como fallo y no como excepción que tumba el cron entero.
        $this->bd->pdo()->exec("UPDATE proveedores_ia SET activo = 1 WHERE clave = 'openai'");

        $resumen = $this->catalogo([new ModeloDescubierto('claude-opus-5', 'Claude Opus 5')])
            ->sincronizarTodo();

        $porClave = array_column($resumen, null, 'proveedor');

        self::assertTrue($porClave['anthropic']['ok']);
        self::assertFalse($porClave['openai']['ok']);
        self::assertStringContainsString('descubridor', (string) $porClave['openai']['error']);
    }

    // ── Semillas ─────────────────────────────────────────────────────────

    #[Test]
    public function lasSemillasNoDejanNingunModeloListoParaUsarse(): void
    {
        $filas = $this->bd->pdo()->query(
            'SELECT COUNT(*) AS total,
                    SUM(activo) AS activos,
                    SUM(es_primario) AS primarios,
                    SUM(costos_verificados) AS verificados
               FROM modelos_ia'
        )->fetch();

        self::assertGreaterThan(0, (int) $filas['total'], 'debe haber catálogo de partida');
        self::assertSame(0, (int) $filas['activos']);
        self::assertSame(0, (int) $filas['primarios']);
        self::assertSame(0, (int) $filas['verificados'], 'el precio lo confirma una persona');
    }

    #[Test]
    public function lasSemillasTraenElPrecioPrecargadoParaQueVerificarloSeaComprobarlo(): void
    {
        $opus = $this->modelo('claude-opus-5');

        self::assertSame('5.0000', $opus['costo_entrada_usd_1m']);
        self::assertSame('25.0000', $opus['costo_salida_usd_1m']);
        self::assertSame(0, (int) $opus['costos_verificados']);
    }
}
