<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\GateDorado;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * El conjunto dorado después de que se retirara el gate.
 *
 * Hasta el 2026-08-01 este archivo probaba que promover un modelo sin corrida
 * verde fallaba. El Product Owner retiró esa puerta —«quita el gate, elegir
 * el modelo debe ser suficiente»— y lo que queda por probar cambia de signo:
 *
 *  · Que el bloqueo YA NO está, ni en el código ni en la base. Un CHECK
 *    olvidado haría fallar el ascenso con un error de SQL en vez de con un
 *    mensaje, y sería peor que el gate que se quiso quitar.
 *  · Que la EVIDENCIA sigue intacta. `registrarCorrida()` guarda igual,
 *    `estado()` describe igual, y la corrida sigue atándose al prompt con el
 *    que se corrió. Se retiró el bloqueo, no el registro.
 */
#[Group('critica')]
final class GateDoradoTest extends CasoBaseBd
{
    private GateDorado $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new GateDorado($this->bd);
    }

    private function modelo(string $identificador): array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM modelos_ia WHERE identificador = ?');
        $stmt->execute([$identificador]);

        return $stmt->fetch();
    }

    /** Deja el modelo con costo verificado y activo. */
    private function prepararModelo(string $identificador): array
    {
        $this->bd->pdo()->prepare(
            'UPDATE modelos_ia
                SET costo_entrada_usd_1m = 5, costo_salida_usd_1m = 25,
                    costos_verificados = 1, activo = 1
              WHERE identificador = ?'
        )->execute([$identificador]);

        return $this->modelo($identificador);
    }

    private function activarPrompt(string $contenido = 'Eres el asistente del despacho.'): string
    {
        $pdo = $this->bd->pdo();
        $id = $pdo->query('SELECT UUID()')->fetchColumn();

        $pdo->prepare(
            'INSERT INTO prompts (id, clave, version, contenido, activo) VALUES (?, ?, ?, ?, 1)'
        )->execute([$id, GateDorado::CLAVE_PROMPT, random_int(1, 1_000_000), $contenido]);

        return (string) $id;
    }

    /**
     * La fila del prompt activo, tal y como la lee el corredor del dorado.
     *
     * `registrarCorrida()` recibe la fila entera y no un id suelto: quien
     * llama solo puede registrar lo que leyó, así que no puede correr con un
     * texto y atribuir el resultado a otra versión.
     *
     * @return array{id:string,version:int,contenido:string}
     */
    private function promptRow(): array
    {
        $fila = $this->bd->pdo()->query(
            "SELECT id, version, contenido FROM prompts
              WHERE clave = 'conversacion' AND activo = 1 LIMIT 1"
        )->fetch();

        return $fila === false
            ? ['id' => '00000000-0000-0000-0000-000000000000', 'version' => 0, 'contenido' => '']
            : [
                'id' => (string) $fila['id'],
                'version' => (int) $fila['version'],
                'contenido' => (string) $fila['contenido'],
            ];
    }

    // ── Que el bloqueo ya no está ────────────────────────────────────────

    #[Test]
    public function laBaseYaNoImpidePromoverSinCorridaDorada(): void
    {
        // La prueba que más importa de este archivo. El CHECK
        // `ck_modelo_primario_dorado` se retiró en la migración 0010; si
        // sobreviviera en algún entorno, el ascenso fallaría con un error de
        // SQL en mitad de la pantalla en vez de con un mensaje — peor que el
        // gate que se quiso quitar.
        $modelo = $this->prepararModelo('claude-opus-5');

        $this->bd->pdo()
            ->prepare('UPDATE modelos_ia SET es_primario = 1 WHERE id = ?')
            ->execute([$modelo['id']]);

        self::assertSame(1, (int) $this->modelo('claude-opus-5')['es_primario']);
    }

    #[Test]
    public function elCheckDelDoradoNoExisteEnElEsquema(): void
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ?'
        );
        $stmt->execute(['modelos_ia', 'ck_modelo_primario_dorado']);

        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function sigueProhibidoUnPrimarioSinCostoVerificado(): void
    {
        // Lo que la decisión NO tocó. Sin costo, el corte por
        // `presupuesto_ia_mensual_usd` no corta nunca: un modelo a costo cero
        // jamás agota un presupuesto, y un guardia que deja de guardar en
        // silencio es peor que no tenerlo. Lo impone `ck_modelo_primario_apto`.
        $this->bd->pdo()->exec(
            "UPDATE modelos_ia SET costos_verificados = 0, activo = 1
              WHERE identificador = 'claude-opus-5'"
        );

        $this->expectException(\PDOException::class);

        $this->bd->pdo()->exec(
            "UPDATE modelos_ia SET es_primario = 1 WHERE identificador = 'claude-opus-5'"
        );
    }

    // ── Que la evidencia sigue ───────────────────────────────────────────

    #[Test]
    public function sinCorridaElEstadoLoDiceAunqueNoImpida(): void
    {
        $veredicto = $this->gate->estado($this->prepararModelo('claude-opus-5'));

        self::assertFalse($veredicto['ok'], 'no hay evidencia');
        self::assertStringContainsString('no se ha corrido', $veredicto['motivo']);
    }

    #[Test]
    public function conCorridaEnRojoSeDiceCuantosFallaron(): void
    {
        $this->activarPrompt();
        $modelo = $this->prepararModelo('claude-opus-5');

        $this->gate->registrarCorrida($modelo['id'], $this->promptRow(), verde: false, casos: 40, fallos: 3);

        $veredicto = $this->gate->estado($this->modelo('claude-opus-5'));

        self::assertFalse($veredicto['ok']);
        self::assertStringContainsString('3 caso(s) en rojo', $veredicto['motivo']);
    }

    #[Test]
    public function conCorridaEnVerdeYPromptVigenteElEstadoEsOk(): void
    {
        $this->activarPrompt();
        $modelo = $this->prepararModelo('claude-opus-5');

        $this->gate->registrarCorrida($modelo['id'], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        self::assertTrue($this->gate->estado($this->modelo('claude-opus-5'))['ok']);
    }

    #[Test]
    public function siElPromptCambioDespuesElVerdeCaduca(): void
    {
        // Sigue siendo cierto y sigue avisándose: un verde de ayer no dice
        // nada sobre lo que el bot diría con el prompt de hoy. Lo que cambió
        // es que ahora es un aviso y no un impedimento.
        $this->activarPrompt('Prompt de ayer.');
        $modelo = $this->prepararModelo('claude-opus-5');
        $this->gate->registrarCorrida($modelo['id'], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        self::assertTrue($this->gate->estado($this->modelo('claude-opus-5'))['ok']);

        $this->bd->pdo()->exec('UPDATE prompts SET activo = 0');
        $this->activarPrompt('Prompt de hoy, con instrucciones distintas.');

        $veredicto = $this->gate->estado($this->modelo('claude-opus-5'));

        self::assertFalse($veredicto['ok']);
        self::assertStringContainsString('prompt activo cambió', $veredicto['motivo']);
    }

    #[Test]
    public function cambiarElPromptNoDegradaAlPrimarioQueYaEstaba(): void
    {
        // Degradarlo en caliente dejaría al motor sin modelo en mitad de una
        // conversación.
        $this->activarPrompt('Prompt de ayer.');
        $modelo = $this->prepararModelo('claude-opus-5');
        $this->gate->registrarCorrida($modelo['id'], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        $this->bd->pdo()
            ->prepare('UPDATE modelos_ia SET es_primario = 1 WHERE id = ?')
            ->execute([$modelo['id']]);

        $this->bd->pdo()->exec('UPDATE prompts SET activo = 0');
        $this->activarPrompt('Prompt de hoy.');

        self::assertSame(1, (int) $this->modelo('claude-opus-5')['es_primario']);
    }

    #[Test]
    public function laCorridaSeAtaAlPromptConElQueSeCorrio(): void
    {
        // `registrarCorrida()` recibe la FILA del prompt, no su id suelto:
        // quien llama solo puede registrar lo que leyó, así que no puede
        // correr con un texto y atribuir el verde a otra versión. Esta parte
        // no dependía del gate y sigue en pie.
        $activo = $this->activarPrompt('El bueno.');
        $modelo = $this->prepararModelo('claude-opus-5');

        $this->gate->registrarCorrida($modelo['id'], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        self::assertSame($activo, $this->modelo('claude-opus-5')['dorado_prompt_id']);
    }

    #[Test]
    public function unModeloDeEmbeddingsNoTieneNadaQueDecirDelDorado(): void
    {
        // No le dice nada a nadie.
        $pdo = $this->bd->pdo();
        $id = $pdo->query('SELECT UUID()')->fetchColumn();
        $proveedorId = $pdo->query("SELECT id FROM proveedores_ia WHERE clave='anthropic'")
            ->fetchColumn();

        $pdo->prepare(
            'INSERT INTO modelos_ia
                (id, proveedor_id, identificador, nombre_visible, proposito,
                 costo_entrada_usd_1m, costo_salida_usd_1m, costos_verificados, activo)
             VALUES (?, ?, ?, ?, \'embeddings\', 0.1, 0.1, 1, 1)'
        )->execute([$id, $proveedorId, 'text-embedding-3-large', 'Embeddings']);

        $stmt = $pdo->prepare('SELECT * FROM modelos_ia WHERE id = ?');
        $stmt->execute([$id]);

        self::assertTrue($this->gate->estado($stmt->fetch())['ok']);
    }

    // ── La matriz de permisos ────────────────────────────────────────────

    #[Test]
    public function promoverYaNoEsExclusivoDelAbogado(): void
    {
        // Quien configura el proveedor es el perfil técnico; exigirle cambiar
        // de sesión para poner en uso lo que acaba de configurar convertía
        // «elegir el modelo» en dos pasos con dos cuentas.
        $stmt = $this->bd->pdo()->prepare(
            'SELECT r.clave FROM roles_permisos rp
               JOIN roles r    ON r.id = rp.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE p.clave = ? ORDER BY r.clave'
        );
        $stmt->execute(['ia.modelos.promover']);
        $roles = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('super_admin', $roles);
        self::assertContains('abogado', $roles, 'al abogado no se le quita nada');
    }

    #[Test]
    public function lasOtrasAsimetriasDelAdr007SiguenIntactas(): void
    {
        // La decisión fue sobre el modelo, no sobre el reparto de firmas.
        // Aprobar prompts, verificar normas y publicar contenido siguen
        // siendo del abogado.
        $stmt = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM roles_permisos rp
               JOIN roles r    ON r.id = rp.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE r.clave = \'super_admin\' AND p.clave = ?'
        );

        foreach (['ia.prompts.aprobar', 'kb.verificar', 'contenido.publicar'] as $permiso) {
            $stmt->execute([$permiso]);

            self::assertSame(0, (int) $stmt->fetchColumn(), "«{$permiso}» no es del super_admin");
        }
    }

    #[Test]
    public function elSuperAdminConservaTodoLoTecnico(): void
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT COUNT(*) FROM roles_permisos rp
               JOIN roles r    ON r.id = rp.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE r.clave = \'super_admin\' AND p.clave = ?'
        );
        $stmt->execute(['ia.proveedores.escribir']);

        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}
