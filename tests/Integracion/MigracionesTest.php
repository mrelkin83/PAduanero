<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Migrador;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Criterio de cierre de la Etapa 0: las migraciones corren limpias sobre
 * MySQL 8. Y, de paso, que sigan ahí las columnas generadas que sostienen
 * las reglas de negocio.
 */
#[Group('critica')]
final class MigracionesTest extends CasoBaseBd
{
    #[Test]
    public function correrDosVecesNoCambiaNada(): void
    {
        $migrador = new Migrador($this->bd, dirname(__DIR__, 2) . '/db/migraciones');

        $mensajes = $migrador->migrar();

        self::assertNotEmpty($mensajes);
        self::assertStringContainsString('Todo al día', implode(' ', $mensajes));
    }

    #[Test]
    public function elHashDetectaUnaMigracionEditada(): void
    {
        $directorio = sys_get_temp_dir() . '/pedro-migraciones-' . bin2hex(random_bytes(4));
        mkdir($directorio, 0o777, true);

        // Las migraciones reales ya están aplicadas en esta base. Si no se
        // copian, el Migrador aborta —con razón— porque vería versiones
        // aplicadas sin archivo, que es el otro control de integridad.
        foreach (glob(dirname(__DIR__, 2) . '/db/migraciones/*.sql') ?: [] as $real) {
            copy($real, $directorio . '/' . basename($real));
        }

        try {
            $archivo = $directorio . '/9001_prueba.sql';
            file_put_contents($archivo, 'CREATE TABLE IF NOT EXISTS zz_prueba (id INT PRIMARY KEY);');

            $migrador = new Migrador($this->bd, $directorio);
            $migrador->migrar();

            // Alguien edita una migración ya aplicada: reaplicarla dejaría la
            // base en un estado que no corresponde a ninguna versión del
            // código, así que el runner debe abortar.
            file_put_contents($archivo, 'CREATE TABLE IF NOT EXISTS zz_prueba (id INT PRIMARY KEY, x INT);');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/contenido cambió/u');
            (new Migrador($this->bd, $directorio))->migrar();
        } finally {
            $this->bd->pdo()->exec('DROP TABLE IF EXISTS zz_prueba');
            $this->bd->pdo()->exec("DELETE FROM migraciones WHERE version = '9001_prueba.sql'");

            foreach (glob($directorio . '/*.sql') ?: [] as $temporal) {
                @unlink($temporal);
            }
            @rmdir($directorio);
        }
    }

    #[Test]
    public function existeLaColumnaGeneradaQueFrenaLaDobleReserva(): void
    {
        // Es la comprobación que también hace bin/salud.sh cada 10 minutos.
        // Si alguien elimina slot_unico "porque MySQL se quejó", esto lo caza.
        self::assertTrue($this->existeColumna('consultas', 'slot_unico'));
        self::assertTrue($this->existeColumna('modelos_ia', 'primario_key'));
        self::assertTrue($this->existeColumna('prompts', 'activo_key'));
    }

    #[Test]
    public function credencialesYaNoTieneColumnasNonceNiTag(): void
    {
        // ADR-011: el nonce y el tag viajan dentro del blob. Si estas columnas
        // reaparecen, hay dos caminos de código para lo mismo.
        self::assertFalse($this->existeColumna('credenciales', 'nonce'));
        self::assertFalse($this->existeColumna('credenciales', 'tag'));
        self::assertTrue($this->existeColumna('credenciales', 'key_version'));
    }

    #[Test]
    public function lasSemillasDelNegocioEstanCargadas(): void
    {
        $pdo = $this->bd->pdo();

        self::assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
        self::assertSame(11, (int) $pdo->query('SELECT COUNT(*) FROM horarios')->fetchColumn());

        // El precio va en PESOS (ADR-010). Un 40000000 aquí le cobraría
        // $40 millones al cliente.
        self::assertSame(
            400000,
            (int) $pdo->query('SELECT precio_cop FROM modalidades_asesoria')->fetchColumn(),
        );
    }

    #[Test]
    public function elModoSombraArrancaEncendido(): void
    {
        // docs/PLAN_BUILD.md: la IA arranca en modo sombra, sin excepción.
        $valor = $this->bd->pdo()
            ->query("SELECT valor FROM configuraciones WHERE clave = 'motor_modo_sombra'")
            ->fetchColumn();

        self::assertSame(true, json_decode((string) $valor, true));
    }

    #[Test]
    public function laMatrizDeRolesRespetaLasDosAsimetrias(): void
    {
        // ADR-007: el super_admin no aprueba prompts, no verifica normas y no
        // publica contenido. El abogado no ve credenciales.
        self::assertFalse($this->tienePermiso('super_admin', 'ia.prompts.aprobar'));
        self::assertFalse($this->tienePermiso('super_admin', 'kb.verificar'));
        self::assertFalse($this->tienePermiso('super_admin', 'contenido.publicar'));

        self::assertTrue($this->tienePermiso('abogado', 'ia.prompts.aprobar'));
        self::assertFalse($this->tienePermiso('abogado', 'pagos.credenciales.ver'));
        self::assertTrue($this->tienePermiso('super_admin', 'pagos.credenciales.escribir'));
    }

    private function tienePermiso(string $rol, string $permiso): bool
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM roles_permisos rp
               JOIN roles r    ON r.id = rp.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE r.clave = ? AND p.clave = ?'
        );
        $stmt->execute([$rol, $permiso]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function existeColumna(string $tabla, string $columna): bool
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$this->bd->nombreBase(), $tabla, $columna]);

        return (int) $stmt->fetchColumn() > 0;
    }

    protected function limpiar(): void
    {
        // Esta clase comprueba las semillas: vaciar las tablas las borraría.
    }
}
