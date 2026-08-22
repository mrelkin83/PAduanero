<?php
/**
 * ============================================================================
 * Engine — dónde el motor encuentra lo que el proyecto le presta
 * ============================================================================
 * Un contenedor estático y pequeño, no un framework de inyección. La razón es
 * el sitio donde vive esto: los proyectos que lo usan son PHP propio, sin
 * contenedor de servicios, y cada clase del motor se instancia desde media
 * docena de puntos distintos (webhook, panel, cron, scripts de diagnóstico).
 * Pasarle siete puertos por constructor a cada una obligaría a tocar todos esos
 * llamadores, que es exactamente el trabajo que este paquete quiere ahorrar.
 *
 * USO, una sola vez por petición y antes de tocar nada del motor:
 *
 *     Engine::arrancar([
 *         'db'      => new MiDb(),
 *         'secreto' => new MiCifrado(),
 *         ...
 *     ]);
 *
 * Lo que no se pase tiene un valor por defecto sensato: un proyecto sin planes
 * de suscripción no tiene que escribir un FeaturePort para decir «sí a todo».
 */

namespace ElkinLinan\WhatsappAiEngine;

use ElkinLinan\WhatsappAiEngine\Ports\ConfigPort;
use ElkinLinan\WhatsappAiEngine\Ports\DbPort;
use ElkinLinan\WhatsappAiEngine\Ports\DomainAdapter;
use ElkinLinan\WhatsappAiEngine\Ports\FeaturePort;
use ElkinLinan\WhatsappAiEngine\Ports\FormatPort;
use ElkinLinan\WhatsappAiEngine\Ports\SecretPort;
use ElkinLinan\WhatsappAiEngine\Ports\StoragePort;
use ElkinLinan\WhatsappAiEngine\Ports\TenantPort;

final class Engine
{
    /** @var array<string,object> */
    private static array $puertos = [];

    /**
     * Conecta el motor con el proyecto. Se puede llamar más de una vez: en un
     * webhook multi-negocio hay que rearrancarlo al resolver de qué negocio es
     * el mensaje, porque cambia la base de datos y con ella los secretos.
     */
    public static function arrancar(array $puertos): void
    {
        self::$puertos = $puertos + self::$puertos;
    }

    /** Olvida todo. Lo usan las pruebas entre casos. */
    public static function reiniciar(): void
    {
        self::$puertos = [];
    }

    public static function db(): DbPort
    {
        return self::obligatorio('db', DbPort::class);
    }

    public static function secretos(): SecretPort
    {
        return self::$puertos['secreto'] ??= new Defecto\SecretoEnClaro();
    }

    public static function funciones(): FeaturePort
    {
        return self::$puertos['funcion'] ??= new Defecto\TodoPermitido();
    }

    public static function negocio(): TenantPort
    {
        return self::$puertos['negocio'] ??= new Defecto\NegocioUnico();
    }

    public static function archivos(): StoragePort
    {
        return self::obligatorio('archivo', StoragePort::class);
    }

    public static function formato(): FormatPort
    {
        return self::$puertos['formato'] ??= new Defecto\PesosColombianos();
    }

    public static function config(): ConfigPort
    {
        // Por defecto se leen las constantes/env de plataforma (WA_EVOLUTION_URL,
        // WA_TTS_URL, WA_STT_URL, WA_VISION_URL…): la conexión y la voz quedan
        // preconfiguradas igual que en el módulo original sin escribir un puerto.
        return self::$puertos['config'] ??= new Defecto\ConfigDeEntorno();
    }

    /** El adaptador del negocio. Sin esto el motor no puede vender nada. */
    public static function dominio(): DomainAdapter
    {
        return self::obligatorio('dominio', DomainAdapter::class);
    }

    /** ¿Está conectado este puerto? Para que el llamador decida sin excepciones. */
    public static function tiene(string $puerto): bool
    {
        return isset(self::$puertos[$puerto]);
    }

    /**
     * Los puertos sin valor por defecto razonable fallan RUIDOSAMENTE. Inventar
     * una base de datos vacía o un adaptador que no vende nada convertiría un
     * error de arranque en un bot que contesta cosas raras sin que nadie sepa
     * por qué.
     */
    private static function obligatorio(string $clave, string $interfaz): object
    {
        if (!isset(self::$puertos[$clave])) {
            throw new \RuntimeException(
                "El motor de WhatsApp no está conectado: falta el puerto '{$clave}' ({$interfaz}). "
                . 'Llama a Engine::arrancar([...]) antes de usarlo.'
            );
        }
        return self::$puertos[$clave];
    }
}
