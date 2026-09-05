<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

/**
 * La plantilla del certificado — una sola fila global (id = 1). Guarda el
 * nombre de la imagen de fondo y, en JSON, la posición y el estilo de cada
 * dato que se estampa encima.
 *
 * Los defaults de `campos()` son un certificado apaisado corriente: los
 * textos centrados a distintas alturas. Sirven tal cual mientras el operador
 * no los ajuste, y son la red si el JSON guardado viene corrupto.
 */
final class CertificadoPlantillaRepo
{
    /** Datos que se pueden estampar, con su posición y estilo por defecto. */
    private const DEFECTOS = [
        'nombre'    => ['y' => 45, 'x' => 0, 'alineacion' => 'center', 'tamano' => 30, 'color' => '#1a1a1a', 'visible' => true],
        'curso'     => ['y' => 60, 'x' => 0, 'alineacion' => 'center', 'tamano' => 20, 'color' => '#333333', 'visible' => true],
        'fecha'     => ['y' => 78, 'x' => 0, 'alineacion' => 'center', 'tamano' => 12, 'color' => '#666666', 'visible' => true],
        'documento' => ['y' => 72, 'x' => 0, 'alineacion' => 'center', 'tamano' => 12, 'color' => '#666666', 'visible' => false],
        'codigo'    => ['y' => 90, 'x' => 0, 'alineacion' => 'center', 'tamano' => 10, 'color' => '#888888', 'visible' => true],
        // El QR es una imagen, no texto: 'tamano' es su ancho en % de la hoja,
        // y 'x' su margen lateral (con alineación left/right); color no aplica.
        'qr'        => ['y' => 68, 'x' => 6, 'alineacion' => 'right', 'tamano' => 14, 'color' => '#000000', 'visible' => true],
    ];

    public function __construct(private readonly BD $bd)
    {
    }

    /** Orden y etiquetas de los campos, para el panel. */
    public static function camposDisponibles(): array
    {
        return [
            'nombre'    => 'Nombre del egresado',
            'curso'     => 'Nombre del curso',
            'fecha'     => 'Fecha de emisión',
            'documento' => 'Documento del egresado',
            'codigo'    => 'Código de verificación',
            'qr'        => 'Código QR de verificación',
        ];
    }

    /** @return array{imagen_fondo:?string,campos:array<string,array<string,mixed>>} */
    public function obtener(): array
    {
        $fila = $this->bd->pdo()->query(
            'SELECT imagen_fondo, campos_json FROM certificado_plantilla WHERE id = 1'
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'imagen_fondo' => $fila['imagen_fondo'] ?? null,
            'campos' => self::campos($fila['campos_json'] ?? null),
        ];
    }

    /**
     * Funde el JSON guardado con los defaults: cada campo hereda lo que falte,
     * así un JSON viejo al que se le agregue un campo nuevo no rompe nada.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function campos(?string $json): array
    {
        $guardado = is_string($json) ? (json_decode($json, true) ?: []) : [];
        $out = [];
        foreach (self::DEFECTOS as $clave => $def) {
            $g = is_array($guardado[$clave] ?? null) ? $guardado[$clave] : [];
            $out[$clave] = [
                'y'          => self::num($g['y'] ?? null, (int) $def['y'], 0, 100),
                'x'          => self::num($g['x'] ?? null, (int) $def['x'], 0, 100),
                'alineacion' => in_array($g['alineacion'] ?? '', ['left', 'center', 'right'], true) ? $g['alineacion'] : $def['alineacion'],
                'tamano'     => self::num($g['tamano'] ?? null, (int) $def['tamano'], 6, 96),
                'color'      => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($g['color'] ?? '')) ? $g['color'] : $def['color'],
                'visible'    => array_key_exists('visible', $g) ? (bool) $g['visible'] : (bool) $def['visible'],
            ];
        }

        return $out;
    }

    /** @param array<string,array<string,mixed>> $campos */
    public function guardar(?string $imagenFondo, array $campos): void
    {
        // Se normaliza contra los defaults antes de guardar: nada de basura en
        // la columna, venga como venga del formulario.
        $limpio = self::campos(json_encode($campos));

        if ($imagenFondo !== null) {
            $this->bd->pdo()->prepare(
                'UPDATE certificado_plantilla SET imagen_fondo = ?, campos_json = ? WHERE id = 1'
            )->execute([$imagenFondo, json_encode($limpio, JSON_UNESCAPED_UNICODE)]);
        } else {
            // imagenFondo null = no se subió una nueva; se conserva la que había.
            $this->bd->pdo()->prepare(
                'UPDATE certificado_plantilla SET campos_json = ? WHERE id = 1'
            )->execute([json_encode($limpio, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private static function num(mixed $v, int $porDefecto, int $min, int $max): int
    {
        if (!is_numeric($v)) {
            return $porDefecto;
        }

        return max($min, min($max, (int) $v));
    }
}
