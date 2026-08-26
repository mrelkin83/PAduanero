<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Servicios\ConfigMysql;
use App\Servicios\Cursos;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CursosTest extends CasoBaseBd
{
    private const URL = 'https://pedroabogadoaduanero.com';

    private Cursos $cursos;
    private ConfigMysql $config;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = bin2hex(random_bytes(4));
        $this->config = new ConfigMysql(
            $this->bd,
            sys_get_temp_dir() . "/pa-cursos-sent-{$sufijo}",
            sys_get_temp_dir() . "/pa-cursos-cfg-{$sufijo}.json",
        );
        $this->cursos = new Cursos($this->bd, $this->config, self::URL);
    }

    private function categoria(string $nombre = 'Aduanero'): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $slug = strtolower($nombre);

        $this->bd->pdo()->prepare(
            'INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)'
        )->execute([$id, $nombre, $slug]);

        return $id;
    }

    /** @param array<string,mixed> $overrides */
    private function curso(string $categoriaId, array $overrides = []): string
    {
        $datos = array_merge([
            'id' => (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn(),
            'titulo' => 'Clasificación arancelaria desde cero',
            'slug' => 'clasificacion-arancelaria-desde-cero',
            'resumen' => 'Aprenda a clasificar mercancías sin errores.',
            'descripcion' => 'Curso completo sobre el sistema armonizado.',
            'lo_que_aprendera' => json_encode(['Leer el arancel', 'Evitar errores comunes'], JSON_UNESCAPED_UNICODE),
            'nivel' => 'basico',
            'precio_cop' => 250000,
            'estado' => 'borrador',
        ], $overrides);

        $this->bd->pdo()->prepare(
            'INSERT INTO cursos
                (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, nivel, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $datos['id'], $categoriaId, $datos['titulo'], $datos['slug'], $datos['resumen'],
            $datos['descripcion'], $datos['lo_que_aprendera'], $datos['nivel'], $datos['precio_cop'], $datos['estado'],
        ]);

        return (string) $datos['id'];
    }

    #[Test]
    public function elCatalogoSoloListaCursosPublicados(): void
    {
        $cat = $this->categoria();
        $this->curso($cat, ['titulo' => 'Curso borrador', 'slug' => 'curso-borrador', 'estado' => 'borrador']);
        $this->curso($cat, ['titulo' => 'Curso publicado', 'slug' => 'curso-publicado', 'estado' => 'publicado']);

        $html = $this->cursos->catalogo(null)->cuerpo;

        self::assertStringContainsString('Curso publicado', $html);
        self::assertStringNotContainsString('Curso borrador', $html);
    }

    #[Test]
    public function elCatalogoSinCursosMuestraUnEstadoVacioHonesto(): void
    {
        $html = $this->cursos->catalogo(null)->cuerpo;

        self::assertStringContainsString('Todavía no hay cursos publicados', $html);
    }

    #[Test]
    public function elFiltroPorCategoriaSoloMuestraLaSuya(): void
    {
        $aduanero = $this->categoria('Aduanero');
        $otra = $this->categoria('Otra área');
        $this->curso($aduanero, ['titulo' => 'Curso aduanero', 'slug' => 'curso-aduanero', 'estado' => 'publicado']);
        $this->curso($otra, ['titulo' => 'Curso de otra área', 'slug' => 'curso-otra-area', 'estado' => 'publicado']);

        $html = $this->cursos->catalogo('aduanero')->cuerpo;

        self::assertStringContainsString('Curso aduanero', $html);
        self::assertStringNotContainsString('Curso de otra área', $html);
    }

    #[Test]
    public function elPrecioSeMuestraEnPesosNuncaEnCentavos(): void
    {
        $cat = $this->categoria();
        $this->curso($cat, ['slug' => 'con-precio', 'precio_cop' => 250000, 'estado' => 'publicado']);

        $html = $this->cursos->catalogo(null)->cuerpo;

        self::assertStringContainsString('250.000', $html);
        self::assertStringNotContainsString('25000000', $html);
    }

    #[Test]
    public function laFichaDeUnCursoBorradorEsVisibleParaQuienTieneElLinkDirecto(): void
    {
        $cat = $this->categoria();
        $this->curso($cat, ['slug' => 'aun-en-borrador', 'estado' => 'borrador']);

        $respuesta = $this->cursos->ficha('aun-en-borrador');

        self::assertSame(200, $respuesta->estado);
        self::assertStringContainsString('Borrador', $respuesta->cuerpo);
    }

    #[Test]
    public function unSlugInexistenteResponde404(): void
    {
        self::assertSame(404, $this->cursos->ficha('no-existe')->estado);
    }

    #[Test]
    public function laFichaPintaElTemarioCompletoEnOrden(): void
    {
        $cat = $this->categoria();
        $cursoId = $this->curso($cat, ['slug' => 'con-temario', 'estado' => 'publicado']);

        $moduloId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_modulos (id, curso_id, titulo, orden) VALUES (?, ?, ?, ?)'
        )->execute([$moduloId, $cursoId, 'Módulo 1: fundamentos', 1]);

        $leccionId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO curso_lecciones (id, modulo_id, titulo, duracion_min, orden)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$leccionId, $moduloId, 'Lección 1: el arancel', 12, 1]);

        $html = $this->cursos->ficha('con-temario')->cuerpo;

        self::assertStringContainsString('Módulo 1: fundamentos', $html);
        self::assertStringContainsString('Lección 1: el arancel', $html);
    }

    #[Test]
    public function laFichaDeUnBorradorNuncaEsIndexablePeroLaDeUnPublicadoSiSegunLaConfiguracion(): void
    {
        $cat = $this->categoria();
        $this->curso($cat, ['slug' => 'borrador-noindex', 'estado' => 'borrador']);
        $this->curso($cat, ['slug' => 'publicado-indexable', 'estado' => 'publicado']);

        $htmlBorrador = $this->cursos->ficha('borrador-noindex')->cuerpo;
        $htmlPublicado = $this->cursos->ficha('publicado-indexable')->cuerpo;

        self::assertStringContainsString('noindex', $htmlBorrador);
        self::assertStringNotContainsString('noindex', $htmlPublicado);
    }
}
