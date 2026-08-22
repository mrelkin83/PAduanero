<?php

declare(strict_types=1);

namespace App\Wa;

use App\Core\BD;
use App\Soporte\Cifrado;
use App\Soporte\Entorno;
use App\Soporte\Logger;
use ElkinLinan\WhatsappAiEngine\Engine;

/**
 * Conecta el motor vendorizado (`packages/whatsapp-engine`) con este
 * proyecto: es el equivalente de PuertosControlBarMax, en cuatro puertos.
 *
 * Se llama UNA vez por petición, antes de tocar cualquier clase del motor.
 */
final class MotorWa
{
    private static bool $conectado = false;

    public static function conectar(BD $bd, Cifrado $cifrado, Logger $log, string $raiz): DbMotor
    {
        $db = new DbMotor($bd);

        if (self::$conectado) {
            return $db;
        }
        self::$conectado = true;

        // El ConfigPort por defecto del motor lee constantes PHP; el .env de
        // PAduanero no pasa por putenv(), así que aquí se puentea lo mínimo.
        if (!defined('APP_URL')) {
            define('APP_URL', (string) (Entorno::obtener('APP_URL', '') ?? ''));
        }

        Engine::arrancar([
            'db' => $db,
            'secreto' => new SecretoMotor($cifrado),
            'archivo' => new ArchivosMotor($raiz . '/storage/wa', (string) (Entorno::obtener('APP_URL', '') ?? '')),
            'dominio' => new AdaptadorDespacho($db, new GoogleCalendar($bd, $cifrado, $log)),
            // 'formato' queda en el defecto: pesos colombianos.
            // 'funcion' y 'negocio' quedan en el defecto: sin planes, un solo negocio.
        ]);

        return $db;
    }
}
