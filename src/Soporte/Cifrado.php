<?php

declare(strict_types=1);

namespace App\Soporte;

use App\Excepciones\CifradoException;
use App\Excepciones\ConfiguracionFatalException;

/**
 * AES-256-GCM sobre openssl_encrypt. Nada casero (docs/PLAN_BUILD.md).
 *
 * Formato del blob, uno solo para todo el sistema (ADR-011):
 *
 *     v1 ‖ nonce(12) ‖ tag(16) ‖ ciphertext
 *
 * Lo usan `credenciales.valor_cifrado`, `contactos.nit_cifrado` y
 * `usuarios.totp_secret_cifrado`. Por eso `credenciales` no tiene columnas
 * `nonce` ni `tag`: eran un segundo camino de código para lo mismo.
 *
 * El byte de versión NO es `key_version`. Este dice qué layout tiene el blob;
 * `key_version` dice qué clave maestra lo cifró y lo mueve
 * `Credenciales::rotarClaveMaestra()`. Rotan por razones distintas.
 */
final class Cifrado
{
    private const VERSION = "\x01";
    private const CIFRADO = 'aes-256-gcm';
    private const BYTES_NONCE = 12;   // el estándar para GCM
    private const BYTES_TAG = 16;

    private readonly string $claveMaestra;

    /**
     * @param string $claveMaestraB64 MASTER_KEY, 32 bytes en base64
     *
     * @throws ConfiguracionFatalException si falta o no mide 32 bytes
     */
    public function __construct(string $claveMaestraB64)
    {
        $this->claveMaestra = self::decodificar($claveMaestraB64, 'MASTER_KEY');
    }

    public static function desdeEntorno(): self
    {
        return new self(Entorno::exigir('MASTER_KEY'));
    }

    private static function decodificar(string $b64, string $nombre): string
    {
        $bruto = base64_decode($b64, true);

        if ($bruto === false) {
            throw new ConfiguracionFatalException("{$nombre} no es base64 válido.");
        }

        if (strlen($bruto) !== 32) {
            // Sin longitud no se avisa del valor, solo del tamaño: esta
            // excepción acaba en los logs.
            throw new ConfiguracionFatalException(
                "{$nombre} debe medir 32 bytes; mide " . strlen($bruto) . '. '
                . 'Generar con: openssl rand -base64 32'
            );
        }

        return $bruto;
    }

    public function cifrar(string $claro): string
    {
        $nonce = random_bytes(self::BYTES_NONCE);
        $tag = '';

        $texto = openssl_encrypt(
            $claro,
            self::CIFRADO,
            $this->claveMaestra,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::BYTES_TAG,
        );

        if ($texto === false) {
            throw new CifradoException('Falló el cifrado AES-256-GCM.');
        }

        return self::VERSION . $nonce . $tag . $texto;
    }

    /** @throws CifradoException si el tag no valida o el layout es desconocido */
    public function descifrar(string $blob): string
    {
        $minimo = 1 + self::BYTES_NONCE + self::BYTES_TAG;
        if (strlen($blob) <= $minimo) {
            throw new CifradoException('Blob cifrado truncado o vacío.');
        }

        if ($blob[0] !== self::VERSION) {
            throw new CifradoException(
                'Versión de blob desconocida: 0x' . bin2hex($blob[0])
                . '. Este build solo entiende v1.'
            );
        }

        $nonce = substr($blob, 1, self::BYTES_NONCE);
        $tag = substr($blob, 1 + self::BYTES_NONCE, self::BYTES_TAG);
        $texto = substr($blob, $minimo);

        $claro = openssl_decrypt(
            $texto,
            self::CIFRADO,
            $this->claveMaestra,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($claro === false) {
            // Puede ser clave equivocada o dato alterado. No se distingue a
            // propósito: decirlo daría un oráculo a quien manipule la base.
            throw new CifradoException('El blob no descifra: tag inválido.');
        }

        return $claro;
    }

    /* Aquí vivía `hashTelefono()`, el HMAC-SHA256 de ADR-012 con su propio
       pepper. Se retira con el motor, que era su único cliente: solo
       `contactos` guardaba teléfonos, y esa tabla ya no recibe filas.

       Se va también la exigencia de `PEPPER_TELEFONO` al arrancar. Una
       variable obligatoria que nada usa no es inofensiva: obliga a generarla
       y custodiarla en cada despliegue —`docs/RESPALDOS.md` pedía tres
       copias de ella— a cambio de nada.

       Si vuelve el motor, vuelve el ADR-012 entero y con su razón intacta:
       un SHA-256 pelado sobre un número de doce dígitos se rompe por fuerza
       bruta en segundos, y el pepper no puede derivarse de MASTER_KEY porque
       esa sí rota y un hash no es reversible. */

    /**
     * Lo único de una credencial que puede salir por HTTP.
     *
     * Se muestran 3 caracteres, y solo si el valor tiene al menos 8: con
     * secretos cortos, revelar el final ya es media pista.
     */
    public static function mascara(string $valor): string
    {
        if (mb_strlen($valor) < 8) {
            return str_repeat('•', 8);
        }

        return str_repeat('•', 8) . mb_substr($valor, -3);
    }
}
