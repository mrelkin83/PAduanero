<?php

declare(strict_types=1);

namespace App\Soporte;

/**
 * Envuelve el contenido de un correo en el diseño de la marca «Pedro.».
 *
 * Todo va con estilos EN LÍNEA y una tabla de ancho fijo: los clientes de
 * correo (Gmail, Outlook) ignoran el CSS de <style> y no cargan imágenes
 * remotas, así que el encabezado es texto, no un logo. Fondo claro y un único
 * acento de oro, en la línea de Lex Aeterna pero legible en bandeja.
 */
final class PlantillaCorreo
{
    private const ORO = '#9a7b2e';
    private const TINTA = '#1a1a1a';
    private const ACERO = '#666666';

    /**
     * @param string      $titulo   encabezado del cuerpo (h1)
     * @param string      $cuerpo   HTML ya escapado del contenido (párrafos)
     * @param array{texto:string,url:string}|null $accion botón opcional
     */
    public static function envolver(string $titulo, string $cuerpo, ?array $accion = null): string
    {
        $e = Vista::e(...);
        $boton = '';
        if ($accion !== null && ($accion['url'] ?? '') !== '') {
            $boton = '<tr><td style="padding:8px 0 24px;">'
                . '<a href="' . $e($accion['url']) . '" style="display:inline-block;background:' . self::ORO
                . ';color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;font-size:15px;">'
                . $e($accion['texto']) . '</a></td></tr>';
        }

        $anio = date('Y');

        return <<<HTML
        <!doctype html>
        <html lang="es-CO"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1"></head>
        <body style="margin:0;padding:0;background:#f4f4f2;font-family:Arial,Helvetica,sans-serif;color:{self::TINTA};">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f2;padding:24px 0;">
        <tr><td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:92%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e6e6e3;">
            <tr><td style="background:{self::TINTA};padding:20px 32px;">
                <span style="color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:.5px;">Pedro<span style="color:{self::ORO};">.</span></span>
                <span style="color:#9a9a9a;font-size:12px;"> &nbsp; Abogado aduanero</span>
            </td></tr>
            <tr><td style="padding:32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr><td style="font-size:20px;font-weight:bold;padding-bottom:16px;color:{self::TINTA};">{$titulo}</td></tr>
                <tr><td style="font-size:15px;line-height:1.6;color:#333333;">{$cuerpo}</td></tr>
                {$boton}
                </table>
            </td></tr>
            <tr><td style="padding:18px 32px;background:#faf9f6;border-top:1px solid #eeece6;font-size:12px;color:{self::ACERO};">
                Pedro · Abogado especialista en derecho aduanero y comercio exterior<br>
                pedroabogadoaduanero.com &nbsp;·&nbsp; © {$anio}
            </td></tr>
            </table>
        </td></tr>
        </table>
        </body></html>
        HTML;
    }
}
