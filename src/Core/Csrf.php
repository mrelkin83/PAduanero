<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSRF por doble envío de cookie.
 *
 * Se elige este patrón y no el token en sesión porque el formulario de login
 * también necesita protección y ahí todavía no hay sesión donde guardar nada.
 * Un solo mecanismo para todos los formularios es menos superficie que dos.
 *
 * Funciona porque un sitio de terceros puede **provocar** que el navegador
 * envíe la cookie, pero no puede **leerla** para copiar su valor al campo del
 * formulario. Con `SameSite=Lax` encima, la cookie ni siquiera viaja en un
 * POST de otro origen.
 */
final class Csrf
{
    public const COOKIE = 'pa_csrf';
    public const CAMPO = '_csrf';

    private ?string $token = null;

    public function __construct(private readonly bool $seguro = true)
    {
    }

    /** Token de esta petición; lo crea y fija la cookie si no existía. */
    public function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $existente = $_COOKIE[self::COOKIE] ?? null;

        if (is_string($existente) && preg_match('/^[a-f0-9]{64}$/', $existente) === 1) {
            return $this->token = $existente;
        }

        $this->token = bin2hex(random_bytes(32));

        if (!headers_sent()) {
            setcookie(self::COOKIE, $this->token, [
                'expires' => 0,          // de sesión de navegador
                'path' => '/',
                'secure' => $this->seguro,
                // NO es HttpOnly: el JavaScript del panel la lee para mandar
                // el token en peticiones fetch. No es un secreto de sesión,
                // solo tiene que ser inaccesible desde OTRO origen, y de eso
                // se encarga la política de mismo origen.
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        return $this->token;
    }

    public function campoOculto(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::CAMPO,
            htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8'),
        );
    }

    public function validar(Peticion $peticion): bool
    {
        // GET y HEAD no cambian estado, así que no se validan. Si alguna ruta
        // GET llegara a mutar algo, el problema es esa ruta.
        if (in_array($peticion->metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $cookie = $_COOKIE[self::COOKIE] ?? '';

        $enviado = $peticion->formulario[self::CAMPO]
            ?? $peticion->cabecera('x-csrf-token')
            ?? ($peticion->json()[self::CAMPO] ?? '');

        if (!is_string($enviado) || !is_string($cookie) || $cookie === '' || $enviado === '') {
            return false;
        }

        return hash_equals($cookie, $enviado);
    }
}
