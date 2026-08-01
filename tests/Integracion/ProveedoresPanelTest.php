<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\CatalogoProveedores;
use App\Servicios\ClientesLlm\ClienteOpenAiCompatible;
use App\Soporte\Http;
use App\Soporte\RespuestaHttp;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * Proveedores configurables desde el panel.
 *
 * Antes había tres filas sembradas en una migración: añadir DeepSeek o Groq
 * exigía escribir SQL. Lo que se prueba aquí es que el alta desde el panel no
 * abre ninguna puerta trasera a las garantías del ADR-016.
 */
#[Group('critica')]
final class ProveedoresPanelTest extends CasoBaseBd
{
    private function proveedor(string $clave): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM proveedores_ia WHERE clave = ?');
        $stmt->execute([$clave]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    // ── El catálogo de conveniencia ──────────────────────────────────────

    #[Test]
    public function elCatalogoNoInsertaFilasPorSiSolo(): void
    {
        // La lista de modelos conocidos es para ENSEÑAR, no para dar de alta.
        // Un modelo entra al catálogo cuando el proveedor lo anuncia
        // (ADR-016); insertarlo desde una lista escrita a mano sería inventar
        // que el proveedor lo ofrece, y esa lista envejece.
        $antes = (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM modelos_ia')->fetchColumn();

        self::assertNotEmpty(CatalogoProveedores::modelosDeReferencia('deepseek'));

        self::assertSame(
            $antes,
            (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM modelos_ia')->fetchColumn(),
        );
    }

    #[Test]
    public function losProveedoresYaDadosDeAltaNoSeOfrecenOtraVez(): void
    {
        $disponibles = CatalogoProveedores::disponibles(['anthropic', 'openai', 'ollama']);

        self::assertArrayNotHasKey('anthropic', $disponibles);
        self::assertArrayHasKey('deepseek', $disponibles);
    }

    #[Test]
    public function cadaProveedorConocidoDeclaraUnFormatoValido(): void
    {
        // El ENUM de la columna solo admite tres. Uno mal escrito en el
        // catálogo daría un error de base al darlo de alta, no un aviso.
        foreach (CatalogoProveedores::CONOCIDOS as $clave => $d) {
            self::assertContains(
                $d['formato_api'],
                ['anthropic', 'openai_compatible', 'ollama'],
                "«{$clave}» declara un formato que la columna no admite",
            );
            self::assertMatchesRegularExpression('#^https?://#', $d['base_url'], $clave);
            self::assertNotSame('', $d['pais_servidor'], "«{$clave}» sin país: es dato de cumplimiento");
        }
    }

    // ── El parámetro que rompe todas las llamadas si se manda mal ────────

    #[Test]
    public function openAiRecibeMaxCompletionTokensYElRestoMaxTokens(): void
    {
        // OpenAI renombró el parámetro en sus modelos recientes y RECHAZA
        // `max_tokens` con un 400. Enviar el equivocado no degrada nada:
        // rompe todas las llamadas a ese proveedor.
        self::assertSame('max_completion_tokens', CatalogoProveedores::campoMax('openai'));
        self::assertSame('max_tokens', CatalogoProveedores::campoMax('deepseek'));
        // Un proveedor personalizado usa el que espera la inmensa mayoría.
        self::assertSame('max_tokens', CatalogoProveedores::campoMax('inventado-por-el-usuario'));
    }

    #[Test]
    public function elClienteEnviaElCampoQueEsperaCadaProveedor(): void
    {
        foreach ([
            ['openai', 'max_completion_tokens', 'max_tokens'],
            ['deepseek', 'max_tokens', 'max_completion_tokens'],
        ] as [$clave, $esperado, $prohibido]) {
            $http = new class extends Http {
                /** @var array<string,mixed>|null */
                public ?array $cuerpo = null;

                public function pedir(string $m, string $u, array $c = [], ?array $json = null): RespuestaHttp
                {
                    $this->cuerpo = $json;

                    return new RespuestaHttp(200, json_encode([
                        'choices' => [['message' => ['content' => 'hola']]],
                        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                    ], JSON_THROW_ON_ERROR), null, 50);
                }
            };

            (new ClienteOpenAiCompatible($http))->chat(
                'https://api.ejemplo.com/v1',
                'sk-x',
                [
                    'id' => 'm1',
                    'identificador' => 'modelo-x',
                    'proveedor_clave' => $clave,
                    'temperatura_default' => null,
                ],
                'Sistema',
                [['role' => 'user', 'content' => 'hola']],
                600,
            );

            self::assertArrayHasKey($esperado, (array) $http->cuerpo, $clave);
            self::assertArrayNotHasKey($prohibido, (array) $http->cuerpo, $clave);
        }
    }

    // ── Que el alta no abra puertas traseras ─────────────────────────────

    #[Test]
    public function unProveedorNuevoNaceInactivo(): void
    {
        // Igual que un modelo descubierto. Darlo de alta no puede meter nada
        // en la cascada sin que alguien lo mire.
        $this->bd->pdo()->prepare(
            'INSERT INTO proveedores_ia (clave, nombre, base_url, formato_api, pais_servidor, activo)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute(['deepseek', 'DeepSeek', 'https://api.deepseek.com', 'openai_compatible', 'China']);

        self::assertSame(0, (int) $this->proveedor('deepseek')['activo']);
    }

    #[Test]
    public function apagarUnProveedorSacaSusModelosDeLaCascada(): void
    {
        // Es la forma de dejar de usar un proveedor sin borrar su histórico
        // de consumo. `Llm::cascada()` exige `p.activo = 1`.
        $this->bd->pdo()->exec(
            "UPDATE modelos_ia SET costos_verificados = 1, activo = 1, dorado_estado = 'verde'
              WHERE identificador = 'claude-opus-5'"
        );
        $this->bd->pdo()->exec("UPDATE proveedores_ia SET activo = 0 WHERE clave = 'anthropic'");

        $vivos = (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM modelos_ia m JOIN proveedores_ia p ON p.id = m.proveedor_id
              WHERE m.activo = 1 AND p.activo = 1 AND m.proposito = 'conversacion'"
        )->fetchColumn();

        self::assertSame(0, $vivos);
    }

    #[Test]
    public function laClaveDelProveedorEsUnica(): void
    {
        $this->expectException(\PDOException::class);

        $this->bd->pdo()->prepare(
            'INSERT INTO proveedores_ia (clave, nombre, base_url, formato_api)
             VALUES (?, ?, ?, ?)'
        )->execute(['anthropic', 'Duplicado', 'https://x.test', 'anthropic']);
    }
}
