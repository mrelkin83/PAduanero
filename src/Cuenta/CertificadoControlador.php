<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CertificadoRepo;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\AutenticacionComprador;
use App\Servicios\Cursos;

final class CertificadoControlador
{
    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly Cursos $cursos,
        private readonly CompraCursoRepo $compras,
        private readonly CertificadoRepo $certificados,
        private readonly CertificadoPdf $pdf,
    ) {
    }

    public function descargar(Peticion $peticion, string $slug): Respuesta
    {
        $comprador = $this->compradorActual();
        if ($comprador === null) {
            return new Respuesta('', 302, ['Location' => '/entrar']);
        }

        $curso = $this->cursos->porSlug($slug);
        $compraId = $curso !== null ? $this->compras->idDePagadaPorComprador($comprador->id, $curso['id']) : null;
        if ($compraId === null) {
            return new Respuesta('', 302, ['Location' => '/mis-cursos']);
        }

        $certificado = $this->certificados->porCompra($compraId);
        if ($certificado === null) {
            return new Respuesta('', 302, ['Location' => '/mis-cursos/' . $slug]);
        }

        $bytes = $this->pdf->generar(
            $comprador->id,
            (string) $curso['titulo'],
            (string) $certificado['codigo_verificacion'],
            substr((string) $certificado['emitido_en'], 0, 10),
        );

        return Respuesta::archivo($bytes, 'certificado-' . $slug . '.pdf', 'application/pdf');
    }

    public function verificarMostrar(Peticion $peticion): Respuesta
    {
        $codigo = trim((string) ($peticion->consulta['codigo'] ?? ''));
        if ($codigo === '') {
            return Respuesta::vista('cuenta/certificado_verificar', []);
        }

        return Respuesta::vista('cuenta/certificado_resultado', [
            'certificado' => $this->certificados->porCodigo($codigo),
        ]);
    }

    public function verificarBuscar(Peticion $peticion, string $codigo): Respuesta
    {
        return Respuesta::vista('cuenta/certificado_resultado', [
            'certificado' => $this->certificados->porCodigo($codigo),
        ]);
    }

    /** @return \App\Modelos\Comprador|null */
    private function compradorActual(): ?\App\Modelos\Comprador
    {
        $token = $_COOKIE[AccesoControlador::COOKIE] ?? null;

        return (is_string($token) && $token !== '') ? $this->auth->compradorDeSesion($token) : null;
    }
}
