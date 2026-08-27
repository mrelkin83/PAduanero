<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\Comprador;
use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Repositorios\IntentoAccesoRepo;

/**
 * Entrada de comprador — hermana de `Autenticacion` (el panel), pero sin
 * TOTP ni roles: un comprador solo necesita probar que es dueño de su
 * correo y su clave. Mismo cuidado contra fuerza bruta y enumeración.
 */
final class AutenticacionComprador
{
    /** Fallos por IP en 15 minutos antes de cortar. */
    private const TOPE_POR_IP = 20;

    public function __construct(
        private readonly CompradorRepo $compradores,
        private readonly CompradorSesionRepo $sesiones,
        private readonly IntentoAccesoRepo $intentos,
        private readonly int $duracionMinutos = 43_200,
    ) {
    }

    /** @return array{ok:bool,motivo?:string,comprador?:Comprador} */
    public function verificarCredenciales(string $correo, string $password, ?string $ip): array
    {
        $correo = mb_strtolower(trim($correo));

        if ($this->intentos->fallosRecientes('login_comprador', $ip) >= self::TOPE_POR_IP) {
            $this->intentos->registrar('login_comprador', $ip, false, $correo);

            return ['ok' => false, 'motivo' => 'Demasiados intentos desde esta conexión. Espere unos minutos.'];
        }

        if (!$this->compradores->verificarPassword($correo, $password)) {
            $this->intentos->registrar('login_comprador', $ip, false, $correo);

            return ['ok' => false, 'motivo' => 'Credenciales incorrectas.'];
        }

        $comprador = $this->compradores->porCorreo($correo);

        if ($comprador === null) {
            return ['ok' => false, 'motivo' => 'Credenciales incorrectas.'];
        }

        $this->intentos->registrar('login_comprador', $ip, true, $correo);

        return ['ok' => true, 'comprador' => $comprador];
    }

    /** @return string token de sesión en claro, para la cookie */
    public function abrirSesion(Comprador $comprador, ?string $ip, ?string $userAgent): string
    {
        return $this->sesiones->crear($comprador->id, $this->duracionMinutos, $ip, $userAgent);
    }

    public function cerrarSesion(string $token): void
    {
        $this->sesiones->revocar($token);
    }

    public function compradorDeSesion(string $token): ?Comprador
    {
        $sesion = $this->sesiones->vigente($token);

        if ($sesion === null) {
            return null;
        }

        return $this->compradores->porId($sesion['comprador_id']);
    }
}
