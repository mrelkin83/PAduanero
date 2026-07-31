<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * TOTP según RFC 6238 (sobre HOTP, RFC 4226).
 *
 * Sobre por qué va escrito aquí y no con una librería: `docs/CONTRATOS.md`
 * prohíbe el cifrado casero, y con razón — pero esto no es cifrado. Es una
 * función derivada de `hash_hmac`, que sí es una primitiva vetada por PHP, y
 * está especificada al detalle en un RFC que además **publica vectores de
 * prueba oficiales**. `tests/Unidad/TotpTest.php` los verifica los diez.
 * Correctitud demostrable y cero dependencias nuevas.
 *
 * Si el PO prefiere `spomky-labs/otphp`, el cambio es de una clase y las
 * pruebas siguen sirviendo tal cual.
 */
final class Totp
{
    private const PERIODO = 30;
    private const DIGITOS = 6;

    /**
     * Ventana de tolerancia, en pasos de 30 s hacia atrás y hacia adelante.
     *
     * 1 = ±30 s. Es el equilibrio habitual: cubre el reloj desajustado del
     * teléfono y que la persona teclee el código justo al cambiar, sin
     * ampliar de más la ventana de un código robado.
     */
    private const TOLERANCIA = 1;

    /** Secreto nuevo de 160 bits, que es lo que recomienda el RFC 4226. */
    public static function generarSecreto(): string
    {
        return Base32::codificar(random_bytes(20));
    }

    public static function codigo(string $secretoBase32, ?int $momento = null, string $algoritmo = 'sha1'): string
    {
        $contador = intdiv($momento ?? time(), self::PERIODO);

        return self::hotp(Base32::decodificar($secretoBase32), $contador, $algoritmo);
    }

    /**
     * Verifica un código dentro de la ventana de tolerancia.
     *
     * La comparación es con `hash_equals`: comparar códigos con `===` filtra
     * por tiempo cuántos dígitos iniciales acertó quien prueba.
     */
    public static function verificar(string $secretoBase32, string $codigo, ?int $momento = null): bool
    {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';

        if (strlen($codigo) !== self::DIGITOS) {
            return false;
        }

        $clave = Base32::decodificar($secretoBase32);
        $contador = intdiv($momento ?? time(), self::PERIODO);

        for ($paso = -self::TOLERANCIA; $paso <= self::TOLERANCIA; $paso++) {
            if (hash_equals(self::hotp($clave, $contador + $paso), $codigo)) {
                return true;
            }
        }

        return false;
    }

    /** URI `otpauth://` que se pega en la app de autenticación. */
    public static function uri(string $secretoBase32, string $cuenta, string $emisor = 'Pedro Abogado'): string
    {
        return 'otpauth://totp/' . rawurlencode($emisor) . ':' . rawurlencode($cuenta)
            . '?' . http_build_query([
                'secret' => $secretoBase32,
                'issuer' => $emisor,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITOS,
                'period' => self::PERIODO,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Segundos que le quedan de vida al código actual. Para la interfaz. */
    public static function segundosRestantes(?int $momento = null): int
    {
        return self::PERIODO - (($momento ?? time()) % self::PERIODO);
    }

    /** HOTP, RFC 4226 §5.3: truncamiento dinámico del HMAC. */
    private static function hotp(string $clave, int $contador, string $algoritmo = 'sha1'): string
    {
        // El contador va en 8 bytes big-endian. `J` no existe en pack(), así
        // que se arma con dos enteros de 32 bits.
        $binario = pack('N2', ($contador >> 32) & 0xFFFFFFFF, $contador & 0xFFFFFFFF);

        $hmac = hash_hmac($algoritmo, $binario, $clave, true);

        $desplazamiento = ord($hmac[strlen($hmac) - 1]) & 0x0F;

        $valor = ((ord($hmac[$desplazamiento]) & 0x7F) << 24)
            | ((ord($hmac[$desplazamiento + 1]) & 0xFF) << 16)
            | ((ord($hmac[$desplazamiento + 2]) & 0xFF) << 8)
            | (ord($hmac[$desplazamiento + 3]) & 0xFF);

        return str_pad((string) ($valor % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }
}
