<?php
/**
 * ============================================================================
 * Scope — el filtro que separa a un negocio de su vecino de tabla
 * ============================================================================
 * Solo hace algo en los proyectos que guardan a VARIOS negocios en la MISMA
 * base y los separan por una columna (`empresa_id` en MayTech POS). En los que
 * dan una base a cada negocio —ControlBarMax— devuelve cadena vacía y las
 * consultas salen byte a byte iguales a como estaban.
 *
 * POR QUÉ EXISTE: el motor nació en un proyecto de una-base-por-negocio, así
 * que sus consultas no filtran por nadie: `SELECT * FROM wa_config WHERE id = 1`
 * y `SELECT * FROM wa_conversaciones WHERE telefono = ?`. Puestas sobre una
 * base compartida, la primera devuelve la configuración —y las claves— de otro
 * negocio, y la segunda, sus conversaciones. No es un detalle de rendimiento:
 * es la fuga.
 *
 * SE APLICA SOLO DONDE HACE FALTA. La inmensa mayoría de las consultas del
 * motor ya están acotadas por una clave que no se repite entre negocios
 * (`conversacion_id`, `pedido_id`, `referencia`), y añadirles el filtro sería
 * ruido. Las que lo necesitan son las que buscan «la fila de este negocio» sin
 * más pistas: wa_config, wa_agentes y la conversación por teléfono.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

use ElkinLinan\WhatsappAiEngine\Engine;

final class Scope
{
    /** Columna que separa negocios, o null si cada uno tiene su base. */
    public static function columna(): ?string
    {
        $s = Engine::negocio()->scopeFila();
        return ($s && !empty($s['columna'])) ? (string)$s['columna'] : null;
    }

    /** Valor de ESTE negocio en esa columna. */
    public static function valor(): ?int
    {
        $s = Engine::negocio()->scopeFila();
        return ($s && isset($s['valor'])) ? (int)$s['valor'] : null;
    }

    /** ¿Hay que filtrar? */
    public static function aplica(): bool
    {
        return self::columna() !== null && self::valor() !== null;
    }

    /**
     * Trozo de WHERE listo para concatenar, con su marcador. Cadena vacía
     * cuando no aplica, para que la consulta quede exactamente como estaba.
     *
     *     $sql = "SELECT * FROM wa_agentes WHERE activo = 1" . Scope::y();
     *     $db->fetch($sql, Scope::mas([]));
     */
    public static function y(string $prefijoTabla = ''): string
    {
        if (!self::aplica()) return '';
        $p = $prefijoTabla !== '' ? $prefijoTabla . '.' : '';
        return ' AND ' . $p . self::columna() . ' = ?';
    }

    /** Añade el parámetro del filtro AL FINAL de la lista, si aplica. */
    public static function mas(array $params = []): array
    {
        if (self::aplica()) $params[] = self::valor();
        return $params;
    }

    /**
     * Columna y valor para un INSERT. Devuelve ['columnas' => 'empresa_id, ',
     * 'marcadores' => '?, ', 'valores' => [7]] o los tres vacíos.
     */
    public static function paraInsert(): array
    {
        if (!self::aplica()) {
            return ['columnas' => '', 'marcadores' => '', 'valores' => []];
        }
        return ['columnas' => self::columna() . ', ', 'marcadores' => '?, ', 'valores' => [self::valor()]];
    }
}
