<?php

declare(strict_types=1);

namespace App\Servicios\Probadores;

/**
 * «Probar conexión» de un servicio externo.
 *
 * Uno por integración, enchufables en `CredencialesAes`. La razón de que sea
 * una interfaz y no un `switch`: cada servicio se prueba de forma distinta y
 * llegan en etapas distintas —Wompi en la 3, Chatwoot y Evolution en la 2,
 * el LLM en la 4—, así que añadir uno no debe tocar el servicio de
 * credenciales.
 */
interface Probador
{
    /** Clave de `credenciales.servicio` que atiende este probador. */
    public function servicio(): string;

    /**
     * Qué claves necesita para poder probar. `CredencialesAes` las descifra y
     * se las pasa; así el probador nunca toca la base ni el cifrado.
     *
     * @return list<string>
     */
    public function clavesRequeridas(): array;

    /**
     * @param  array<string,string> $credenciales descifradas, por clave
     * @param  string               $entorno      produccion|pruebas
     * @return array{ok:bool,mensaje:string,detalle?:array<string,mixed>}
     */
    public function probar(array $credenciales, string $entorno): array;
}
