<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Csrf;
use App\Core\Peticion;
use App\Modelos\Usuario;
use App\Panel\AuditoriaControlador;
use App\Panel\ConfiguracionControlador;
use App\Panel\Contexto;
use App\Panel\TableroControlador;
use App\Panel\TarifasControlador;
use App\Panel\UsuariosControlador;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Repositorios\SesionRepo;
use App\Repositorios\UsuarioRepo;
use App\Servicios\Autenticacion;
use App\Servicios\ConfigMysql;
use App\Servicios\Permisos;
use App\Servicios\SinPermisoException;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Los módulos del panel, ejercitados sin navegador.
 *
 * `bin/verificar-panel.mjs` cubre lo que solo se ve armando la petición de
 * verdad —cookies, CSRF sobre HTTP, redirecciones—. Esto cubre las reglas de
 * negocio de cada módulo, que es donde están las decisiones que importan y
 * donde una regresión no se nota a simple vista.
 */
#[Group('critica')]
final class PanelTest extends CasoBaseBd
{
    private Permisos $permisos;
    private ConfigMysql $config;
    private AuditoriaRepo $auditoria;
    private UsuarioRepo $usuarios;
    private Logger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $cifrado = Cifrado::desdeEntorno();

        $this->permisos = new Permisos($this->bd);
        $this->config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-cfg-{$sufijo}.json",
        );
        $this->auditoria = new AuditoriaRepo($this->bd);
        $this->usuarios = new UsuarioRepo($this->bd, $cifrado);
        $this->log = new Logger(sys_get_temp_dir() . '/pa-panel.log', 'error');
    }

    // ── Andamiaje ────────────────────────────────────────────────────────

    private function usuario(string $rol): Usuario
    {
        return new Usuario(
            id: '00000000-0000-0000-0000-00000000000' . (int) (strlen($rol) % 9),
            email: "{$rol}@ejemplo.com",
            nombre: ucfirst($rol) . ' de prueba',
            rol: $rol,
            rolId: 1,
            totpActivo: true,
            activo: true,
            intentosFallidos: 0,
            bloqueadoHasta: null,
        );
    }

    /** @param array<string,mixed> $formulario */
    private function ctx(string $rol, array $formulario = [], array $consulta = []): Contexto
    {
        return new Contexto(
            new Peticion(
                metodo: $formulario === [] ? 'GET' : 'POST',
                ruta: '/panel',
                consulta: $consulta,
                formulario: $formulario,
                ip: '190.85.1.1',
            ),
            $this->usuario($rol),
            $this->permisos,
            new Csrf(false),
        );
    }

    private function tablero(): TableroControlador
    {
        return new TableroControlador(
            $this->bd,
            $this->config,
            new \App\Servicios\MetricasLanding($this->bd, $this->config),
        );
    }

    private function configuracion(): ConfiguracionControlador
    {
        return new ConfiguracionControlador($this->config);
    }

    private function tarifas(): TarifasControlador
    {
        return new TarifasControlador($this->bd, $this->auditoria);
    }

    private function modalidadId(): string
    {
        return (string) $this->bd->pdo()->query('SELECT id FROM modalidades_asesoria LIMIT 1')->fetchColumn();
    }

    // ── Tablero ──────────────────────────────────────────────────────────

    #[Test]
    public function elTableroMuestraElPrecioQueSePintaEnLaLanding(): void
    {
        $html = $this->tablero()->inicio($this->ctx('abogado'))->cuerpo;

        // El precio sobrevivió al recorte porque no lo usaba la pasarela: lo
        // pintan la landing y el diagnóstico. Si deja de verse aquí, se
        // cambia a ciegas.
        self::assertStringContainsString('Precio de la asesoría', $html);
    }

    #[Test]
    public function elTableroDiceHastaDondeMide(): void
    {
        $html = $this->tablero()->inicio($this->ctx('abogado'))->cuerpo;

        // Lo que esta pantalla llama «conversión» es a conversación iniciada,
        // no a cliente: lo que pasa después del clic ocurre en WhatsApp. Sin
        // decirlo en la propia pantalla, el número se lee como lo que no es.
        self::assertStringContainsString('Hasta el clic a WhatsApp', $html);
    }

    #[Test]
    public function elContadorVeElTableroPeroNoLasTarifas(): void
    {
        // En la matriz de PANEL_ADMIN §3 el contador tiene el tablero en
        // «lectura», así que entrar debe funcionar…
        self::assertSame(200, $this->tablero()->inicio($this->ctx('contador'))->estado);

        // …pero agenda y tarifas no aparecen para él en absoluto.
        $this->expectException(SinPermisoException::class);
        $this->tarifas()->listar($this->ctx('contador'));
    }

    // ── Configuración ────────────────────────────────────────────────────

    #[Test]
    public function elFormularioSePintaDesdeLosMetadatosDeLasFilas(): void
    {
        $html = $this->configuracion()->listar($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('Minutos de reserva sin pago', $html);
        self::assertStringContainsString('minutos_reserva_pago', $html);
        // La ayuda de la propia fila, no un texto del código.
        self::assertStringContainsString('Tiempo que se aparta el cupo', $html);
    }

    #[Test]
    public function guardarRespetaElRangoDeLaFila(): void
    {
        $r = $this->configuracion()->guardar(
            $this->ctx('abogado', ['clave' => 'minutos_reserva_pago', 'valor' => '9999']),
        );

        self::assertSame(302, $r->estado);
        self::assertStringContainsString('no+puede+ser+mayor', $r->cabeceras['Location']);
        self::assertSame(45, $this->config->get('minutos_reserva_pago'), 'no debió cambiar');
    }

    #[Test]
    public function guardarEscribeElHistorialConMotivo(): void
    {
        $this->configuracion()->guardar($this->ctx('abogado', [
            'clave' => 'minutos_reserva_pago',
            'valor' => '120',
            'motivo' => 'mucha caída entre reserva y pago',
        ]));

        self::assertSame(120, $this->config->get('minutos_reserva_pago'));

        $fila = $this->bd->pdo()->query(
            "SELECT motivo FROM configuraciones_historial WHERE clave='minutos_reserva_pago'"
        )->fetch();

        self::assertIsArray($fila);
        self::assertSame('mucha caída entre reserva y pago', $fila['motivo']);
    }

    #[Test]
    public function elAbogadoNoTocaLasFilasReservadasAlAdministradorTecnico(): void
    {
        // Es el «✔ (parcial)» de la matriz: `pasarela_activa` tiene
        // rol_minimo = super_admin.
        $r = $this->configuracion()->guardar(
            $this->ctx('abogado', ['clave' => 'pasarela_activa', 'valor' => 'bold']),
        );

        self::assertStringContainsString('administrador+t', $r->cabeceras['Location']);
        self::assertSame('wompi', $this->config->get('pasarela_activa'));
    }

    #[Test]
    public function unaClaveInventadaNoSeGuarda(): void
    {
        $r = $this->configuracion()->guardar(
            $this->ctx('abogado', ['clave' => 'clave_inventada', 'valor' => 'x']),
        );

        self::assertStringContainsString('no+existe', $r->cabeceras['Location']);
    }

    // ── Tarifas ──────────────────────────────────────────────────────────

    #[Test]
    public function elPrecioSeCambiaSinTocarCodigo(): void
    {
        $r = $this->tarifas()->guardar($this->ctx('abogado', [
            'id' => $this->modalidadId(),
            'nombre' => 'Asesoría virtual',
            'precio_cop' => '450000',
            'duracion_min' => '60',
            'modalidad' => 'virtual',
            'requiere_pago' => '1',
            'activo' => '1',
        ]));

        self::assertSame(302, $r->estado);
        self::assertSame(
            450000,
            (int) $this->bd->pdo()->query('SELECT precio_cop FROM modalidades_asesoria')->fetchColumn(),
        );
    }

    #[Test]
    public function rechazaCentavosDondeVanPesos(): void
    {
        // A $400.000 la tarifa, un cero de más son cuatro millones. El error
        // es fácil justo porque la pasarela SÍ cobra en centavos.
        $r = $this->tarifas()->guardar($this->ctx('abogado', [
            'id' => $this->modalidadId(),
            'precio_cop' => '40000000',
            'duracion_min' => '60',
        ]));

        self::assertStringContainsString('PESOS', urldecode($r->cabeceras['Location']));
        self::assertSame(
            400000,
            (int) $this->bd->pdo()->query('SELECT precio_cop FROM modalidades_asesoria')->fetchColumn(),
        );
    }

    #[Test]
    public function elCambioDePrecioQuedaAuditadoConAntesYDespues(): void
    {
        $this->tarifas()->guardar($this->ctx('abogado', [
            'id' => $this->modalidadId(),
            'precio_cop' => '500000',
            'duracion_min' => '60',
        ]));

        $detalle = (string) $this->bd->pdo()->query(
            "SELECT detalle FROM auditoria WHERE entidad='modalidad' AND accion='actualizar'"
        )->fetchColumn();

        self::assertStringContainsString('400000', $detalle);
        self::assertStringContainsString('500000', $detalle);
    }

    #[Test]
    public function avisaDeQueLasReservasVivasConservanSuPrecio(): void
    {
        $r = $this->tarifas()->guardar($this->ctx('abogado', [
            'id' => $this->modalidadId(),
            'precio_cop' => '480000',
            'duracion_min' => '60',
        ]));

        self::assertStringContainsString('conservan', urldecode($r->cabeceras['Location']));
    }

    #[Test]
    public function unaDuracionAbsurdaSeRechaza(): void
    {
        $r = $this->tarifas()->guardar($this->ctx('abogado', [
            'id' => $this->modalidadId(),
            'precio_cop' => '400000',
            'duracion_min' => '1',
        ]));

        self::assertStringContainsString('duraci', urldecode($r->cabeceras['Location']));
    }

    #[Test]
    public function elAsistenteNoEditaTarifas(): void
    {
        $this->expectException(SinPermisoException::class);

        $this->tarifas()->guardar($this->ctx('asistente', [
            'id' => $this->modalidadId(),
            'precio_cop' => '1',
            'duracion_min' => '60',
        ]));
    }

    // ── Auditoría ────────────────────────────────────────────────────────

    #[Test]
    public function laBitacoraSeListaYSeFiltra(): void
    {
        $this->auditoria->registrar('modalidad', null, 'actualizar', 'pedro@ejemplo.com', ['x' => 1]);
        $this->auditoria->registrar('usuario', null, 'crear', 'pedro@ejemplo.com');

        $ctrl = new AuditoriaControlador($this->auditoria);

        $todo = $ctrl->listar($this->ctx('super_admin'))->cuerpo;
        self::assertStringContainsString('modalidad', $todo);
        self::assertStringContainsString('usuario', $todo);

        $filtrado = $ctrl->listar($this->ctx('super_admin', [], ['entidad' => 'usuario']))->cuerpo;
        self::assertStringContainsString('crear', $filtrado);
    }

    #[Test]
    public function elAsistenteNoVeLaBitacora(): void
    {
        $this->expectException(SinPermisoException::class);

        (new AuditoriaControlador($this->auditoria))->listar($this->ctx('asistente'));
    }

    // ── Usuarios ─────────────────────────────────────────────────────────

    private function usuariosCtrl(): UsuariosControlador
    {
        return new UsuariosControlador(
            $this->usuarios,
            new Autenticacion(
                $this->usuarios,
                new SesionRepo($this->bd),
                new IntentoAccesoRepo($this->bd),
                $this->auditoria,
            ),
            $this->auditoria,
            $this->bd,
            $this->log,
        );
    }

    #[Test]
    public function unaContrasenaCortaSeRechaza(): void
    {
        $r = $this->usuariosCtrl()->crear($this->ctx('super_admin', [
            'email' => 'nuevo@ejemplo.com', 'nombre' => 'Nuevo',
            'password' => 'corta', 'rol_id' => '2',
        ]));

        self::assertStringContainsString('12+caracteres', $r->cabeceras['Location']);
        self::assertNull($this->usuarios->porEmail('nuevo@ejemplo.com'));
    }

    #[Test]
    public function unCorreoInvalidoSeRechaza(): void
    {
        $r = $this->usuariosCtrl()->crear($this->ctx('super_admin', [
            'email' => 'no-es-correo', 'nombre' => 'X',
            'password' => 'contrasena-larga-de-verdad', 'rol_id' => '2',
        ]));

        self::assertStringContainsString('correo+no+es+v', $r->cabeceras['Location']);
    }

    #[Test]
    public function unCorreoRepetidoSeRechazaConMensajeClaro(): void
    {
        $datos = [
            'email' => 'repetido@ejemplo.com', 'nombre' => 'Uno',
            'password' => 'contrasena-larga-de-verdad', 'rol_id' => '2',
        ];

        $this->usuariosCtrl()->crear($this->ctx('super_admin', $datos));
        $r = $this->usuariosCtrl()->crear($this->ctx('super_admin', $datos));

        self::assertStringContainsString('Ya+existe', $r->cabeceras['Location']);
    }

    #[Test]
    public function crearUnUsuarioQuedaAuditado(): void
    {
        $this->usuariosCtrl()->crear($this->ctx('super_admin', [
            'email' => 'auditado@ejemplo.com', 'nombre' => 'Auditado',
            'password' => 'contrasena-larga-de-verdad', 'rol_id' => '2',
        ]));

        $fila = $this->bd->pdo()->query(
            "SELECT actor FROM auditoria WHERE entidad='usuario' AND accion='crear'"
        )->fetch();

        self::assertIsArray($fila);
        self::assertSame('super_admin@ejemplo.com', $fila['actor']);
    }

    #[Test]
    public function elAbogadoNoCreaUsuarios(): void
    {
        // En la matriz el abogado tiene `usuarios.ver`, no `usuarios.editar`.
        $this->expectException(SinPermisoException::class);

        $this->usuariosCtrl()->crear($this->ctx('abogado', [
            'email' => 'x@y.co', 'nombre' => 'X',
            'password' => 'contrasena-larga-de-verdad', 'rol_id' => '2',
        ]));
    }

    #[Test]
    public function elListadoDeUsuariosSePinta(): void
    {
        $html = $this->usuariosCtrl()->listar($this->ctx('super_admin'))->cuerpo;

        self::assertStringContainsString('Crear usuario', $html);
    }
}
