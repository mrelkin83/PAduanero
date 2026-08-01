<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * Catálogos cerrados del motor.
 *
 * La fuente normativa es `CLAUDE.md` §5. El array `TIPOS_CASO` de
 * `motor/index.js` tiene 21 valores y **ninguno tributario**: ese es el que
 * está desactualizado, y con esa lista el bot rechazaría clientes de
 * requerimiento especial, que son negocio.
 *
 * Cerrado significa cerrado: el saneador fuerza `otro` ante cualquier valor
 * que no esté aquí. Si el modelo inventa un tipo, no llega a la base.
 */
final class Catalogo
{
    /** @var list<string> */
    public const ADUANERO = [
        'aprehension_mercancia',
        'decomiso',
        'cancelacion_levante',
        'firmeza_declaracion',
        'clasificacion_arancelaria',
        'valoracion_aduanera',
        'origen_tlc',
        'operativo_polfa',
        'contrabando_tecnico',
        'deposito_habilitado',
        'transporte_transito',
        'devolucion_mercancia',
        'agencia_aduanas_sancion',
    ];

    /** @var list<string> */
    public const TRIBUTARIO = [
        'requerimiento_especial',
        'liquidacion_oficial_revision',
        'fiscalizacion_renta',
        'fiscalizacion_iva',
        'sancion_tributaria',
        'devolucion_compensacion',
        'retencion_fuente',
        'precios_transferencia',
    ];

    /** @var list<string> Valen para cualquiera de las dos ramas. */
    public const COMUNES = [
        'requerimiento_ordinario',
        'proceso_sancionatorio',
        'recurso_reconsideracion',
        'nulidad_restablecimiento',
        'fiscalizacion',
        'otro',
    ];

    /**
     * Casos que el bot NO conversa (regla 5).
     *
     * Un operativo en curso o un contrabando con implicación penal se escalan
     * a Pedro sin pasar por el modelo. No es una cuestión de calidad de la
     * respuesta: es que en esos casos cualquier cosa que diga un bot puede
     * usarse después, y el contacto necesita a un abogado, no a un
     * recepcionista.
     */
    public const CRITICOS = ['operativo_polfa', 'contrabando_tecnico'];

    public const ENTIDADES = ['dian', 'polfa', 'ica', 'invima', 'otra'];

    public const URGENCIAS = ['critica', 'alta', 'media', 'baja'];

    public const AREAS = ['aduanero', 'tributario', 'mixto'];

    /** @return list<string> */
    public static function tipos(): array
    {
        return [...self::ADUANERO, ...self::TRIBUTARIO, ...self::COMUNES];
    }

    public static function esTipoValido(string $tipo): bool
    {
        return in_array($tipo, self::tipos(), true);
    }

    /** Fuerza `otro` ante cualquier valor no listado. */
    public static function normalizarTipo(mixed $tipo): string
    {
        return (is_string($tipo) && self::esTipoValido($tipo)) ? $tipo : 'otro';
    }

    /**
     * Deduce el área a partir del tipo.
     *
     * Un tipo común no dice nada por sí solo, así que se deja que el motor
     * conserve lo que ya supiera del caso en vez de inventar `aduanero` por
     * defecto.
     */
    public static function areaDe(string $tipo): ?string
    {
        if (in_array($tipo, self::ADUANERO, true)) {
            return 'aduanero';
        }

        if (in_array($tipo, self::TRIBUTARIO, true)) {
            return 'tributario';
        }

        return null;
    }

    public static function esCritico(string $tipo): bool
    {
        return in_array($tipo, self::CRITICOS, true);
    }
}
