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
        private readonly \App\Soporte\BunnyStream $bunny,
        private readonly \App\Repositorios\CursoMaterialRepo $materiales,
        private readonly ProgresoCurso $progreso,
        private readonly \App\Repositorios\CertificadoRepo $certificados,
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

        $conteo = $this->progreso->conteo($comprador->id, $curso['id']);
        $compraId = $this->compras->idDePagadaPorComprador($comprador->id, $curso['id']);
        $certificado = $compraId !== null ? $this->certificados->porCompra($compraId) : null;

        return Respuesta::vista('cuenta/aula', [
            'curso' => $curso,
            'modulos' => $this->temario($curso['id']),
            'progreso' => $conteo,
            'tieneCertificado' => $certificado !== null,
        ]);
    }

    public function leccion(Peticion $peticion, string $slug, string $leccionId): Respuesta
    {
        $curso = $this->cursos->porSlug($slug);
        if ($curso === null) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $leccion = $this->leccionDelCurso($curso['id'], $leccionId);
        if ($leccion === null) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $comprador = $this->compradorActual();
        if (!$this->acceso->puedeVer($comprador, $leccion, $curso['id'])) {
            return $comprador === null
                ? new Respuesta('', 302, ['Location' => '/entrar'])
                : new Respuesta('', 302, ['Location' => '/mis-cursos']);
        }

        if ($comprador !== null) {
            $compraId = $this->compras->idDePagadaPorComprador($comprador->id, $curso['id']);
            if ($compraId !== null) {
                $this->progreso->registrarVista($comprador->id, $leccionId, $curso['id'], $compraId);
            }
        }

        return Respuesta::vista('cuenta/leccion', [
            'curso' => $curso,
            'leccion' => $leccion,
            'materiales' => $this->materiales->deLeccion($leccionId),
            'urlVideo' => ($leccion['video_bunny_id'] !== null && $this->bunny->disponible())
                ? $this->bunny->urlEmbed((string) $leccion['video_bunny_id'])
                : null,
        ]);
    }

    private const MIMES_MATERIAL = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip', 'jpg' => 'image/jpeg', 'png' => 'image/png',
    ];

    public function material(Peticion $peticion, string $slug, string $leccionId, string $materialId): Respuesta
    {
        $curso = $this->cursos->porSlug($slug);
        if ($curso === null) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $leccion = $this->leccionDelCurso($curso['id'], $leccionId);
        if ($leccion === null) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $material = $this->materiales->porId($materialId);
        if ($material === null || $material['leccion_id'] !== $leccionId) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $comprador = $this->compradorActual();
        if (!$this->acceso->puedeVer($comprador, $leccion, $curso['id'])) {
            return $comprador === null
                ? new Respuesta('', 302, ['Location' => '/entrar'])
                : new Respuesta('', 302, ['Location' => '/mis-cursos']);
        }

        $ruta = dirname(__DIR__, 2) . '/storage/cursos/materiales/' . $leccionId
            . '/' . $material['archivo'] . '.' . $material['extension'];

        if (!is_file($ruta)) {
            return Respuesta::texto('No encontrado.', 404);
        }

        $mime = self::MIMES_MATERIAL[$material['extension']] ?? 'application/octet-stream';

        return Respuesta::archivo(
            (string) file_get_contents($ruta),
            $material['nombre'] . '.' . $material['extension'],
            $mime,
        );
    }

    /** @return array<string,mixed>|null */
    private function leccionDelCurso(string $cursoId, string $leccionId): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT cl.* FROM curso_lecciones cl
               JOIN curso_modulos cm ON cm.id = cl.modulo_id
              WHERE cl.id = ? AND cm.curso_id = ?'
        );
        $stmt->execute([$leccionId, $cursoId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
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
