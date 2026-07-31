<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\AuditoriaRepo;
use App\Repositorios\IntentoAccesoRepo;
use App\Repositorios\SesionRepo;
use App\Repositorios\UsuarioRepo;
use App\Servicios\Autenticacion;
use App\Soporte\Cifrado;
use App\Soporte\Totp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

#[Group('critica')]
final class AutenticacionTest extends CasoBaseBd
{
    private const CLAVE = 'una-contrasena-larga-de-verdad';
    private const IP = '190.85.1.1';

    private Autenticacion $auth;
    private UsuarioRepo $usuarios;
    private SesionRepo $sesiones;
    private string $email = 'pedro@ejemplo.com';

    protected function setUp(): void
    {
        parent::setUp();

        $cifrado = Cifrado::desdeEntorno();
        $this->usuarios = new UsuarioRepo($this->bd, $cifrado);
        $this->sesiones = new SesionRepo($this->bd);

        $this->auth = new Autenticacion(
            $this->usuarios,
            $this->sesiones,
            new IntentoAccesoRepo($this->bd),
            new AuditoriaRepo($this->bd),
        );

        $rol = (int) $this->bd->pdo()->query("SELECT id FROM roles WHERE clave='abogado'")->fetchColumn();
        $this->usuarios->crear($this->email, 'Pedro', self::CLAVE, $rol);
    }

    #[Test]
    public function laContrasenaCorrectaEntra(): void
    {
        $r = $this->auth->verificarCredenciales($this->email, self::CLAVE, self::IP);

        self::assertTrue($r['ok']);
        self::assertSame('abogado', $r['usuario']->rol);
    }

    #[Test]
    public function laContrasenaSeGuardaConArgon2id(): void
    {
        $hash = $this->bd->pdo()->query('SELECT password_hash FROM usuarios')->fetchColumn();

        self::assertStringStartsWith('$argon2id$', (string) $hash);
        self::assertStringNotContainsString(self::CLAVE, (string) $hash);
    }

    #[Test]
    public function elMensajeDeErrorNoDistingueUsuarioInexistenteDeClaveMala(): void
    {
        // Distinguirlos convierte el formulario en un enumerador de cuentas.
        $a = $this->auth->verificarCredenciales($this->email, 'equivocada', self::IP);
        $b = $this->auth->verificarCredenciales('nadie@ejemplo.com', 'equivocada', self::IP);

        self::assertFalse($a['ok']);
        self::assertFalse($b['ok']);
        self::assertSame($a['motivo'], $b['motivo']);
    }

    #[Test]
    public function alQuintoIntentoLaCuentaSeBloquea(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->verificarCredenciales($this->email, 'equivocada', self::IP);
        }

        // Ahora ni siquiera la contraseña correcta pasa.
        $r = $this->auth->verificarCredenciales($this->email, self::CLAVE, self::IP);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('bloqueada', $r['motivo']);
    }

    #[Test]
    public function laEsperaCreceConCadaIntento(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->usuarios->registrarFallo($this->email);
        }

        $quinto = $this->usuarios->minutosDeBloqueo($this->email);

        for ($i = 0; $i < 3; $i++) {
            $this->usuarios->registrarFallo($this->email);
        }

        // 5→1 min, 8→8 min. Creciente, para que la fuerza bruta no compense.
        self::assertGreaterThan((int) $quinto, (int) $this->usuarios->minutosDeBloqueo($this->email));
    }

    #[Test]
    public function elRateLimitPorIpFrenaElBarridoDeCorreos(): void
    {
        // Mil contraseñas contra mil correos distintos nunca dispara el
        // bloqueo por cuenta: ninguna cuenta llega a cinco intentos. Esto es
        // lo que lo detiene.
        for ($i = 0; $i < 20; $i++) {
            $this->auth->verificarCredenciales("victima{$i}@ejemplo.com", '123456', self::IP);
        }

        $r = $this->auth->verificarCredenciales('otro@ejemplo.com', '123456', self::IP);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('esta conexión', $r['motivo']);
    }

    #[Test]
    public function elRateLimitNoAfectaAOtraIp(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->auth->verificarCredenciales("victima{$i}@ejemplo.com", '123456', self::IP);
        }

        $r = $this->auth->verificarCredenciales($this->email, self::CLAVE, '181.50.2.2');

        self::assertTrue($r['ok'], 'un atacante no puede dejar fuera al resto del mundo');
    }

    #[Test]
    public function elTokenDeSesionSeGuardaHasheado(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $token = $this->auth->abrirSesion($usuario, self::IP, 'Chrome');

        $guardado = (string) $this->bd->pdo()->query('SELECT token_hash FROM sesiones')->fetchColumn();

        self::assertNotSame($token, $guardado);
        self::assertSame(hash('sha256', $token), $guardado);
    }

    #[Test]
    public function unaSesionRevocadaDejaDeValer(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $token = $this->auth->abrirSesion($usuario, self::IP, null);

        self::assertNotNull($this->auth->usuarioDeSesion($token));

        $this->auth->cerrarSesion($token, $usuario, self::IP);

        self::assertNull($this->auth->usuarioDeSesion($token));
    }

    #[Test]
    public function desactivarLaCuentaCortaLaSesionAlInstante(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $token = $this->auth->abrirSesion($usuario, self::IP, null);

        $this->usuarios->activar($usuario->id, false);

        // Sin esperar a que caduque la sesión.
        self::assertNull($this->auth->usuarioDeSesion($token));
    }

    #[Test]
    public function cambiarLaContrasenaRevocaTodasLasSesiones(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $a = $this->auth->abrirSesion($usuario, self::IP, null);
        $b = $this->auth->abrirSesion($usuario, '181.50.2.2', null);

        $this->auth->cambiarPassword($usuario, 'otra-contrasena-igual-de-larga', self::IP);

        // Si no, quien robó la sesión sigue dentro después de que la víctima
        // «arregle» el problema.
        self::assertNull($this->auth->usuarioDeSesion($a));
        self::assertNull($this->auth->usuarioDeSesion($b));
    }

    #[Test]
    public function elSecretoTotpSeGuardaCifrado(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $datos = $this->auth->prepararTotp($usuario);

        $blob = $this->bd->pdo()->query('SELECT totp_secret_cifrado FROM usuarios')->fetchColumn();
        $binario = is_resource($blob) ? (string) stream_get_contents($blob) : (string) $blob;

        self::assertStringNotContainsString($datos['secreto'], $binario);
        self::assertSame("\x01", $binario[0], 'debe llevar el byte de versión del blob');
        self::assertSame($datos['secreto'], $this->usuarios->secretoTotp($usuario->id));
    }

    #[Test]
    public function elTotpNoSeActivaHastaQueUnCodigoCoincide(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $datos = $this->auth->prepararTotp($usuario);

        // Activarlo sin comprobar dejaría a Pedro fuera del panel con un
        // secreto que su teléfono nunca guardó.
        self::assertFalse($this->usuarios->porEmail($this->email)->totpActivo);
        self::assertFalse($this->auth->confirmarTotp($usuario, '000000', self::IP));
        self::assertFalse($this->usuarios->porEmail($this->email)->totpActivo);

        self::assertTrue($this->auth->confirmarTotp($usuario, Totp::codigo($datos['secreto']), self::IP));
        self::assertTrue($this->usuarios->porEmail($this->email)->totpActivo);
    }

    // ── Antirreplay y tope del segundo factor ────────────────────────────

    /** Deja al usuario con TOTP activo y devuelve el secreto. */
    private function conTotpActivo(): string
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $datos = $this->auth->prepararTotp($usuario);

        self::assertTrue($this->auth->confirmarTotp($usuario, Totp::codigo($datos['secreto']), self::IP));

        return $datos['secreto'];
    }

    #[Test]
    public function elMismoCodigoNoEntraDosVeces(): void
    {
        $secreto = $this->conTotpActivo();
        $usuario = $this->usuarios->porEmail($this->email);

        // El código de activación ya quedó consumido: si no, ese mismo
        // código serviría acto seguido para iniciar sesión.
        $codigo = Totp::codigo($secreto);

        self::assertFalse(
            $this->auth->verificarTotp($usuario, $codigo, self::IP)['ok'],
            'el código usado para activar no puede servir además para entrar',
        );
    }

    #[Test]
    public function unCodigoValidoEntraUnaVezYSoloUna(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $datos = $this->auth->prepararTotp($usuario);
        $this->usuarios->activarTotp($usuario->id, 0);

        $usuario = $this->usuarios->porEmail($this->email);
        $codigo = Totp::codigo($datos['secreto']);

        self::assertTrue($this->auth->verificarTotp($usuario, $codigo, self::IP)['ok']);

        // Segunda vez con el mismo código: rechazado (RFC 6238 §5.2).
        self::assertFalse($this->auth->verificarTotp($usuario, $codigo, self::IP)['ok']);
    }

    #[Test]
    public function elContadorAceptadoQuedaGuardado(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $datos = $this->auth->prepararTotp($usuario);
        $this->auth->confirmarTotp($usuario, Totp::codigo($datos['secreto']), self::IP);

        self::assertSame(
            intdiv(time(), 30),
            $this->usuarios->ultimoContadorTotp($usuario->id),
        );
    }

    #[Test]
    public function elContadorNuncaRetrocede(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);

        $this->usuarios->guardarContadorTotp($usuario->id, 1000);
        $this->usuarios->guardarContadorTotp($usuario->id, 500);

        // Cierra la carrera entre dos peticiones simultáneas con el mismo
        // código: la segunda no puede rebajar el contador.
        self::assertSame(1000, $this->usuarios->ultimoContadorTotp($usuario->id));
    }

    #[Test]
    public function elSegundoFactorTieneTopePropio(): void
    {
        $this->conTotpActivo();
        $usuario = $this->usuarios->porEmail($this->email);

        // Son seis dígitos y quien llega aquí ya pasó la contraseña: sin
        // tope propio queda un millón de combinaciones abierto.
        for ($i = 0; $i < 5; $i++) {
            $this->auth->verificarTotp($usuario, '000000', self::IP);
        }

        $r = $this->auth->verificarTotp($usuario, '000000', self::IP);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Demasiados códigos', $r['motivo']);
    }

    #[Test]
    public function elTopeDelSegundoFactorEsPorCuentaYNoPorIp(): void
    {
        $this->conTotpActivo();
        $usuario = $this->usuarios->porEmail($this->email);

        // Rotar de IP no debe servir para esquivarlo: quien ya tiene la
        // contraseña puede cambiar de salida cuantas veces quiera.
        for ($i = 0; $i < 5; $i++) {
            $this->auth->verificarTotp($usuario, '000000', "190.85.1.{$i}");
        }

        $r = $this->auth->verificarTotp($usuario, '000000', '181.50.9.9');

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Demasiados códigos', $r['motivo']);
    }

    #[Test]
    public function restablecerElSegundoFactorLoDejaSinTotpYSinSesiones(): void
    {
        $this->conTotpActivo();
        $usuario = $this->usuarios->porEmail($this->email);
        $token = $this->auth->abrirSesion($usuario, self::IP, null);

        $this->auth->restablecerTotp($usuario, 'consola:elkin', 'perdió el teléfono');

        $despues = $this->usuarios->porEmail($this->email);

        self::assertFalse($despues->totpActivo);
        self::assertNull($this->usuarios->secretoTotp($usuario->id));
        self::assertNull($this->usuarios->ultimoContadorTotp($usuario->id));

        // Si el teléfono se perdió, una sesión abierta en ese teléfono sigue
        // siendo una sesión abierta.
        self::assertNull($this->auth->usuarioDeSesion($token));

        $fila = $this->bd->pdo()->query(
            "SELECT actor, detalle FROM auditoria WHERE accion='totp_restablecido'"
        )->fetch();

        self::assertIsArray($fila, 'debe quedar en la bitácora');
        self::assertSame('consola:elkin', $fila['actor']);
        self::assertStringContainsString('perdió el teléfono', (string) $fila['detalle']);
    }

    #[Test]
    public function elRolDeAbogadoExigeSegundoPaso(): void
    {
        // super_admin y abogado manejan credenciales y aprueban lo que dice
        // el bot: para ellos el TOTP no es opcional.
        $r = $this->auth->verificarCredenciales($this->email, self::CLAVE, self::IP);

        self::assertTrue($r['requiereTotp']);
    }

    #[Test]
    public function elLoginQuedaEnLaBitacora(): void
    {
        $usuario = $this->usuarios->porEmail($this->email);
        $this->auth->abrirSesion($usuario, self::IP, null);

        $fila = $this->bd->pdo()->query(
            "SELECT actor, detalle FROM auditoria WHERE entidad='sesion' AND accion='login'"
        )->fetch();

        self::assertIsArray($fila);
        self::assertSame($this->email, $fila['actor']);
    }

    #[Test]
    public function laBitacoraNoGuardaLaContrasena(): void
    {
        $this->auth->verificarCredenciales($this->email, 'MiClaveSecreta123', self::IP);

        $todo = (string) $this->bd->pdo()->query('SELECT GROUP_CONCAT(detalle) FROM auditoria')->fetchColumn();

        self::assertStringNotContainsString('MiClaveSecreta123', $todo);
    }
}
