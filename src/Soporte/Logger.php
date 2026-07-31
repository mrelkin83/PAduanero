<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Log en JSON por líneas, con redacción de datos personales.
 *
 * El error 7 de docs/CONTRATOS.md prohíbe registrar contenido de mensajes,
 * NIT o credenciales. Confiar en que quien llama se acuerde no funciona: la
 * redacción vive aquí, en el único sitio por el que pasa todo.
 *
 * Redacta por dos vías, y hacen falta las dos:
 *   · por NOMBRE de clave — cubre lo estructurado (`mensaje`, `nit`, `token`)
 *   · por PATRÓN sobre el valor — cubre lo que llega dentro de un texto
 *     libre, como un teléfono en medio del mensaje de una excepción.
 */
final class Logger
{
    private const NIVELES = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40];

    /** Claves cuyo valor no se registra nunca, se llamen como se llamen. */
    private const CLAVES_PROHIBIDAS = [
        'mensaje', 'texto', 'contenido', 'descripcion', 'descripcion_cliente',
        'extracto', 'resumen', 'historial', 'buffer_mensajes', 'notas_internas',
        'nit', 'nit_cifrado', 'documento', 'razon_social',
        'password', 'password_hash', 'clave', 'secret', 'totp_secret_cifrado',
        'token', 'api_key', 'apikey', 'authorization', 'valor_cifrado',
        'master_key', 'pepper_telefono', 'firma', 'signature',
        'telefono', 'telefono_alertas', 'email', 'correo',
    ];

    private readonly int $umbral;

    public function __construct(
        private readonly string $ruta,
        string $nivel = 'info',
    ) {
        $this->umbral = self::NIVELES[strtolower($nivel)] ?? self::NIVELES['info'];
    }

    public static function desdeEntorno(): self
    {
        return new self(
            Entorno::obtener('LOG_RUTA', dirname(__DIR__, 2) . '/storage/logs/app.log') ?? '',
            Entorno::obtener('LOG_NIVEL', 'info') ?? 'info',
        );
    }

    /** @param array<string,mixed> $contexto */
    public function debug(string $evento, array $contexto = []): void
    {
        $this->registrar('debug', $evento, $contexto);
    }

    /** @param array<string,mixed> $contexto */
    public function info(string $evento, array $contexto = []): void
    {
        $this->registrar('info', $evento, $contexto);
    }

    /** @param array<string,mixed> $contexto */
    public function warn(string $evento, array $contexto = []): void
    {
        $this->registrar('warn', $evento, $contexto);
    }

    /** @param array<string,mixed> $contexto */
    public function error(string $evento, array $contexto = []): void
    {
        $this->registrar('error', $evento, $contexto);
    }

    /** @param array<string,mixed> $contexto */
    private function registrar(string $nivel, string $evento, array $contexto): void
    {
        if (self::NIVELES[$nivel] < $this->umbral) {
            return;
        }

        $linea = json_encode(
            [
                'ts' => Fechas::ahora()->format('c'),
                'nivel' => $nivel,
                'evento' => self::redactarTexto($evento),
                'ctx' => self::redactar($contexto),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($linea === false) {
            return;
        }

        $directorio = dirname($this->ruta);
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0o770, true);
        }

        // LOCK_EX: el worker del outbox y PHP-FPM escriben el mismo archivo.
        @file_put_contents($this->ruta, $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<array-key,mixed> $datos
     * @return array<array-key,mixed>
     */
    public static function redactar(array $datos, int $profundidad = 0): array
    {
        if ($profundidad > 6) {
            return ['…' => 'demasiado anidado'];
        }

        $limpio = [];
        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && self::claveProhibida($clave)) {
                $limpio[$clave] = '[redactado]';
                continue;
            }

            if (is_array($valor)) {
                $limpio[$clave] = self::redactar($valor, $profundidad + 1);
            } elseif (is_string($valor)) {
                $limpio[$clave] = self::redactarTexto($valor);
            } elseif (is_scalar($valor) || $valor === null) {
                $limpio[$clave] = $valor;
            } else {
                $limpio[$clave] = '[' . get_debug_type($valor) . ']';
            }
        }

        return $limpio;
    }

    private static function claveProhibida(string $clave): bool
    {
        $normal = strtolower($clave);
        foreach (self::CLAVES_PROHIBIDAS as $prohibida) {
            if ($normal === $prohibida || str_contains($normal, $prohibida)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redacción por patrón, para lo que llega dentro de texto libre.
     *
     * No pretende ser exhaustiva — eso es imposible sobre texto libre. Cubre
     * lo que de verdad se cuela: teléfonos colombianos, NIT y correos dentro
     * del mensaje de una excepción o de una URL de conexión.
     */
    public static function redactarTexto(string $texto): string
    {
        // El ORDEN importa: `preg_replace` aplica los patrones en secuencia.
        // El del DSN va primero porque el de correo casaría con
        // `contrasena@127.0.0.1` y dejaría la contraseña convertida en
        // `[email]` — redactada de casualidad, no por diseño, y solo mientras
        // el host tenga un punto.
        $patrones = [
            // Credenciales dentro de una URL de conexión.
            '/(:\/\/[^:@\s\/]+):[^@\s\/]+@/u' => '$1:[redactado]@',
            // Teléfono E.164 colombiano, con o sin +57, y móviles de 10 dígitos.
            '/\+?57\d{10}\b/u' => '[tel]',
            '/\b3\d{9}\b/u' => '[tel]',
            // NIT con dígito de verificación: 900.123.456-7 o 900123456-7.
            '/\b\d{3}[.\s]?\d{3}[.\s]?\d{3}\s?-\s?\d\b/u' => '[nit]',
            '/[\w.+-]+@[\w-]+\.[\w.-]+/u' => '[email]',
        ];

        return (string) preg_replace(
            array_keys($patrones),
            array_values($patrones),
            $texto,
        );
    }
}
