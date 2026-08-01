<?php

declare(strict_types=1);

namespace App\Soporte;

use App\Excepciones\ConfiguracionFatalException;

/**
 * Lector de .env.
 *
 * Cuarenta líneas propias en vez de una dependencia: es menos código que el
 * que costaría auditar la librería, y `docs/CONTRATOS.md` cierra la puerta a
 * los frameworks.
 *
 * Aquí SOLO va lo necesario para arrancar y conectar. Todo parámetro
 * operativo (precios, plazos, textos) vive en `configuraciones` y se edita
 * desde el panel — leerlo del .env es el error 6 de la lista de CONTRATOS.
 */
final class Entorno
{
    /** @var array<string,string> */
    private static array $valores = [];
    private static bool $cargado = false;

    public static function cargar(string $ruta): void
    {
        if (self::$cargado) {
            return;
        }
        self::$cargado = true;

        if (!is_readable($ruta)) {
            // Sin .env no se aborta aquí: en pruebas y en CI las variables
            // llegan por el entorno del proceso. Quien aborta es exigir().
            return;
        }

        $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Quitar el BOM de la primera línea.
        //
        // No es una rareza teórica: en Windows, guardar el .env con Notepad o
        // escribirlo con `Out-File` deja EF BB BF al principio, y sin esto la
        // clave de la primera línea pasa a llamarse "\u{FEFF}APP_ENV" — es
        // decir, **la primera variable del archivo desaparece en silencio**.
        //
        // El modo de falla depende de qué variable esté arriba, y el peor es
        // el más probable: si es `MASTER_KEY`, la aplicación no arranca y se
        // ve; si es `APP_ENV`, se asume `produccion`, las cookies se marcan
        // `Secure`, el navegador las descarta sobre http:// y uno entra al
        // panel y vuelve al formulario **sin ningún mensaje de error**. Pasó.
        if (isset($lineas[0])) {
            $lineas[0] = preg_replace('/^\x{FEFF}/u', '', $lineas[0]) ?? $lineas[0];
        }

        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }

            $partes = explode('=', $linea, 2);
            if (count($partes) !== 2) {
                continue;
            }

            $clave = trim($partes[0]);
            $valor = trim($partes[1]);

            // Comentario al final de la línea, solo si el valor no va entre
            // comillas: `LOG_NIVEL=info   # debug | info` es un caso real
            // del .env.example.
            if (!str_starts_with($valor, '"') && !str_starts_with($valor, "'")) {
                $valor = trim(preg_replace('/\s+#.*$/', '', $valor) ?? $valor);
            } elseif (strlen($valor) >= 2 && $valor[0] === $valor[strlen($valor) - 1]) {
                $valor = substr($valor, 1, -1);
            }

            self::$valores[$clave] = $valor;
        }
    }

    public static function obtener(string $clave, ?string $porDefecto = null): ?string
    {
        // El entorno real del proceso gana sobre el archivo: así systemd y
        // Docker pueden inyectar secretos sin tocar el .env.
        $delProceso = getenv($clave);
        if ($delProceso !== false && $delProceso !== '') {
            return $delProceso;
        }

        $valor = self::$valores[$clave] ?? null;

        return ($valor === null || $valor === '') ? $porDefecto : $valor;
    }

    /** @throws ConfiguracionFatalException si falta o está vacía */
    public static function exigir(string $clave): string
    {
        $valor = self::obtener($clave);
        if ($valor === null || $valor === '') {
            throw new ConfiguracionFatalException(
                "Falta la variable de entorno obligatoria {$clave}."
            );
        }

        return $valor;
    }

    public static function booleano(string $clave, bool $porDefecto = false): bool
    {
        $valor = self::obtener($clave);
        if ($valor === null) {
            return $porDefecto;
        }

        return in_array(strtolower($valor), ['1', 'true', 'si', 'sí', 'on'], true);
    }

    /** Solo para pruebas: reinicia el estado estático entre casos. */
    public static function reiniciar(): void
    {
        self::$valores = [];
        self::$cargado = false;
    }
}
