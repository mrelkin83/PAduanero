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
     * Ventana de deriva: **±1 paso de 30 s**, ni uno más.
     *
     * Cubre lo único que hay que cubrir: el reloj del teléfono desajustado
     * unos segundos, y que la persona teclee el código justo cuando cambia.
     * El RFC 6238 §5.2 recomienda expresamente mantenerla al mínimo, porque
     * cada paso extra alarga la vida útil de un código robado — con ±3 un
     * código leído por encima del hombro serviría más de tres minutos.
     *
     * Combinada con el antirreplay (`verificarConContador`), la ventana real
     * de un código ya usado es cero.
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
     * Verifica un código dentro de la ventana de deriva.
     *
     * @deprecated para autenticación. Úsese `verificarConContador()`, que es
     *             lo único que permite aplicar el antirreplay del RFC 6238
     *             §5.2. Esta se conserva para el alta del segundo factor,
     *             donde todavía no hay contador que comparar.
     */
    public static function verificar(string $secretoBase32, string $codigo, ?int $momento = null): bool
    {
        return self::verificarConContador($secretoBase32, $codigo, null, $momento) !== null;
    }

    /**
     * Verifica y devuelve **el contador con el que casó**, o `null`.
     *
     * Devolver el contador es lo que hace posible el antirreplay: quien llama
     * guarda el último aceptado y no vuelve a admitir ese ni ninguno
     * anterior. Un booleano no da esa información, y por eso el RFC no se
     * puede cumplir con una función que solo diga sí o no.
     *
     * @param int|null $ultimoAceptado contador del último código válido de
     *        este usuario. Cualquier código que no sea estrictamente
     *        posterior se rechaza aunque sea criptográficamente correcto.
     */
    public static function verificarConContador(
        string $secretoBase32,
        string $codigo,
        ?int $ultimoAceptado = null,
        ?int $momento = null,
    ): ?int {
        // Se limpian separadores porque las apps muestran «123 456» y la
        // gente lo copia con el espacio.
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';

        if (strlen($codigo) !== self::DIGITOS) {
            return null;
        }

        try {
            $clave = Base32::decodificar($secretoBase32);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $contador = intdiv($momento ?? time(), self::PERIODO);

        // Se recorre de más antiguo a más nuevo y NO se corta al primer
        // acierto de forma temprana por diseño: los tres HMAC se calculan
        // igual. Es barato y evita que el tiempo de respuesta revele en qué
        // paso de la ventana estaba el código.
        $casó = null;

        for ($paso = -self::TOLERANCIA; $paso <= self::TOLERANCIA; $paso++) {
            $candidato = $contador + $paso;

            // `hash_equals` y nunca `===`: comparar cadenas con `===` corta
            // en el primer byte distinto, y esa diferencia de tiempo revela
            // cuántos dígitos iniciales acertó quien prueba.
            if (hash_equals(self::hotp($clave, $candidato), $codigo)) {
                $casó = $candidato;
            }
        }

        if ($casó === null) {
            return null;
        }

        // Antirreplay (RFC 6238 §5.2): un código ya usado no vuelve a valer,
        // ni siquiera dentro de su ventana de 30 segundos.
        if ($ultimoAceptado !== null && $casó <= $ultimoAceptado) {
            return null;
        }

        return $casó;
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
