<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\Respuesta;

/**
 * Las páginas legales: `/privacidad` y `/condiciones`.
 *
 * Existen por dos obligaciones que convergen. La primera es del propio
 * proyecto: el motor de WhatsApp (§0.2 del CLAUDE.md) persiste teléfono,
 * nombre, correo y motivo de consulta, y encenderlo exige una política de
 * tratamiento publicada. La segunda es de Google: pasar la app OAuth de
 * `panel-pedro` a producción —sin lo cual el refresh token del calendario
 * caduca cada siete días— requiere una URL de política de privacidad válida
 * enlazada desde la página principal.
 *
 * Son texto fijo en plantilla y no bloques de `landing_bloques`, a propósito:
 * una política de tratamiento no es copy que se afine desde el panel; su
 * texto lo aprueba Pedro y cambia por decisión suya, con su firma. Editarla
 * es editar la plantilla y desplegar, que deja rastro en git.
 *
 * Sin caché: no reciben tráfico que lo justifique y así no participan del
 * juego de centinelas de la landing.
 */
final class PaginaLegal
{
    public function __construct(
        private readonly Config $config,
        private readonly string $urlBase,
    ) {
    }

    public function privacidad(): Respuesta
    {
        return $this->render(
            'privacidad',
            'Política de tratamiento de datos personales',
            'Qué datos personales trata este sitio, para qué, y cómo ejercer sus derechos sobre ellos.',
        );
    }

    public function condiciones(): Respuesta
    {
        return $this->render(
            'condiciones',
            'Condiciones del servicio',
            'Qué es este sitio, qué no es, y en qué términos se presta la asesoría.',
        );
    }

    private function render(string $slug, string $titulo, string $descripcion): Respuesta
    {
        return Respuesta::vista('legal/' . $slug, [
            'meta' => [
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'indexable' => (bool) $this->config->get('landing_indexable', true),
                'url' => rtrim($this->urlBase, '/'),
                'ruta' => '/' . $slug,
            ],
            'whatsapp' => (string) $this->config->get('whatsapp_numero_negocio', ''),
        ]);
    }
}
