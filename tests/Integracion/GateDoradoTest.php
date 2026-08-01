<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\GateDorado;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Promover un modelo sin corrida dorada verde debe fallar (`PRUEBAS.md` §1,
 * nivel 1).
 *
 * La firma del abogado sobre un modelo solo significa algo si lo que firma ya
 * demostró no violar las reglas inviolables. Sin este gate, aprobar un modelo
 * es aprobar una cadena de texto.
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

    /** Deja el modelo con costo verificado y activo, listo salvo por el dorado. */
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
            : ['id' => (string) $fila['id'], 'version' => (int) $fila['version'], 'contenido' => (string) $fila['contenido']];
    }

    // ── El nivel 1 ───────────────────────────────────────────────────────

    #[Test]
    public function sinCorridaDoradaNoSePuedePromover(): void
    {
        $modelo = $this->prepararModelo('claude-opus-5');

        $veredicto = $this->gate->puedePromover($modelo);

        self::assertFalse($veredicto['ok']);
        self::assertStringContainsString('no se ha corrido', $veredicto['motivo']);
    }

    #[Test]
    public function laBaseTambienLoImpide(): void
    {
        // No solo la pantalla: un script de mantenimiento tampoco puede.
        $modelo = $this->prepararModelo('claude-opus-5');

        $this->expectException(\PDOException::class);

        $this->bd->pdo()
            ->prepare('UPDATE modelos_ia SET es_primario = 1 WHERE id = ?')
            ->execute([$modelo['id']]);
    }

    #[Test]
    public function conCorridaEnRojoTampoco(): void
    {
        $this->activarPrompt();
        $modelo = $this->prepararModelo('claude-opus-5');

        $this->gate->registrarCorrida($modelo["id"], $this->promptRow(), verde: false, casos: 40, fallos: 3);

        $veredicto = $this->gate->puedePromover($this->modelo('claude-opus-5'));

        self::assertFalse($veredicto['ok']);
        self::assertStringContainsString('3 caso(s) en rojo', $veredicto['motivo']);
    }

    #[Test]
    public function conCorridaEnVerdeYPromptVigenteSiSePuede(): void
    {
        $this->activarPrompt();
        $modelo = $this->prepararModelo('claude-opus-5');

        $this->gate->registrarCorrida($modelo["id"], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        self::assertTrue($this->gate->puedePromover($this->modelo('claude-opus-5'))['ok']);
    }

    // ── La parte que un CHECK no puede ver ───────────────────────────────

    #[Test]
    public function siElPromptCambioDespuesElVerdeCaduca(): void
    {
        // Este es el fraude honesto que el gate existe para impedir: correr
        // el dorado en verde, cambiar el prompt al día siguiente, y promover
        // con un verde que ya no dice nada sobre lo que el bot diría.
        $this->activarPrompt('Prompt de ayer.');
        $modelo = $this->prepararModelo('claude-opus-5');
        $this->gate->registrarCorrida($modelo["id"], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        self::assertTrue($this->gate->puedePromover($this->modelo('claude-opus-5'))['ok']);

        // Se activa una versión nueva del prompt.
        $this->bd->pdo()->exec('UPDATE prompts SET activo = 0');
        $this->activarPrompt('Prompt de hoy, con instrucciones distintas.');

        $veredicto = $this->gate->puedePromover($this->modelo('claude-opus-5'));

        self::assertFalse($veredicto['ok']);
        self::assertStringContainsString('prompt activo cambió', $veredicto['motivo']);
    }

    #[Test]
    public function cambiarElPromptNoDegradaAlPrimarioQueYaEstaba(): void
    {
        // Degradarlo en caliente dejaría al motor sin modelo en mitad de una
        // conversación. Lo que caduca es el permiso para promover a OTRO.
        $this->activarPrompt('Prompt de ayer.');
        $modelo = $this->prepararModelo('claude-opus-5');
        $this->gate->registrarCorrida($modelo["id"], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        $this->bd->pdo()
            ->prepare('UPDATE modelos_ia SET es_primario = 1 WHERE id = ?')
            ->execute([$modelo['id']]);

        $this->bd->pdo()->exec('UPDATE prompts SET activo = 0');
        $this->activarPrompt('Prompt de hoy.');

        self::assertSame(1, (int) $this->modelo('claude-opus-5')['es_primario']);
    }

    #[Test]
    public function sinPromptActivoNoHayNadaAQueAtarLaCorrida(): void
    {
        $modelo = $this->prepararModelo('claude-opus-5');
        $this->gate->registrarCorrida($modelo["id"], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        $veredicto = $this->gate->puedePromover($this->modelo('claude-opus-5'));

        self::assertFalse($veredicto['ok']);
        self::assertStringContainsString('prompt de conversación activo', $veredicto['motivo']);
    }

    #[Test]
    public function laCorridaSeAtaAlPromptConElQueSeCorrio(): void
    {
        // `registrarCorrida()` recibe la FILA del prompt, no su id suelto:
        // quien llama solo puede registrar lo que leyó, así que no puede
        // correr con un texto y atribuir el verde a otra versión.
        $activo = $this->activarPrompt('El bueno.');
        $modelo = $this->prepararModelo('claude-opus-5');

        $this->gate->registrarCorrida($modelo["id"], $this->promptRow(), verde: true, casos: 40, fallos: 0);

        self::assertSame($activo, $this->modelo('claude-opus-5')['dorado_prompt_id']);
    }

    // ── Alcance del gate ─────────────────────────────────────────────────

    #[Test]
    public function unModeloDeEmbeddingsNoNecesitaConjuntoDorado(): void
    {
        // No le dice nada a nadie. Exigírselo sería teatro.
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

        self::assertTrue($this->gate->puedePromover($stmt->fetch())['ok']);

        // Y la base tampoco lo bloquea.
        $pdo->prepare('UPDATE modelos_ia SET es_primario = 1 WHERE id = ?')->execute([$id]);
        self::assertTrue(true);
    }

    // ── La matriz de permisos ────────────────────────────────────────────

    #[Test]
    public function promoverEsDelAbogadoYNoDelSuperAdmin(): void
    {
        // Tercera asimetría del ADR-007. El super_admin tiene las llaves
        // técnicas; la responsabilidad profesional es del abogado.
        $stmt = $this->bd->pdo()->prepare(
            'SELECT r.clave FROM roles_permisos rp
               JOIN roles r    ON r.id = rp.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE p.clave = ?'
        );
        $stmt->execute(['ia.modelos.promover']);
        $roles = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['abogado'], $roles);
    }

    #[Test]
    public function elSuperAdminConservaTodoLoTecnico(): void
    {
        // Lo que la decisión NO hace: quitarle el trabajo. Descubre,
        // configura, verifica costos y prueba conexión.
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
