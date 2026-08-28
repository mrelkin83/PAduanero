<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\BD;
use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\AutenticacionComprador;
use App\Servicios\Cursos;

final class AulaControlador
{
    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly Cursos $cursos,
        private readonly CompraCursoRepo $compras,
        private readonly AccesoLeccion $acceso,
        private readonly BD $bd,
    ) {
    }

    public function aula(Peticion $peticion, string $slug): Respuesta
    {
        $comprador = $this->compradorActual();
        if ($comprador === null) {
            return new Respuesta('', 302, ['Location' => '/entrar']);
        }

        $curso = $this->cursos->porSlug($slug);
        if ($curso === null || !$this->compras->tienePagada($comprador->id, $curso['id'])) {
            return new Respuesta('', 302, ['Location' => '/mis-cursos']);
        }

        return Respuesta::vista('cuenta/aula', [
            'curso' => $curso,
            'modulos' => $this->temario($curso['id']),
        ]);
    }

    /** @return \App\Modelos\Comprador|null */
    private function compradorActual(): ?\App\Modelos\Comprador
    {
        $token = $_COOKIE[AccesoControlador::COOKIE] ?? null;

        return (is_string($token) && $token !== '') ? $this->auth->compradorDeSesion($token) : null;
    }

    /** @return list<array{id:string,titulo:string,lecciones:list<array<string,mixed>>}> */
    private function temario(string $cursoId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, titulo, orden FROM curso_modulos WHERE curso_id = ? ORDER BY orden'
        );
        $stmt->execute([$cursoId]);
        $modulos = $stmt->fetchAll();

        foreach ($modulos as &$modulo) {
            $stmtL = $this->bd->pdo()->prepare(
                'SELECT id, titulo, duracion_min, orden, vista_previa_gratis
                   FROM curso_lecciones WHERE modulo_id = ? ORDER BY orden'
            );
            $stmtL->execute([$modulo['id']]);
            $modulo['lecciones'] = $stmtL->fetchAll();
        }
        unset($modulo);

        return $modulos;
    }
}
