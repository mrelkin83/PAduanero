<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\AutenticacionComprador;

final class MisCursosControlador
{
    public function __construct(
        private readonly AutenticacionComprador $auth,
        private readonly CompraCursoRepo $compras,
    ) {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $token = $_COOKIE[AccesoControlador::COOKIE] ?? null;
        $comprador = (is_string($token) && $token !== '') ? $this->auth->compradorDeSesion($token) : null;

        if ($comprador === null) {
            return new Respuesta('', 302, ['Location' => '/entrar']);
        }

        return Respuesta::vista('cuenta/mis_cursos', [
            'comprador' => $comprador,
            'cursos' => $this->compras->pagadasDeComprador($comprador->id),
        ]);
    }
}
