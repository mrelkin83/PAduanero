<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Router propio. Patrones con `{parametro}`, sin regex a la vista.
 *
 * Se distingue 404 de 405 a propósito: si la ruta existe pero el método no
 * cuadra, decirlo ahorra media hora de depuración cuando un webhook llega por
 * GET.
 */
final class Router
{
    /** @var array<string,array<string,callable>> ruta => metodo => manejador */
    private array $rutas = [];

    public function get(string $patron, callable $manejador): void
    {
        $this->agregar('GET', $patron, $manejador);
    }

    public function post(string $patron, callable $manejador): void
    {
        $this->agregar('POST', $patron, $manejador);
    }

    private function agregar(string $metodo, string $patron, callable $manejador): void
    {
        $this->rutas['/' . trim($patron, '/')][$metodo] = $manejador;
    }

    public function despachar(Peticion $peticion): Respuesta
    {
        $rutaExiste = false;

        foreach ($this->rutas as $patron => $metodos) {
            $parametros = $this->coincide($patron, $peticion->ruta);

            if ($parametros === null) {
                continue;
            }

            $rutaExiste = true;

            $manejador = $metodos[$peticion->metodo] ?? null;
            if ($manejador === null) {
                continue;
            }

            return $manejador($peticion->conParametros($parametros));
        }

        if ($rutaExiste) {
            return Respuesta::json(
                ['error' => 'Método no permitido para esta ruta.'],
                405,
            );
        }

        return Respuesta::json(['error' => 'No encontrado.'], 404);
    }

    /** @return array<string,string>|null null si no coincide */
    private function coincide(string $patron, string $ruta): ?array
    {
        if (!str_contains($patron, '{')) {
            return $patron === $ruta ? [] : null;
        }

        $segmentosPatron = explode('/', trim($patron, '/'));
        $segmentosRuta = explode('/', trim($ruta, '/'));

        if (count($segmentosPatron) !== count($segmentosRuta)) {
            return null;
        }

        $parametros = [];
        foreach ($segmentosPatron as $i => $segmento) {
            if (str_starts_with($segmento, '{') && str_ends_with($segmento, '}')) {
                $parametros[trim($segmento, '{}')] = $segmentosRuta[$i];
                continue;
            }

            if ($segmento !== $segmentosRuta[$i]) {
                return null;
            }
        }

        return $parametros;
    }
}
