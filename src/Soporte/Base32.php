<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Base32 (RFC 4648) sin relleno.
 *
 * Existe por una sola razón: es el formato en el que las aplicaciones de
 * autenticación esperan el secreto TOTP. No vale base64.
 */
final class Base32
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function codificar(string $binario): string
    {
        if ($binario === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($binario) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $salida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $salida .= self::ALFABETO[bindec(str_pad($grupo, 5, '0', STR_PAD_RIGHT))];
        }

        return $salida;
    }

    public static function decodificar(string $base32): string
    {
        // Las apps muestran el secreto en grupos separados por espacios y la
        // gente lo copia tal cual, con el relleno incluido.
        $limpio = strtoupper(str_replace([' ', '=', '-'], '', $base32));

        if ($limpio === '' || preg_match('/^[A-Z2-7]+$/', $limpio) !== 1) {
            throw new \InvalidArgumentException('Secreto Base32 inválido.');
        }

        $bits = '';
        foreach (str_split($limpio) as $caracter) {
            $indice = strpos(self::ALFABETO, $caracter);
            $bits .= str_pad(decbin((int) $indice), 5, '0', STR_PAD_LEFT);
        }

        $salida = '';
        foreach (str_split($bits, 8) as $grupo) {
            if (strlen($grupo) === 8) {
                $salida .= chr(bindec($grupo));
            }
        }

        return $salida;
    }
}
