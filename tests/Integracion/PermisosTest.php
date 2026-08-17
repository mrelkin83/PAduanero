<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Modelos\Usuario;
use App\Servicios\Permisos;
use App\Servicios\SinPermisoException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * La matriz de `docs/PANEL_ADMIN.md` §3, comprobada contra la base.
 *
 * Las dos asimetrías del ADR-007 parecen erratas y no lo son, así que van
 * probadas explícitamente: si alguien «arregla» la matriz dándole al
 * super_admin el permiso de aprobar prompts, esto lo caza.
 */
#[Group('critica')]
final class PermisosTest extends CasoBaseBd
{
    private Permisos $permisos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permisos = new Permisos($this->bd);
    }

    private function usuario(string $rol): Usuario
    {
        return new Usuario(
            id: '00000000-0000-0000-0000-000000000001',
            email: "{$rol}@ejemplo.com",
            nombre: ucfirst($rol),
            rol: $rol,
            rolId: 1,
            totpActivo: true,
            activo: true,
            intentosFallidos: 0,
            bloqueadoHasta: null,
        );
    }

    #[Test]
    public function elSuperAdminNoAsumeLaResponsabilidadProfesional(): void
    {
        $tecnico = $this->usuario('super_admin');

        // Tiene las llaves técnicas, no la firma del abogado. Si el bot dice
        // una barbaridad jurídica, la firma que la autorizó debe ser la de
        // Pedro (ADR-007).
        self::assertFalse($this->permisos->puede($tecnico, 'ia.prompts.aprobar'));
        self::assertFalse($this->permisos->puede($tecnico, 'kb.verificar'));
        self::assertFalse($this->permisos->puede($tecnico, 'contenido.publicar'));

        // Pero sí las llaves.
        self::assertTrue($this->permisos->puede($tecnico, 'pagos.credenciales.escribir'));
        self::assertTrue($this->permisos->puede($tecnico, 'ia.proveedores.escribir'));
    }

    #[Test]
    public function elAbogadoNoVeCredenciales(): void
    {
        $abogado = $this->usuario('abogado');

        // No las necesita y no debería poder filtrarlas.
        self::assertFalse($this->permisos->puede($abogado, 'pagos.credenciales.ver'));
        self::assertFalse($this->permisos->puede($abogado, 'pagos.credenciales.escribir'));

        // Pero sí aprueba y publica.
        self::assertTrue($this->permisos->puede($abogado, 'ia.prompts.aprobar'));
        self::assertTrue($this->permisos->puede($abogado, 'kb.verificar'));
        self::assertTrue($this->permisos->puede($abogado, 'contenido.publicar'));
    }

    /** @return list<array{string,string,bool}> */
    public static function matriz(): array
    {
        return [
            ['asistente', 'casos.editar', true],
            ['asistente', 'agenda.editar', false],
            ['asistente', 'config.ver', false],
            ['asistente', 'pagos.transacciones.ver', false],
            ['asistente', 'motor.killswitch', false],

            ['contador', 'pagos.transacciones.ver', true],
            ['contador', 'casos.ver', false],
            ['contador', 'config.ver', false],

            ['abogado', 'motor.killswitch', true],
            ['abogado', 'usuarios.editar', false],
            ['super_admin', 'usuarios.editar', true],
        ];
    }

    #[Test]
    #[DataProvider('matriz')]
    public function laMatrizSeRespeta(string $rol, string $permiso, bool $esperado): void
    {
        self::assertSame($esperado, $this->permisos->puede($this->usuario($rol), $permiso));
    }

    #[Test]
    public function sinUsuarioNoHayPermiso(): void
    {
        self::assertFalse($this->permisos->puede(null, 'tablero.ver'));
    }

    #[Test]
    public function unaCuentaDesactivadaPierdeTodo(): void
    {
        $inactivo = new Usuario(
            '1', 'x@y.co', 'X', 'super_admin', 1, true, false, 0, null,
        );

        self::assertFalse($this->permisos->puede($inactivo, 'tablero.ver'));
    }

    #[Test]
    public function exigirLanzaExcepcionSinDecirQuePermisoFalta(): void
    {
        $this->expectException(SinPermisoException::class);

        $this->permisos->exigir($this->usuario('contador'), 'config.editar');
    }

    #[Test]
    public function elAbogadoNoEditaConfiguracionesDeSuperAdmin(): void
    {
        // Es el «✔ (parcial)» de la matriz: entra al módulo, pero las filas
        // que apuntan a credenciales y proveedores no las toca.
        $abogado = $this->usuario('abogado');

        self::assertTrue($this->permisos->puedeEditarConfiguracion($abogado, 'abogado'));
        self::assertFalse($this->permisos->puedeEditarConfiguracion($abogado, 'super_admin'));

        self::assertTrue($this->permisos->puedeEditarConfiguracion($this->usuario('super_admin'), 'super_admin'));
    }
}
