<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Csrf;
use App\Core\Peticion;
use App\Modelos\Usuario;
use App\Panel\ContenidoControlador;
use App\Panel\Contexto;
use App\Repositorios\AuditoriaRepo;
use App\Servicios\ConfigMysql;
use App\Servicios\Landing;
use App\Servicios\Permisos;
use App\Servicios\Seo;
use App\Servicios\SinPermisoException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * El editor de contenido de la página: las reglas que no se ven.
 *
 * Lo que se defiende: que la estructura del JSON no cambie desde el panel
 * (las claves son las que las plantillas esperan), que la marca `pendiente`
 * solo la quite el «0» explícito del formulario, que lo añadido nazca
 * pendiente, y que guardar invalide la caché — sin eso, «el panel no guarda».
 */
#[Group('critica')]
final class PanelContenidoTest extends CasoBaseBd
{
    private Permisos $permisos;
    private string $rutaCache;
    private string $rutaSentinela;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permisos = new Permisos($this->bd);

        $sufijo = bin2hex(random_bytes(4));
        $this->rutaCache = sys_get_temp_dir() . "/pa-landing-{$sufijo}.html";
        $this->rutaSentinela = sys_get_temp_dir() . "/pa-landing-{$sufijo}.sentinel";
    }

    private function ctrl(): ContenidoControlador
    {
        $config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . '/pa-cont-sent',
            sys_get_temp_dir() . '/pa-cont-cfg.json',
        );

        return new ContenidoControlador(
            $this->bd,
            new AuditoriaRepo($this->bd),
            new Landing(
                $this->bd,
                $config,
                new Seo($this->bd, $config, 'http://localhost'),
                'http://localhost',
                $this->rutaCache,
                $this->rutaSentinela,
                dirname(__DIR__, 2) . '/public/css/app.css',
            ),
        );
    }

    /** @param array<string,mixed> $formulario */
    private function ctx(string $rol, array $formulario = [], array $consulta = []): Contexto
    {
        return new Contexto(
            new Peticion(
                metodo: $formulario === [] ? 'GET' : 'POST',
                ruta: '/panel/contenido',
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

    private function json(string $clave): array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT contenido FROM landing_bloques WHERE clave = ?');
        $stmt->execute([$clave]);

        return json_decode((string) $stmt->fetchColumn(), true) ?: [];
    }

    #[Test]
    public function laListaMuestraLosBloquesYCuentaLosPendientes(): void
    {
        $html = $this->ctrl()->listar($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('testimonios', $html);
        // Los testimonios de relleno (0015) tienen que verse como deuda, no
        // desaparecer entre las filas.
        self::assertStringContainsString('por confirmar', $html);
    }

    #[Test]
    public function elContadorNoEditaContenido(): void
    {
        $this->expectException(SinPermisoException::class);
        $this->ctrl()->listar($this->ctx('contador'));
    }

    #[Test]
    public function elEditorSeGeneraDeLaEstructuraDelBloque(): void
    {
        $html = $this->ctrl()->editar($this->ctx('abogado', [], ['clave' => 'testimonios']))->cuerpo;

        // Campos generados del JSON, no escritos a mano.
        self::assertStringContainsString('c[items][0][autor]', $html);
        self::assertStringContainsString('Dato pendiente de confirmar', $html);
    }

    #[Test]
    public function guardarCambiaValoresSinTocarLaEstructura(): void
    {
        $antes = $this->json('testimonios');

        $this->ctrl()->guardar($this->ctx('abogado', [
            'clave' => 'testimonios',
            'titulo' => 'Lo que dicen quienes ya pasaron por esto',
            'visible' => '1',
            'c' => ['items' => [0 => ['autor' => 'María Fernanda López', 'pendiente' => '0']]],
        ]));

        $despues = $this->json('testimonios');

        self::assertSame('María Fernanda López', $despues['items'][0]['autor']);
        // Lo no enviado se conserva; las claves son las mismas.
        self::assertSame($antes['items'][0]['texto'], $despues['items'][0]['texto']);
        self::assertSame(count($antes['items']), count($despues['items']));
    }

    #[Test]
    public function desmarcarPendienteQuitaLaClaveYNoLaPoneEnFalse(): void
    {
        $this->ctrl()->guardar($this->ctx('abogado', [
            'clave' => 'testimonios',
            'visible' => '1',
            'c' => ['items' => [0 => ['pendiente' => '0']]],
        ]));

        $items = $this->json('testimonios')['items'];

        // La plantilla pregunta por la EXISTENCIA de la marca (0015):
        // un `pendiente: false` seguiría existiendo.
        self::assertArrayNotHasKey('pendiente', $items[0]);
        // Y los no tocados la conservan.
        self::assertTrue($items[1]['pendiente']);
    }

    #[Test]
    public function unaPeticionSinLaCasillaConservaLaMarcaPendiente(): void
    {
        $this->ctrl()->guardar($this->ctx('abogado', [
            'clave' => 'testimonios',
            'visible' => '1',
            'c' => ['items' => [0 => ['autor' => 'Otro nombre']]],   // sin 'pendiente'
        ]));

        // Quitar el «no confirmado» por accidente publicaría como real un
        // dato que no lo es: solo el 0 explícito del formulario lo quita.
        self::assertTrue($this->json('testimonios')['items'][0]['pendiente']);
    }

    #[Test]
    public function loAgregadoClonaLaFormaYNacePendiente(): void
    {
        $antes = count($this->json('testimonios')['items']);

        $this->ctrl()->guardar($this->ctx('abogado', [
            'clave' => 'testimonios',
            'visible' => '1',
            'agregar' => 'items',
        ]));

        $items = $this->json('testimonios')['items'];

        self::assertCount($antes + 1, $items);
        $nuevo = end($items);
        self::assertSame('', $nuevo['autor']);
        self::assertSame(array_keys($items[0]), array_keys($nuevo), 'misma forma que los existentes');
        self::assertTrue($nuevo['pendiente']);
    }

    #[Test]
    public function quitarEliminaSoloEseElemento(): void
    {
        $antes = $this->json('testimonios')['items'];

        $this->ctrl()->guardar($this->ctx('abogado', [
            'clave' => 'testimonios',
            'visible' => '1',
            'quitar' => 'items:0',
        ]));

        $despues = $this->json('testimonios')['items'];

        self::assertCount(count($antes) - 1, $despues);
        self::assertSame($antes[1]['texto'], $despues[0]['texto']);
    }

    #[Test]
    public function guardarInvalidaLaCacheDeLaPagina(): void
    {
        file_put_contents($this->rutaCache, '<html>vieja</html>');

        $this->ctrl()->guardar($this->ctx('abogado', [
            'clave' => 'hero',
            'titulo' => 'Título nuevo',
            'visible' => '1',
        ]));

        self::assertFileDoesNotExist($this->rutaCache, 'la caché vieja debió borrarse al guardar');
    }

    #[Test]
    public function elEditorDelTelefonoOfreceUnDesplegableDeIcono(): void
    {
        // No es texto libre (0026): un valor mal escrito ahí no rompe el
        // enlace `tel:`, pero cae en el icono genérico en silencio — mejor
        // que el desplegable no deje escribir eso desde el principio.
        $html = $this->ctrl()->editar($this->ctx('abogado', [], ['clave' => 'pie']))->cuerpo;

        self::assertStringContainsString('<select name="c[telefonos][0][icono]"', $html);
        self::assertStringContainsString('value="whatsapp"', $html);
    }

    #[Test]
    public function guardarElIconoDeUnTelefonoNoTocaLosDemas(): void
    {
        $this->ctrl()->guardar($this->ctx('abogado', [
            'clave' => 'pie',
            'visible' => '1',
            'c' => ['telefonos' => [0 => ['numero' => '+57 300 123 4567', 'icono' => 'whatsapp']]],
        ]));

        $telefonos = $this->json('pie')['telefonos'];

        self::assertSame('whatsapp', $telefonos[0]['icono']);
        self::assertSame('+57 300 123 4567', $telefonos[0]['numero']);
        self::assertSame('telefono', $telefonos[1]['icono'], 'el segundo teléfono no se tocó');
    }

    #[Test]
    public function ocultarUnBloqueQuedaAuditado(): void
    {
        $this->ctrl()->guardar($this->ctx('abogado', [
            'clave' => 'testimonios',
            'visible' => '0',
        ]));

        self::assertSame(0, (int) $this->bd->pdo()
            ->query("SELECT visible FROM landing_bloques WHERE clave = 'testimonios'")->fetchColumn());
        self::assertSame(1, (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM auditoria WHERE entidad = 'contenido' AND entidad_id = 'testimonios'"
        )->fetchColumn());
    }
}
