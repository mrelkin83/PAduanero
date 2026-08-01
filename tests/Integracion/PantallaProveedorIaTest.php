<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Csrf;
use App\Core\Peticion;
use App\Modelos\Usuario;
use App\Panel\Contexto;
use App\Panel\IaControlador;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\CredencialRepo;
use App\Servicios\CatalogoModelos;
use App\Servicios\Credenciales;
use App\Servicios\CredencialesAes;
use App\Servicios\Descubridores\Descubridor;
use App\Servicios\Descubridores\DescubrimientoFallido;
use App\Servicios\Descubridores\ModeloDescubierto;
use App\Servicios\GateDorado;
use App\Servicios\Llm;
use App\Servicios\Permisos;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * La pantalla «Proveedor de IA»: elegir proveedor, ver sus modelos en vivo,
 * elegir uno y guardar la llave, todo de una vez.
 *
 * Lo que se prueba no es que los campos existan, sino que el atajo no abra
 * ninguna puerta trasera: la llave sigue sin volver al navegador, el modelo
 * sigue necesitando costo para activarse y el gate del ADR-016 sigue
 * decidiendo quién habla con los clientes.
 */
#[Group('critica')]
final class PantallaProveedorIaTest extends CasoBaseBd
{
    /** @param list<ModeloDescubierto>|\Throwable $resultado */
    private function controlador(array|\Throwable $resultado = []): IaControlador
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

        $credenciales = $this->credencialesEnMemoria();

        return new IaControlador(
            $this->bd,
            new CatalogoModelos($this->bd, $credenciales, Logger::desdeEntorno(), [$descubridor]),
            new GateDorado($this->bd),
            $credenciales,
            new CredencialRepo($this->bd),
            new AuditoriaRepo($this->bd),
            new Llm(
                $this->bd,
                $credenciales,
                new class implements \App\Servicios\Config {
                    public function get(string $clave, mixed $porDefecto = null): mixed
                    {
                        return $clave === 'presupuesto_ia_mensual_usd' ? 100 : $porDefecto;
                    }

                    public function set(
                        string $clave,
                        mixed $valor,
                        string $usuarioId,
                        ?string $motivo = null,
                    ): void {
                    }

                    public function getGrupo(string $grupo): array
                    {
                        return [];
                    }

                    public function invalidarCache(?string $clave = null): void
                    {
                    }
                },
                new GateDorado($this->bd),
                Logger::desdeEntorno(),
                [],
            ),
        );
    }

    /**
     * El servicio de credenciales real, no un doble.
     *
     * La máscara que la pantalla enseña la produce `CredencialRepo` leyendo
     * la tabla, así que un doble en memoria no probaría nada del recorrido
     * que importa: que la llave se cifra al guardarla y que lo único que
     * vuelve al navegador son cuatro dígitos.
     */
    private function credencialesEnMemoria(): Credenciales
    {
        return new CredencialesAes(
            $this->bd,
            Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pedro-pruebas.log', 'error'),
        );
    }

    /** @param array<string,string> $formulario */
    private function ctx(array $formulario = [], array $consulta = [], string $rol = 'super_admin'): Contexto
    {
        return new Contexto(
            new Peticion(
                metodo: $formulario === [] ? 'GET' : 'POST',
                ruta: '/panel/ia',
                consulta: $consulta,
                formulario: $formulario,
                ip: '127.0.0.1',
            ),
            new Usuario(
                id: '00000000-0000-0000-0000-000000000001',
                email: 'prueba@local',
                nombre: 'Prueba',
                rol: $rol,
                rolId: $rol === 'super_admin' ? 1 : 2,
                chatwootAgentId: null,
                totpActivo: true,
                activo: true,
                intentosFallidos: 0,
                bloqueadoHasta: null,
            ),
            new Permisos($this->bd),
            new Csrf(false),
            null,
        );
    }

    private function destino(\App\Core\Respuesta $r): string
    {
        return urldecode($r->cabeceras['Location'] ?? '');
    }

    /** @return array<string,mixed>|null */
    private function modelo(string $identificador): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM modelos_ia WHERE identificador = ?');
        $stmt->execute([$identificador]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    // ── El desplegable de modelos ────────────────────────────────────────

    #[Test]
    public function elDesplegableTraeLosModelosEnVivo(): void
    {
        $r = $this->controlador([
            new ModeloDescubierto('claude-opus-6', 'Claude Opus 6'),
            new ModeloDescubierto('claude-haiku-6', 'Claude Haiku 6'),
        ])->modelosDe($this->ctx(consulta: ['proveedor' => 'anthropic']));

        $datos = json_decode($r->cuerpo, true);

        self::assertSame('api', $datos['origen']);
        self::assertContains('claude-opus-6', $datos['modelos']);
        self::assertStringContainsString('en vivo', $datos['nota']);
    }

    #[Test]
    public function abrirElDesplegableNoDaDeAltaNingunModelo(): void
    {
        // Listar es una consulta para pintar una lista mientras alguien
        // elige; sincronizar es un acto de inventario. Si abrir el
        // desplegable insertara filas, el catálogo se llenaría de modelos que
        // nadie eligió solo por haber mirado.
        $antes = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM modelos_ia')->fetchColumn();

        $this->controlador([new ModeloDescubierto('claude-opus-6', 'Claude Opus 6')])
            ->modelosDe($this->ctx(consulta: ['proveedor' => 'anthropic']));

        self::assertSame(
            $antes,
            (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM modelos_ia')->fetchColumn(),
        );
    }

    #[Test]
    public function siElProveedorNoContestaSeAvisaDeQueLaListaEsDeReferencia(): void
    {
        // La lista de referencia está escrita a mano y envejece. Servirla sin
        // decirlo haría creer que el proveedor ofrece hoy lo que ofrecía
        // cuando alguien escribió esa lista.
        $r = $this->controlador(new DescubrimientoFallido('Anthropic respondió 401'))
            ->modelosDe($this->ctx(consulta: ['proveedor' => 'anthropic']));

        $datos = json_decode($r->cuerpo, true);

        self::assertSame('referencia', $datos['origen']);
        self::assertNotEmpty($datos['modelos']);
        self::assertStringContainsString('401', $datos['nota']);
        self::assertStringContainsString('desactualizada', $datos['nota']);
    }

    #[Test]
    public function sePuedeMirarElCatalogoDeUnProveedorQueNoEstaDadoDeAlta(): void
    {
        // Ver qué ofrece DeepSeek antes de decidir si se quiere DeepSeek.
        $r = $this->controlador()->modelosDe($this->ctx(consulta: ['proveedor' => 'deepseek']));

        self::assertSame(200, $r->estado);
        self::assertNotEmpty(json_decode($r->cuerpo, true)['modelos']);

        $stmt = $this->bd->pdo()->prepare('SELECT COUNT(*) FROM proveedores_ia WHERE clave = ?');
        $stmt->execute(['deepseek']);

        self::assertSame(0, (int) $stmt->fetchColumn(), 'mirar no da de alta');
    }

    // ── Guardar la configuración ─────────────────────────────────────────

    #[Test]
    public function guardarDaDeAltaElProveedorCifraLaLlaveYActivaElModelo(): void
    {
        $r = $this->controlador()->configurar($this->ctx([
            'proveedor' => 'deepseek',
            'modelo' => 'deepseek-chat',
            'api_key' => 'sk-secreta-1234',
            'costo_entrada_usd_1m' => '0.27',
            'costo_salida_usd_1m' => '1.10',
        ]));

        $stmt = $this->bd->pdo()->prepare('SELECT * FROM proveedores_ia WHERE clave = ?');
        $stmt->execute(['deepseek']);
        $proveedor = $stmt->fetch();

        self::assertNotFalse($proveedor);
        self::assertSame(1, (int) $proveedor['activo']);
        self::assertSame('China', $proveedor['pais_servidor'], 'dato de cumplimiento, no decorativo');

        $modelo = $this->modelo('deepseek-chat');

        self::assertNotNull($modelo);
        self::assertSame(1, (int) $modelo['activo']);
        self::assertSame(1, (int) $modelo['costos_verificados']);
        self::assertSame('0.2700', $modelo['costo_entrada_usd_1m']);
        self::assertStringNotContainsString('sk-secreta', $this->destino($r));
    }

    #[Test]
    public function laLlaveNuncaViajaDeVueltaAlNavegador(): void
    {
        $controlador = $this->controlador();

        $controlador->configurar($this->ctx([
            'proveedor' => 'deepseek',
            'modelo' => 'deepseek-chat',
            'api_key' => 'sk-secreta-1234',
            'costo_entrada_usd_1m' => '0.27',
            'costo_salida_usd_1m' => '1.10',
        ]));

        $html = $controlador->inicio($this->ctx())->cuerpo;

        self::assertStringNotContainsString('sk-secreta-1234', $html);
        // Ocho puntos y los tres últimos caracteres: bastante para reconocer
        // cuál de dos llaves está puesta, muy poco para reconstruir ninguna.
        self::assertStringContainsString('••••••••234', $html, 'la máscara sí, el valor no');
    }

    #[Test]
    public function dejarLaLlaveVaciaConservaLaGuardada(): void
    {
        // Sin esto, cambiar de modelo obligaría a volver a pegar la llave, y
        // quien no la tenga a mano la borraría sin querer.
        $controlador = $this->controlador();

        $controlador->configurar($this->ctx([
            'proveedor' => 'deepseek',
            'modelo' => 'deepseek-chat',
            'api_key' => 'sk-secreta-1234',
            'costo_entrada_usd_1m' => '0.27',
            'costo_salida_usd_1m' => '1.10',
        ]));

        $controlador->configurar($this->ctx([
            'proveedor' => 'deepseek',
            'modelo' => 'deepseek-reasoner',
            'api_key' => '',
            'costo_entrada_usd_1m' => '0.55',
            'costo_salida_usd_1m' => '2.19',
        ]));

        $html = $controlador->inicio($this->ctx())->cuerpo;

        self::assertStringContainsString('••••••••234', $html);
        self::assertSame(1, (int) $this->modelo('deepseek-reasoner')['activo']);
    }

    #[Test]
    public function sinCostoElModeloQuedaInactivoYSeDicePorQue(): void
    {
        // Un modelo a costo cero nunca agota `presupuesto_ia_mensual_usd`: el
        // corte deja de cortar sin que nadie se entere. Es la única puerta de
        // esta pantalla que no se puede saltar.
        $r = $this->controlador()->configurar($this->ctx([
            'proveedor' => 'deepseek',
            'modelo' => 'deepseek-chat',
            'api_key' => 'sk-secreta-1234',
            'costo_entrada_usd_1m' => '',
            'costo_salida_usd_1m' => '',
        ]));

        self::assertSame(0, (int) $this->modelo('deepseek-chat')['activo']);
        self::assertStringContainsString('presupuesto', $this->destino($r));
    }

    #[Test]
    public function guardarNoAsciendeAPrimarioSinConjuntoDorado(): void
    {
        // El atajo de esta pantalla no puede saltarse el ADR-016: elegir un
        // modelo lo deja listo, pero quien habla con los clientes se decide
        // con evidencia de que respeta las reglas inviolables.
        $r = $this->controlador()->configurar($this->ctx([
            'proveedor' => 'deepseek',
            'modelo' => 'deepseek-chat',
            'api_key' => 'sk-secreta-1234',
            'costo_entrada_usd_1m' => '0.27',
            'costo_salida_usd_1m' => '1.10',
        ]));

        self::assertSame(0, (int) $this->modelo('deepseek-chat')['es_primario']);
        self::assertStringContainsString('conjunto dorado', $this->destino($r));
    }

    #[Test]
    public function guardarSinElegirModeloNoCreaNada(): void
    {
        $antes = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM proveedores_ia')->fetchColumn();

        $r = $this->controlador()->configurar($this->ctx([
            'proveedor' => 'deepseek',
            'modelo' => '',
            'api_key' => 'sk-secreta-1234',
        ]));

        self::assertStringContainsString('Elija un modelo', $this->destino($r));
        self::assertSame(
            $antes,
            (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM proveedores_ia')->fetchColumn(),
        );
    }

    #[Test]
    public function unProveedorDesconocidoExigeSuUrlBase(): void
    {
        $r = $this->controlador()->configurar($this->ctx([
            'proveedor' => 'mi-servidor',
            'modelo' => 'lo-que-sea',
            'base_url' => 'no-es-una-url',
        ]));

        self::assertStringContainsString('URL base', $this->destino($r));
    }
}
