<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Csrf;
use App\Core\Peticion;
use App\Modelos\Usuario;
use App\Panel\Contexto;
use App\Panel\PanelCursosControlador;
use App\Repositorios\AuditoriaRepo;
use App\Servicios\Permisos;
use App\Servicios\SinPermisoException;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class PanelCursosTest extends CasoBaseBd
{
    private Permisos $permisos;
    private AuditoriaRepo $auditoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permisos = new Permisos($this->bd);
        $this->auditoria = new AuditoriaRepo($this->bd);
    }

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
                ruta: '/panel/cursos',
                consulta: $consulta,
                formulario: $formulario,
                ip: '190.85.1.1',
            ),
            $this->usuario($rol),
            $this->permisos,
            new Csrf(false),
        );
    }

    private function controlador(): PanelCursosControlador
    {
        return new PanelCursosControlador($this->bd, $this->auditoria);
    }

    private function categoriaId(string $nombre = 'Aduanero'): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)'
        )->execute([$id, $nombre, strtolower($nombre)]);

        return $id;
    }

    #[Test]
    public function elAsistenteNoVeCursosEnAbsoluto(): void
    {
        $this->expectException(SinPermisoException::class);
        $this->controlador()->listar($this->ctx('asistente'));
    }

    #[Test]
    public function crearUnCursoLoDejaEnBorrador(): void
    {
        $cat = $this->categoriaId();

        $r = $this->controlador()->guardar($this->ctx('abogado', [
            'id' => '',
            'categoria_id' => $cat,
            'titulo' => 'Clasificación arancelaria desde cero',
            'resumen' => 'Aprenda a clasificar mercancías sin errores.',
            'descripcion' => 'Curso completo sobre el sistema armonizado.',
            'lo_que_aprendera' => "Leer el arancel\nEvitar errores comunes",
            'nivel' => 'basico',
            'precio_cop' => '250000',
            'orden' => '1',
        ]));

        self::assertSame(302, $r->estado);

        $fila = $this->bd->pdo()->query(
            "SELECT * FROM cursos WHERE titulo = 'Clasificación arancelaria desde cero'"
        )->fetch();

        self::assertIsArray($fila);
        self::assertSame('borrador', $fila['estado']);
        self::assertSame('clasificacion-arancelaria-desde-cero', $fila['slug']);
        self::assertSame(
            ['Leer el arancel', 'Evitar errores comunes'],
            json_decode((string) $fila['lo_que_aprendera'], true),
        );
    }

    #[Test]
    public function dosCursosConElMismoTituloRecibenSlugsDistintos(): void
    {
        $cat = $this->categoriaId();

        $datos = [
            'id' => '', 'categoria_id' => $cat, 'titulo' => 'Introducción aduanera',
            'resumen' => 'r', 'descripcion' => 'd', 'lo_que_aprendera' => 'x',
            'nivel' => 'basico', 'precio_cop' => '100000', 'orden' => '0',
        ];

        $this->controlador()->guardar($this->ctx('abogado', $datos));
        $this->controlador()->guardar($this->ctx('abogado', $datos));

        $slugs = $this->bd->pdo()->query(
            "SELECT slug FROM cursos WHERE titulo = 'Introducción aduanera' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(2, array_unique($slugs));
    }

    #[Test]
    public function rechazaCentavosDondeVanPesos(): void
    {
        $cat = $this->categoriaId();

        $r = $this->controlador()->guardar($this->ctx('abogado', [
            'id' => '', 'categoria_id' => $cat, 'titulo' => 'Curso caro por error',
            'resumen' => 'r', 'descripcion' => 'd', 'lo_que_aprendera' => 'x',
            'nivel' => 'basico', 'precio_cop' => '40000000', 'orden' => '0',
        ]));

        self::assertStringContainsString('PESOS', urldecode($r->cabeceras['Location']));
        self::assertSame(
            0,
            (int) $this->bd->pdo()->query("SELECT COUNT(*) FROM cursos WHERE titulo = 'Curso caro por error'")->fetchColumn(),
        );
    }

    #[Test]
    public function laListaSoloLaVeQuienTieneCursosVer(): void
    {
        $html = $this->controlador()->listar($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('Cursos', $html);
    }
}
