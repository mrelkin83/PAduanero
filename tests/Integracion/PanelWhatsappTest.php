<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Csrf;
use App\Core\Peticion;
use App\Modelos\Usuario;
use App\Panel\Contexto;
use App\Panel\WhatsappControlador;
use App\Repositorios\AuditoriaRepo;
use App\Servicios\Permisos;
use App\Servicios\SinPermisoException;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use App\Wa\MotorWa;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * El módulo de WhatsApp del panel: las reglas que no se ven a simple vista.
 *
 * Lo que se defiende aquí: que los permisos corten donde la matriz dice, que
 * los secretos se guarden cifrados y un envío vacío no los pise, que el motor
 * no se pueda encender a medio configurar, y que el horario inválido no entre.
 */
#[Group('critica')]
final class PanelWhatsappTest extends CasoBaseBd
{
    private Permisos $permisos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permisos = new Permisos($this->bd);

        // Las tablas wa_* no están en las semillas restauradas por la base:
        // se devuelven aquí al estado de la migración 0016 para que un caso
        // no herede la configuración del anterior.
        $pdo = $this->bd->pdo();
        $pdo->exec("UPDATE wa_config SET activo = 0, evolution_url = NULL,
                    evolution_instancia = NULL, evolution_apikey = NULL,
                    llm_proveedor = NULL, llm_modelo = NULL, llm_api_key = NULL,
                    pago_modo = 'mixto', pago_datos_transferencia = NULL,
                    pago_transferencia_json = NULL WHERE id = 1");
        $pdo->exec('TRUNCATE TABLE wa_citas');
        $pdo->exec('TRUNCATE TABLE wa_conversaciones');
        $pdo->exec('TRUNCATE TABLE wa_mensajes');
        $pdo->exec('TRUNCATE TABLE wa_modelos');
    }

    private function ctrl(): WhatsappControlador
    {
        return new WhatsappControlador(
            $this->bd,
            Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pa-wa-panel.log', 'error'),
            new AuditoriaRepo($this->bd),
        );
    }

    /** @param array<string,mixed> $formulario */
    private function ctx(string $rol, array $formulario = [], array $consulta = []): Contexto
    {
        return new Contexto(
            new Peticion(
                metodo: $formulario === [] ? 'GET' : 'POST',
                ruta: '/panel/whatsapp',
                consulta: $consulta,
                formulario: $formulario,
                ip: '190.85.1.1',
            ),
            new Usuario(
                id: '00000000-0000-0000-0000-000000000001',
                email: "{$rol}@ejemplo.com",
                nombre: 'De prueba',
                rol: $rol,
                rolId: 1,
                totpActivo: true,
                activo: true,
                intentosFallidos: 0,
                bloqueadoHasta: null,
            ),
            $this->permisos,
            new Csrf(false),
        );
    }

    #[Test]
    public function laPantallaSePintaParaElAbogado(): void
    {
        $html = $this->ctrl()->ver($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('Motor: apagado', $html);
        // El recordatorio de datos personales tiene que estar a la vista
        // ANTES del botón de encender, no en un documento aparte.
        self::assertStringContainsString('política de tratamiento', $html);
    }

    #[Test]
    public function elContadorNoVeLaPantalla(): void
    {
        $this->expectException(SinPermisoException::class);
        $this->ctrl()->ver($this->ctx('contador'));
    }

    #[Test]
    public function elAbogadoNoTocaLaConexion(): void
    {
        // En la matriz el abogado tiene ia.proveedores.ver, no .escribir:
        // puede leer cómo está conectado el bot, no cambiarle la tubería.
        $this->expectException(SinPermisoException::class);

        $this->ctrl()->guardarConexion($this->ctx('abogado', [
            'evolution_url' => 'http://localhost:8080',
        ]));
    }

    #[Test]
    public function laApikeySeGuardaCifradaYDescifraDeVuelta(): void
    {
        // La vía de la tubería es la de consola/despliegue (wa-configurar),
        // no el formulario: se siembra directo y se comprueba el cifrado.
        $db = MotorWa::conectar($this->bd, Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pa-wa-panel.log', 'error'), dirname(__DIR__, 2));
        WaConfig::guardar($db, [
            'evolution_url' => 'http://localhost:8080',
            'evolution_instancia' => 'pedro',
            'evolution_apikey' => 'clave-secreta-de-prueba',
        ]);

        $fila = $this->bd->pdo()->query('SELECT evolution_apikey FROM wa_config WHERE id = 1')->fetch();

        // El secreto NO está en claro en la base…
        self::assertStringNotContainsString('clave-secreta-de-prueba', (string) $fila['evolution_apikey']);

        // …pero el motor lo descifra de vuelta con el cifrado de la casa.
        self::assertSame(
            'clave-secreta-de-prueba',
            WaConfig::secreto(WaConfig::cargar($db, true), 'evolution_apikey'),
        );
    }

    #[Test]
    public function elFormularioNoPuedeTocarLaTuberia(): void
    {
        // La URL y la API Key las fija el despliegue. Un POST que intente
        // cambiarlas —aunque venga de un super_admin legítimo— solo puede
        // cambiar el nombre de la instancia.
        $db = MotorWa::conectar($this->bd, Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pa-wa-panel.log', 'error'), dirname(__DIR__, 2));
        WaConfig::guardar($db, [
            'evolution_url' => 'http://127.0.0.1:8080',
            'evolution_instancia' => 'pedro',
            'evolution_apikey' => 'la-clave-del-despliegue',
        ]);

        $this->ctrl()->guardarConexion($this->ctx('super_admin', [
            'evolution_url' => 'http://atacante.example',
            'evolution_instancia' => 'pedro-2',
            'evolution_apikey' => 'clave-colada-en-el-post',
        ]));

        $cfg = WaConfig::cargar($db, true);
        self::assertSame('http://127.0.0.1:8080', $cfg['evolution_url']);
        self::assertSame('pedro-2', $cfg['evolution_instancia']);
        self::assertSame('la-clave-del-despliegue', WaConfig::secreto($cfg, 'evolution_apikey'));
    }

    #[Test]
    public function guardarSinUrlSembradaLaTomaDelEntorno(): void
    {
        // Con la base virgen, guardar la instancia no puede dejar la URL a
        // medias: el controlador la completa con la del entorno.
        $this->ctrl()->guardarConexion($this->ctx('super_admin', [
            'evolution_instancia' => 'pedro',
        ]));

        $db = MotorWa::conectar($this->bd, Cifrado::desdeEntorno(),
            new Logger(sys_get_temp_dir() . '/pa-wa-panel.log', 'error'), dirname(__DIR__, 2));
        $cfg = WaConfig::cargar($db, true);
        self::assertNotEmpty($cfg['evolution_url']);
        self::assertSame('pedro', $cfg['evolution_instancia']);
    }

    #[Test]
    public function elMotorNoSePuedeEncenderAMedioConfigurar(): void
    {
        $r = $this->ctrl()->encender($this->ctx('super_admin', ['x' => '1']));

        self::assertSame(302, $r->estado);
        self::assertStringContainsString('falta', urldecode($r->cabeceras['Location']));
        self::assertSame(0, (int) $this->bd->pdo()
            ->query('SELECT activo FROM wa_config WHERE id = 1')->fetchColumn());
    }

    #[Test]
    public function conTodoConfiguradoEnciendeYQuedaAuditado(): void
    {
        $this->bd->pdo()->exec(
            "UPDATE wa_config SET evolution_url = 'http://localhost:8080',
             evolution_instancia = 'pedro', llm_proveedor = 'anthropic',
             llm_modelo = 'claude-sonnet-5', llm_api_key = 'cifrada' WHERE id = 1"
        );

        $r = $this->ctrl()->encender($this->ctx('super_admin', ['x' => '1']));

        self::assertStringContainsString('encendido', urldecode($r->cabeceras['Location']));
        self::assertSame(1, (int) $this->bd->pdo()
            ->query('SELECT activo FROM wa_config WHERE id = 1')->fetchColumn());
        self::assertSame(1, (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM auditoria WHERE entidad = 'whatsapp' AND accion = 'encender'"
        )->fetchColumn());
    }

    #[Test]
    public function elDesplegableDeModelosSirveLoConocidoSinSalirALaRed(): void
    {
        $this->bd->pdo()->exec(
            "INSERT INTO wa_modelos (proveedor, modelo_id, nombre) VALUES ('anthropic', 'claude-sonnet-5', 'Claude Sonnet 5')"
        );

        $r = $this->ctrl()->modelos($this->ctx('abogado', [], ['proveedor' => 'anthropic']));
        $json = json_decode($r->cuerpo, true);

        self::assertTrue($json['ok']);
        self::assertSame('claude-sonnet-5', $json['modelos'][0]['modelo_id']);
    }

    #[Test]
    public function sincronizarSinClaveDiceQueFaltaLaClave(): void
    {
        // Sin clave escrita ni guardada no hay a qué llamar: el error debe
        // decirlo, no un genérico «no devolvió modelos» (lección del origen).
        $r = $this->ctrl()->sincronizarModelos($this->ctx('super_admin', ['proveedor' => 'anthropic']));
        $json = json_decode($r->cuerpo, true);

        self::assertFalse($json['ok']);
        self::assertStringContainsString('API Key', $json['error']);
    }

    #[Test]
    public function losDatosDeTransferenciaSeComponenDesdeLosCampos(): void
    {
        $this->ctrl()->guardarCobro($this->ctx('abogado', [
            'pago_modo' => 'manual',
            'trans_nequi' => '315 992 3676',
            'trans_breb' => '@pedro',
            'trans_banco_nombre' => 'Bancolombia',
            'trans_banco_tipo' => 'ahorros',
            'trans_banco_numero' => '123-456789-01',
            'trans_titular' => 'Pedro Pérez',
        ]));

        $texto = (string) $this->bd->pdo()
            ->query('SELECT pago_datos_transferencia FROM wa_config WHERE id = 1')->fetchColumn();

        // Lo que el bot dicta: una línea por método, solo los llenos, y el
        // titular al final. El Nequi entra normalizado a dígitos.
        self::assertSame(
            "Nequi: 3159923676\nBre-B (llave): @pedro\nBancolombia — cuenta de ahorros Nº 123-456789-01\nTitular: Pedro Pérez",
            $texto,
        );
        self::assertStringNotContainsString('Daviplata', $texto, 'lo vacío no se dicta');

        // Y la fuente estructurada queda para volver a pintar el formulario.
        $json = json_decode((string) $this->bd->pdo()
            ->query('SELECT pago_transferencia_json FROM wa_config WHERE id = 1')->fetchColumn(), true);
        self::assertSame('3159923676', $json['nequi']);
    }

    #[Test]
    public function unaCuentaSinTitularSeRechaza(): void
    {
        $r = $this->ctrl()->guardarCobro($this->ctx('abogado', [
            'pago_modo' => 'manual',
            'trans_nequi' => '3159923676',
        ]));

        self::assertStringContainsString('titular', urldecode($r->cabeceras['Location']));
        self::assertNull($this->bd->pdo()
            ->query('SELECT pago_datos_transferencia FROM wa_config WHERE id = 1')->fetchColumn());
    }

    #[Test]
    public function unNequiQueNoEsCelularSeRechaza(): void
    {
        $r = $this->ctrl()->guardarCobro($this->ctx('abogado', [
            'pago_modo' => 'manual',
            'trans_nequi' => '601234',
            'trans_titular' => 'Pedro',
        ]));

        self::assertStringContainsString('10 dígitos', urldecode($r->cabeceras['Location']));
    }

    #[Test]
    public function unHorarioConCierreAntesDeLaAperturaSeRechaza(): void
    {
        $r = $this->ctrl()->guardarHorario($this->ctx('abogado', [
            'desde_1' => '18:00', 'hasta_1' => '08:00',
        ]));

        self::assertStringContainsString('inválido', urldecode($r->cabeceras['Location']));
    }

    #[Test]
    public function elHorarioValidoSeGuardaComoJsonPorDia(): void
    {
        $this->ctrl()->guardarHorario($this->ctx('abogado', [
            'desde_1' => '08:00', 'hasta_1' => '18:00',
            'desde_6' => '09:00', 'hasta_6' => '12:00',
        ]));

        $json = (string) $this->bd->pdo()
            ->query('SELECT horario_atencion FROM wa_config WHERE id = 1')->fetchColumn();
        $h = json_decode($json, true);

        self::assertSame(['desde' => '08:00', 'hasta' => '18:00'], $h['1']);
        self::assertSame(['desde' => '09:00', 'hasta' => '12:00'], $h['6']);
        self::assertArrayNotHasKey('0', $h, 'el domingo quedó cerrado');
    }

    #[Test]
    public function elNumeroDeGuardiaSeGuardaNormalizadoYElInvalidoSeRechaza(): void
    {
        // Con espacios, «+» y guiones: se normaliza a dígitos pelados, que es
        // lo que HumanHandoff le pasa al canal al avisar.
        $this->ctrl()->guardarHorario($this->ctx('abogado', [
            'desde_1' => '08:00', 'hasta_1' => '18:00',
            'handoff_numero' => '+57 300 123-4567',
        ]));
        $n = (string) $this->bd->pdo()
            ->query('SELECT handoff_numero FROM wa_config WHERE id = 1')->fetchColumn();
        self::assertSame('573001234567', $n);

        // Un número sin indicativo (muy corto) se rechaza y no pisa el guardado.
        $r = $this->ctrl()->guardarHorario($this->ctx('abogado', [
            'desde_1' => '08:00', 'hasta_1' => '18:00',
            'handoff_numero' => '12345',
        ]));
        self::assertStringContainsString('inválido', urldecode($r->cabeceras['Location']));
        $n = (string) $this->bd->pdo()
            ->query('SELECT handoff_numero FROM wa_config WHERE id = 1')->fetchColumn();
        self::assertSame('573001234567', $n);
    }

    #[Test]
    public function vozEImagenesSeGuardanYLoInventadoCaeALoSeguro(): void
    {
        $this->ctrl()->guardarMedia($this->ctx('super_admin', [
            'vision_proveedor' => 'anthropic',
            'vision_modelo' => 'claude-sonnet-5',
            'stt_proveedor' => 'groq',
            'stt_modelo' => 'whisper-large-v3',
            'stt_api_key' => 'clave-stt-secreta',
            'tts_modo' => 'un-modo-que-no-existe',
            'tts_proveedor' => 'un-proveedor-inventado',
        ]));

        $fila = $this->bd->pdo()->query(
            'SELECT vision_proveedor, stt_proveedor, stt_api_key, tts_modo, tts_proveedor
             FROM wa_config WHERE id = 1')->fetch();

        self::assertSame('anthropic', $fila['vision_proveedor']);
        self::assertSame('groq', $fila['stt_proveedor']);
        // El secreto no está en claro en la base…
        self::assertStringNotContainsString('clave-stt-secreta', (string) $fila['stt_api_key']);
        // …y lo que no está en el catálogo cae a lo seguro, no a la base.
        self::assertSame('espejo', $fila['tts_modo']);
        self::assertSame('', $fila['tts_proveedor']);
    }

    #[Test]
    public function laRutaDeConversacionLaEditaElAbogado(): void
    {
        $this->ctrl()->guardarAgente($this->ctx('abogado', [
            'nombre' => 'Asistente',
            'instrucciones' => 'RUTA NUEVA: primero el caso, después la cita.',
        ]));

        self::assertStringContainsString(
            'RUTA NUEVA',
            (string) $this->bd->pdo()->query('SELECT instrucciones FROM wa_agentes WHERE id = 1')->fetchColumn(),
        );
    }

    #[Test]
    public function lasCitasSePintanConSuEstadoDePago(): void
    {
        $pdo = $this->bd->pdo();
        $modalidad = (string) $pdo->query('SELECT id FROM modalidades_asesoria LIMIT 1')->fetchColumn();
        $pdo->exec("INSERT INTO wa_conversaciones (telefono) VALUES ('573001112233')");
        $pdo->prepare(
            "INSERT INTO wa_citas (conversacion_id, modalidad_id, nombre, telefono, inicio, precio_cop, estado)
             VALUES (1, ?, 'Cliente De Prueba', '573001112233', '2030-01-15 09:00:00', 400000, 'reservada')"
        )->execute([$modalidad]);

        $html = $this->ctrl()->citas($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('Cliente De Prueba', $html);
        self::assertStringContainsString('Reservada', $html);
        self::assertStringContainsString('400.000', $html);
    }

    #[Test]
    public function unaConversacionSeLeeYSePuedeDevolverALaIa(): void
    {
        $pdo = $this->bd->pdo();
        $pdo->exec("INSERT INTO wa_conversaciones (telefono, estado) VALUES ('573001112233', 'HUMANO_ATENDIENDO')");
        $pdo->exec("INSERT INTO wa_mensajes (conversacion_id, direccion, contenido) VALUES (1, 'entrante', 'Me aprehendieron la mercancía')");

        $html = $this->ctrl()->conversaciones($this->ctx('abogado', [], ['ver' => '1']))->cuerpo;
        self::assertStringContainsString('Me aprehendieron', $html);
        self::assertStringContainsString('Devolver a la IA', $html);

        $this->ctrl()->reanudarIa($this->ctx('abogado', ['conversacion_id' => '1']));
        self::assertSame(
            'IA_ACTIVA',
            (string) $pdo->query('SELECT estado FROM wa_conversaciones WHERE id = 1')->fetchColumn(),
        );
    }
}
