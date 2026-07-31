<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Contenedor mínimo: fábricas perezosas y una instancia por servicio.
 *
 * Sin autowiring ni reflexión. Con doce servicios, la magia cuesta más de lo
 * que ahorra, y en las pruebas basta con `sustituir()`.
 */
final class Contenedor
{
    /** @var array<string,callable(self):mixed> */
    private array $fabricas = [];

    /** @var array<string,mixed> */
    private array $instancias = [];

    /** @param callable(self):mixed $fabrica */
    public function registrar(string $id, callable $fabrica): void
    {
        $this->fabricas[$id] = $fabrica;
        unset($this->instancias[$id]);
    }

    /** Reemplaza un servicio ya construido. Para dobles en pruebas. */
    public function sustituir(string $id, mixed $instancia): void
    {
        $this->instancias[$id] = $instancia;
    }

    /**
     * @template T of object
     * @param  class-string<T>|string $id
     * @return T|mixed
     */
    public function obtener(string $id): mixed
    {
        if (array_key_exists($id, $this->instancias)) {
            return $this->instancias[$id];
        }

        $fabrica = $this->fabricas[$id] ?? throw new \RuntimeException(
            "Servicio no registrado en el contenedor: {$id}"
        );

        return $this->instancias[$id] = $fabrica($this);
    }

    public function tiene(string $id): bool
    {
        return isset($this->fabricas[$id]) || array_key_exists($id, $this->instancias);
    }
}
