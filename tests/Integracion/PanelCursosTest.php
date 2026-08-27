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
        return new PanelCursosControlador(
            $this->bd,
            $this->auditoria,
            new \App\Repositorios\CompraCursoRepo($this->bd),
            new \App\Cuenta\ConfirmadorCompra(
                new \App\Repositorios\CompraCursoRepo($this->bd),
                new \App\Repositorios\CompradorEnlaceRepo($this->bd),
                new \App\Wa\ConexionCompartida(
                    $this->bd,
                    \App\Soporte\Cifrado::desdeEntorno(),
                    new \App\Soporte\Logger(sys_get_temp_dir() . '/pa-panel-cursos.log', 'error'),
                    dirname(__DIR__, 2),
                ),
                $this->bd,
                null,
                'https://pedroabogadoaduanero.com',
            ),
        );
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

    private function crearCursoCompleto(string $categoriaId, int $precio = 250000): string
    {
        $this->controlador()->guardar($this->ctx('abogado', [
            'id' => '', 'categoria_id' => $categoriaId, 'titulo' => 'Curso completo ' . bin2hex(random_bytes(3)),
            'resumen' => 'r', 'descripcion' => 'd', 'lo_que_aprendera' => 'x',
            'nivel' => 'basico', 'precio_cop' => (string) $precio, 'orden' => '0',
        ]));

        return (string) $this->bd->pdo()->query('SELECT id FROM cursos ORDER BY creado_en DESC LIMIT 1')->fetchColumn();
    }

    #[Test]
    public function publicarSinModulosFalla(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());

        $r = $this->controlador()->publicar($this->ctx('abogado', ['id' => $cursoId]));

        self::assertStringContainsString('modulo', strtolower(urldecode($r->cabeceras['Location'])));
        self::assertSame(
            'borrador',
            $this->bd->pdo()->query("SELECT estado FROM cursos WHERE id = '{$cursoId}'")->fetchColumn(),
        );
    }

    #[Test]
    public function publicarConTemarioFunciona(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());

        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$moduloId, $cursoId, 'Módulo único', 1]);

        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$leccionId, $moduloId, 'Lección única', 1]);

        $r = $this->controlador()->publicar($this->ctx('abogado', ['id' => $cursoId]));

        self::assertSame(302, $r->estado);
        self::assertSame(
            'publicado',
            $this->bd->pdo()->query("SELECT estado FROM cursos WHERE id = '{$cursoId}'")->fetchColumn(),
        );
    }

    #[Test]
    public function despublicarVuelveABorrador(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());
        $this->bd->pdo()->exec("UPDATE cursos SET estado = 'publicado' WHERE id = '{$cursoId}'");

        $this->controlador()->despublicar($this->ctx('abogado', ['id' => $cursoId]));

        self::assertSame(
            'borrador',
            $this->bd->pdo()->query("SELECT estado FROM cursos WHERE id = '{$cursoId}'")->fetchColumn(),
        );
    }

    #[Test]
    public function crearUnaCategoriaLeAsignaUnSlug(): void
    {
        $r = $this->controlador()->guardarCategoria($this->ctx('abogado', [
            'id' => '', 'nombre' => 'Comercio Exterior', 'orden' => '0', 'activa' => '1',
        ]));

        self::assertSame(302, $r->estado);

        $fila = $this->bd->pdo()->query(
            "SELECT slug FROM categorias_curso WHERE nombre = 'Comercio Exterior'"
        )->fetch();

        self::assertSame('comercio-exterior', $fila['slug']);
    }

    #[Test]
    public function editarUnaCategoriaExistenteNoLeCambiaElSlug(): void
    {
        $id = $this->categoriaId('Aduanero');

        $this->controlador()->guardarCategoria($this->ctx('abogado', [
            'id' => $id, 'nombre' => 'Aduanero avanzado', 'orden' => '2', 'activa' => '1',
        ]));

        $fila = $this->bd->pdo()->query("SELECT nombre, slug FROM categorias_curso WHERE id = '{$id}'")->fetch();

        self::assertSame('Aduanero avanzado', $fila['nombre']);
        self::assertSame('aduanero', $fila['slug']);
    }

    #[Test]
    public function unaCategoriaSinNombreNoSeGuarda(): void
    {
        $r = $this->controlador()->guardarCategoria($this->ctx('abogado', [
            'id' => '', 'nombre' => '', 'orden' => '0', 'activa' => '1',
        ]));

        self::assertStringContainsString('obligatorio', urldecode($r->cabeceras['Location']));
    }

    #[Test]
    public function agregarUnModuloLoNumeraEnOrden(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());

        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo 1']));
        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo 2']));

        $ordenes = $this->bd->pdo()->query(
            "SELECT orden FROM curso_modulos WHERE curso_id = '{$cursoId}' ORDER BY orden"
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame([1, 2], array_map('intval', $ordenes));
    }

    #[Test]
    public function eliminarUnModuloBorraSusLecciones(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());
        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo']));
        $moduloId = (string) $this->bd->pdo()->query('SELECT id FROM curso_modulos LIMIT 1')->fetchColumn();

        $this->controlador()->agregarLeccion($this->ctx('abogado', [
            'modulo_id' => $moduloId, 'titulo' => 'Lección', 'duracion_min' => '10',
        ]));

        self::assertSame(1, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_lecciones')->fetchColumn());

        $this->controlador()->eliminarModulo($this->ctx('abogado', ['id' => $moduloId]));

        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_modulos')->fetchColumn());
        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_lecciones')->fetchColumn());
    }

    #[Test]
    public function agregarUnaLeccionSinTituloFalla(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());
        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo']));
        $moduloId = (string) $this->bd->pdo()->query('SELECT id FROM curso_modulos LIMIT 1')->fetchColumn();

        $r = $this->controlador()->agregarLeccion($this->ctx('abogado', ['modulo_id' => $moduloId, 'titulo' => '']));

        self::assertStringContainsString('titulo', strtolower(urldecode($r->cabeceras['Location'])));
        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_lecciones')->fetchColumn());
    }

    #[Test]
    public function eliminarUnaLeccion(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());
        $this->controlador()->agregarModulo($this->ctx('abogado', ['curso_id' => $cursoId, 'titulo' => 'Módulo']));
        $moduloId = (string) $this->bd->pdo()->query('SELECT id FROM curso_modulos LIMIT 1')->fetchColumn();
        $this->controlador()->agregarLeccion($this->ctx('abogado', ['modulo_id' => $moduloId, 'titulo' => 'Lección']));
        $leccionId = (string) $this->bd->pdo()->query('SELECT id FROM curso_lecciones LIMIT 1')->fetchColumn();

        $this->controlador()->eliminarLeccion($this->ctx('abogado', ['id' => $leccionId]));

        self::assertSame(0, (int) $this->bd->pdo()->query('SELECT COUNT(*) FROM curso_lecciones')->fetchColumn());
    }

    #[Test]
    public function editarSinIdMuestraElFormularioVacio(): void
    {
        $r = $this->controlador()->editar($this->ctx('abogado'));

        self::assertSame(200, $r->estado);
        self::assertStringNotContainsString('Curso completo', $r->cuerpo);
    }

    #[Test]
    public function editarConIdMuestraElCursoExistente(): void
    {
        $cat = $this->categoriaId();
        $this->controlador()->guardar($this->ctx('abogado', [
            'id' => '', 'categoria_id' => $cat, 'titulo' => 'Curso para editar',
            'resumen' => 'r', 'descripcion' => 'd', 'lo_que_aprendera' => 'x',
            'nivel' => 'basico', 'precio_cop' => '100000', 'orden' => '0',
        ]));
        $cursoId = (string) $this->bd->pdo()->query(
            "SELECT id FROM cursos WHERE titulo = 'Curso para editar'"
        )->fetchColumn();

        $r = $this->controlador()->editar($this->ctx('abogado', [], ['id' => $cursoId]));

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('Curso para editar', $r->cuerpo);
    }

    #[Test]
    public function categoriasMuestraLasCategoriasExistentes(): void
    {
        $this->categoriaId('Comercio internacional');

        $r = $this->controlador()->categorias($this->ctx('abogado'));

        self::assertSame(200, $r->estado);
        self::assertStringContainsString('Comercio internacional', $r->cuerpo);
    }

    #[Test]
    public function publicarDejaUnaEntradaEnLaAuditoria(): void
    {
        $cursoId = $this->crearCursoCompleto($this->categoriaId());

        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$moduloId, $cursoId, 'Módulo único', 1]);

        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$leccionId, $moduloId, 'Lección única', 1]);

        $this->controlador()->publicar($this->ctx('abogado', ['id' => $cursoId]));

        $stmt = $this->bd->pdo()->prepare(
            "SELECT COUNT(*) FROM auditoria WHERE entidad = 'curso' AND accion = 'publicar' AND entidad_id = ?"
        );
        $stmt->execute([$cursoId]);

        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function despublicarUnCursoInexistenteNoEscribeAuditoriaNiExplota(): void
    {
        $idFalso = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $r = $this->controlador()->despublicar($this->ctx('abogado', ['id' => $idFalso]));

        self::assertStringContainsString('no existe', strtolower(urldecode($r->cabeceras['Location'])));

        $total = (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM auditoria WHERE entidad_id = '{$idFalso}'"
        )->fetchColumn();

        self::assertSame(0, $total);
    }

    #[Test]
    public function unRolSinCursosEditarNoPuedeGuardar(): void
    {
        $this->expectException(SinPermisoException::class);

        $this->controlador()->guardar($this->ctx('asistente', [
            'id' => '', 'categoria_id' => '', 'titulo' => 'No debería crearse',
            'resumen' => 'r', 'descripcion' => 'd', 'lo_que_aprendera' => 'x',
            'nivel' => 'basico', 'precio_cop' => '100000', 'orden' => '0',
        ]));
    }

    // ── Compras ──

    private function compraDePruebaPara(string $slug): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero-' . $slug]);
        $cursoId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cursoId, $catId, 'Curso panel', $slug, 'r', 'd', '[]', 250000, 'publicado']);

        return (new \App\Repositorios\CompraCursoRepo($this->bd))->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
    }

    #[Test]
    public function elAsistenteNoVeLasCompras(): void
    {
        $this->expectException(SinPermisoException::class);
        $this->controlador()->compras($this->ctx('asistente'));
    }

    #[Test]
    public function laListaDeComprasMuestraElNombreDelComprador(): void
    {
        $this->compraDePruebaPara('curso-panel-1');

        $html = $this->controlador()->compras($this->ctx('abogado'))->cuerpo;

        self::assertStringContainsString('Ana Gómez', $html);
    }

    #[Test]
    public function aprobarAManoMarcaLaCompraPagadaYAuditaLaAccion(): void
    {
        $compraId = $this->compraDePruebaPara('curso-panel-2');

        $r = $this->controlador()->aprobarCompra($this->ctx('abogado', ['id' => $compraId]));

        self::assertSame(302, $r->estado);

        $repo = new \App\Repositorios\CompraCursoRepo($this->bd);
        self::assertSame('pagada', $repo->porId($compraId)['estado']);

        $auditadas = (int) $this->bd->pdo()->query(
            "SELECT COUNT(*) FROM auditoria WHERE entidad = 'compra_curso' AND accion = 'aprobar_manual'"
        )->fetchColumn();
        self::assertSame(1, $auditadas);
    }

    #[Test]
    public function aprobarUnaCompraInexistenteNoTruena(): void
    {
        $r = $this->controlador()->aprobarCompra($this->ctx('abogado', [
            'id' => (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn(),
        ]));

        self::assertSame(302, $r->estado);
        self::assertStringContainsString('no+existe', $r->cabeceras['Location']);
    }
}
