<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;
use App\Modelos\Caso;
use App\Motor\Catalogo;
use App\Motor\Puntaje;

/**
 * Todo el SQL de `casos`. Incluye la asignación del radicado (ADR-014).
 */
final class CasoRepo
{
    private const CAMPOS = 'id, contacto_id, radicado_interno, area, tipo_caso, entidad,
                            seccional, urgencia, tiene_acto_admin, fecha_acto, numero_acto,
                            valor_estimado_cop, descripcion_cliente, resumen_motor,
                            puntaje_lead, estado, motivo_descarte, requiere_revision, creado_en';

    public function __construct(private readonly BD $bd)
    {
    }

    public function porId(string $id): ?Caso
    {
        $stmt = $this->bd->pdo()->prepare('SELECT ' . self::CAMPOS . ' FROM casos WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Caso::desdeFila($fila);
    }

    /** @return list<Caso> */
    public function porContacto(string $contactoId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM casos WHERE contacto_id = ? ORDER BY creado_en DESC'
        );
        $stmt->execute([$contactoId]);

        return array_map(
            static fn (array $f): Caso => Caso::desdeFila($f),
            $stmt->fetchAll(),
        );
    }

    /**
     * Crea el caso y le asigna radicado dentro de la misma transacción.
     *
     * El radicado sale de `secuencias (anio, ultimo)` tomado con
     * `SELECT … FOR UPDATE`, **nunca de `MAX(id)+1`** (ADR-014). Con dos
     * mensajes concurrentes, `MAX+1` entrega el mismo número dos veces y el
     * `UNIQUE` de `casos.radicado_interno` hace fallar la creación del caso en
     * plena conversación — es decir, el cliente ve que el bot se rompe justo
     * cuando acababa de contarle su problema.
     *
     * Que ambas cosas vayan en la misma transacción es lo que impide el caso
     * contrario: un caso sin radicado porque el proceso murió entre el INSERT
     * y el UPDATE.
     *
     * @param array<string,mixed> $datos ya saneados por `Motor\Accion`
     */
    public function crear(string $contactoId, array $datos): Caso
    {
        $pdo = $this->bd->pdo();
        $pdo->beginTransaction();

        try {
            $anio = (int) date('Y');
            $radicado = $this->siguienteRadicado($anio);

            $id = (string) $pdo->query('SELECT UUID()')->fetchColumn();
            $tipo = Catalogo::normalizarTipo($datos['tipo_caso'] ?? null);

            $pdo->prepare(
                'INSERT INTO casos
                    (id, contacto_id, radicado_interno, area, tipo_caso, entidad, seccional,
                     urgencia, tiene_acto_admin, fecha_acto, numero_acto, valor_estimado_cop,
                     descripcion_cliente, puntaje_lead, estado, requiere_revision)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $contactoId,
                $radicado,
                self::area($datos['area'] ?? null, $tipo),
                $tipo,
                $datos['entidad'] ?? null,
                $datos['seccional'] ?? null,
                $datos['urgencia'] ?? 'media',
                isset($datos['tiene_acto_admin']) ? (int) (bool) $datos['tiene_acto_admin'] : null,
                $datos['fecha_acto'] ?? null,
                $datos['numero_acto'] ?? null,
                $datos['valor_estimado_cop'] ?? null,
                $datos['descripcion'] ?? null,
                Puntaje::calcular(
                    isset($datos['tiene_acto_admin']) ? (bool) $datos['tiene_acto_admin'] : null,
                    $datos['tipo_persona'] ?? null,
                    isset($datos['valor_estimado_cop']) ? (int) $datos['valor_estimado_cop'] : null,
                    $datos['urgencia'] ?? null,
                    $datos['entidad'] ?? null,
                ),
                $datos['estado'] ?? 'nuevo',
                // Un tipo que cayó a `otro` es un caso que el catálogo no supo
                // clasificar. No se descarta: se marca para que lo mire una
                // persona, porque puede ser negocio que el catálogo no cubre.
                $tipo === 'otro' ? 1 : 0,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return $this->porId($id) ?? throw new \RuntimeException('El caso no se pudo releer.');
    }

    /**
     * Actualiza los campos del caso que el triage va llenando.
     *
     * Recalcula el puntaje al vuelo: cambiar el valor estimado sin recalcular
     * dejaría la bandeja ordenada por un número obsoleto, que es peor que no
     * ordenarla porque parece correcto.
     *
     * @param array<string,mixed> $datos
     */
    public function actualizar(string $id, array $datos): ?Caso
    {
        $actual = $this->porId($id);

        if ($actual === null) {
            return null;
        }

        $mapa = [
            'entidad' => 'entidad',
            'seccional' => 'seccional',
            'urgencia' => 'urgencia',
            'fecha_acto' => 'fecha_acto',
            'numero_acto' => 'numero_acto',
            'valor_estimado_cop' => 'valor_estimado_cop',
            'descripcion' => 'descripcion_cliente',
            'resumen_motor' => 'resumen_motor',
        ];

        $sets = [];
        $valores = [];

        foreach ($mapa as $entrada => $columna) {
            if (array_key_exists($entrada, $datos)) {
                $sets[] = "{$columna} = ?";
                $valores[] = $datos[$entrada];
            }
        }

        if (array_key_exists('tipo_caso', $datos)) {
            $sets[] = 'tipo_caso = ?';
            $valores[] = Catalogo::normalizarTipo($datos['tipo_caso']);
        }

        if (array_key_exists('tiene_acto_admin', $datos)) {
            $sets[] = 'tiene_acto_admin = ?';
            $valores[] = $datos['tiene_acto_admin'] === null
                ? null
                : (int) (bool) $datos['tiene_acto_admin'];
        }

        // El puntaje se recalcula con la mezcla de lo viejo y lo nuevo.
        $sets[] = 'puntaje_lead = ?';
        $valores[] = Puntaje::calcular(
            array_key_exists('tiene_acto_admin', $datos)
                ? ($datos['tiene_acto_admin'] === null ? null : (bool) $datos['tiene_acto_admin'])
                : $actual->tieneActoAdmin,
            $datos['tipo_persona'] ?? null,
            array_key_exists('valor_estimado_cop', $datos)
                ? ($datos['valor_estimado_cop'] === null ? null : (int) $datos['valor_estimado_cop'])
                : $actual->valorEstimadoCop,
            $datos['urgencia'] ?? $actual->urgencia,
            $datos['entidad'] ?? $actual->entidad,
        );

        $valores[] = $id;

        $this->bd->pdo()
            ->prepare('UPDATE casos SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($valores);

        return $this->porId($id);
    }

    public function actualizarEstado(string $id, string $estado, ?string $motivoDescarte = null): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE casos SET estado = ?, motivo_descarte = COALESCE(?, motivo_descarte) WHERE id = ?')
            ->execute([$estado, $motivoDescarte, $id]);
    }

    public function actualizarPuntaje(string $id, int $puntaje): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE casos SET puntaje_lead = ? WHERE id = ?')
            ->execute([max(0, min(100, $puntaje)), $id]);
    }

    public function marcarRevision(string $id, bool $requiere = true): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE casos SET requiere_revision = ? WHERE id = ?')
            ->execute([$requiere ? 1 : 0, $id]);
    }

    /**
     * La bandeja de Pedro.
     *
     * Ordena por urgencia y luego por puntaje: el puntaje sirve para priorizar
     * dentro de lo urgente, no para colocar un caso crítico de poco valor
     * detrás de uno tranquilo de mucho (`CLAUDE.md` §3.2).
     *
     * @return list<array<string,mixed>>
     */
    public function listarParaPanel(?string $estado = null, int $limite = 100): array
    {
        $sql = 'SELECT c.' . str_replace(', ', ', c.', self::CAMPOS) . ',
                       ct.nombre AS contacto_nombre, ct.telefono AS contacto_telefono
                  FROM casos c JOIN contactos ct ON ct.id = c.contacto_id';

        $parametros = [];

        if ($estado !== null) {
            $sql .= ' WHERE c.estado = ?';
            $parametros[] = $estado;
        }

        $sql .= " ORDER BY FIELD(c.urgencia,'critica','alta','media','baja'),
                           c.puntaje_lead DESC, c.creado_en DESC
                  LIMIT " . max(1, min(500, $limite));

        $stmt = $this->bd->pdo()->prepare($sql);
        $stmt->execute($parametros);

        return $stmt->fetchAll();
    }

    /**
     * Consecutivo del año con `FOR UPDATE`.
     *
     * El `INSERT … ON DUPLICATE KEY UPDATE` crea la fila del año en enero sin
     * necesidad de sembrarla, y el `LAST_INSERT_ID(ultimo + 1)` devuelve el
     * valor ya incrementado en la misma sentencia: así no hay una ventana
     * entre leer y escribir por la que se cuele otra transacción.
     */
    private function siguienteRadicado(int $anio): string
    {
        $pdo = $this->bd->pdo();

        $pdo->prepare(
            'INSERT INTO secuencias (anio, ultimo) VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)'
        )->execute([$anio]);

        $consecutivo = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

        return sprintf('PA-%d-%06d', $anio, $consecutivo);
    }

    /**
     * El área sale del tipo de caso cuando nadie la declaró.
     *
     * Cuando el tipo es común a las dos ramas —`recurso_reconsideracion`,
     * `fiscalizacion`, `otro`— `Catalogo::areaDe()` devuelve null porque
     * genuinamente no se sabe. Ahí va `mixto`, que es justo para lo que está
     * en el enum.
     *
     * Lo que NO se hace es caer en `aduanero` por defecto. Sería reintroducir
     * por la puerta de atrás el defecto que documenta `CLAUDE.md` §5: el
     * handler de fuera de alcance decía «el despacho se dedica exclusivamente
     * a derecho aduanero», y con esa premisa se rechaza a un cliente de
     * requerimiento especial, que es negocio.
     */
    private static function area(?string $declarada, string $tipo): string
    {
        if (in_array($declarada, Catalogo::AREAS, true)) {
            return (string) $declarada;
        }

        return Catalogo::areaDe($tipo) ?? 'mixto';
    }
}
