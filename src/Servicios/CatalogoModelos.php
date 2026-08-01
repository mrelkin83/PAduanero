<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Excepciones\CredencialNoEncontradaException;
use App\Servicios\Descubridores\Descubridor;
use App\Servicios\Descubridores\DescubrimientoFallido;
use App\Servicios\Descubridores\ModeloDescubierto;
use App\Soporte\Logger;

/**
 * Sincroniza `modelos_ia` con lo que cada proveedor anuncia hoy.
 *
 * El PO pidió que la lista se actualice sola. Se actualiza sola: lo que no
 * se hace sola es la **adopción**. Lo nuevo entra `activo = 0`,
 * `es_primario = 0`, `costos_verificados = 0`, y ahí espera a que una
 * persona lo mire.
 *
 * Las tres razones, en orden de importancia:
 *
 *  1. **ADR-008.** Un prompt no puede cambiar sin aprobación registrada del
 *     abogado, porque cambia lo que el bot dice. El modelo cambia lo que el
 *     bot dice igual o más. Si se ascendiera solo, sería la única pieza del
 *     sistema capaz de alterar el comportamiento del bot sin dejar firma.
 *  2. **El precio no viene en el endpoint.** Ningún proveedor lo publica
 *     ahí. Un modelo con `costo_entrada_usd_1m` NULL hace que el corte por
 *     `presupuesto_ia_mensual_usd` no corte nunca. Un guardia que deja de
 *     guardar en silencio es peor que no tenerlo.
 *  3. **El conjunto dorado.** La Etapa 4 cierra con 30 conversaciones
 *     limpias. Cambiar el modelo por debajo las deja sin valor y nadie se
 *     entera hasta que un cliente lee algo que no debía.
 *
 * Lo que sí es automático de punta a punta: enterarse. Que salga Opus 6 y
 * el panel lo muestre al día siguiente sin tocar código es exactamente lo
 * que se pidió.
 */
final class CatalogoModelos
{
    /** @var array<string,Descubridor> por formato_api */
    private array $descubridores = [];

    /** @param iterable<Descubridor> $descubridores */
    public function __construct(
        private readonly BD $bd,
        private readonly Credenciales $credenciales,
        private readonly Logger $log,
        iterable $descubridores,
    ) {
        foreach ($descubridores as $descubridor) {
            $this->descubridores[$descubridor->formato()] = $descubridor;
        }
    }

    /**
     * Recorre todos los proveedores activos.
     *
     * No se detiene ante el primer fallo: que OpenRouter esté caído no es
     * razón para no enterarse de que Anthropic sacó un modelo.
     *
     * @return list<array{proveedor:string,ok:bool,nuevos:int,vistos:int,retirados:int,error:?string}>
     */
    public function sincronizarTodo(): array
    {
        $proveedores = $this->bd->pdo()->query(
            'SELECT id, clave, nombre, base_url, formato_api
               FROM proveedores_ia WHERE activo = 1 ORDER BY clave'
        )->fetchAll();

        $resumen = [];

        foreach ($proveedores as $proveedor) {
            $resumen[] = $this->sincronizar($proveedor);
        }

        return $resumen;
    }

    /**
     * @param  array<string,mixed> $proveedor fila de `proveedores_ia`
     * @return array{proveedor:string,ok:bool,nuevos:int,vistos:int,retirados:int,error:?string}
     */
    public function sincronizar(array $proveedor): array
    {
        $clave = (string) $proveedor['clave'];
        $inicio = microtime(true);

        try {
            $descubridor = $this->descubridores[(string) $proveedor['formato_api']]
                ?? throw new DescubrimientoFallido(
                    "No hay descubridor para el formato «{$proveedor['formato_api']}»"
                );

            $modelos = $descubridor->listar(
                (string) $proveedor['base_url'],
                $this->secreto($descubridor, $clave),
            );

            $conteo = $this->aplicar((string) $proveedor['id'], $modelos);
            $latencia = (int) round((microtime(true) - $inicio) * 1000);

            $this->registrar((string) $proveedor['id'], true, $conteo, $latencia, null);

            if ($conteo['nuevos'] > 0) {
                $this->log->info('catalogo_modelos.nuevos', [
                    'proveedor' => $clave,
                    'nuevos' => $conteo['nuevos'],
                ]);
            }

            return ['proveedor' => $clave, 'ok' => true, ...$conteo, 'error' => null];
        } catch (DescubrimientoFallido | CredencialNoEncontradaException $e) {
            $latencia = (int) round((microtime(true) - $inicio) * 1000);
            $motivo = mb_substr($e->getMessage(), 0, 300);

            $this->registrar(
                (string) $proveedor['id'],
                false,
                ['nuevos' => 0, 'vistos' => 0, 'retirados' => 0],
                $latencia,
                $motivo,
            );

            $this->log->warn('catalogo_modelos.fallo', [
                'proveedor' => $clave,
                'motivo' => $motivo,
            ]);

            return [
                'proveedor' => $clave,
                'ok' => false,
                'nuevos' => 0,
                'vistos' => 0,
                'retirados' => 0,
                'error' => $motivo,
            ];
        }
    }

    /**
     * @param  list<ModeloDescubierto> $modelos
     * @return array{nuevos:int,vistos:int,retirados:int}
     */
    private function aplicar(string $proveedorId, array $modelos): array
    {
        $pdo = $this->bd->pdo();
        $ahora = date('Y-m-d H:i:s');

        $nuevos = 0;
        $vistos = 0;
        $identificadores = [];

        $existe = $pdo->prepare(
            'SELECT id FROM modelos_ia
              WHERE proveedor_id = ? AND identificador = ? AND proposito = ?'
        );

        // Solo metadatos del proveedor. `activo`, `es_primario`, los costos y
        // los ajustes de temperatura NO se tocan nunca desde aquí: son
        // decisiones de una persona y una sincronización no las revisa.
        $refrescar = $pdo->prepare(
            'UPDATE modelos_ia
                SET nombre_visible   = ?,
                    ventana_contexto = COALESCE(?, ventana_contexto),
                    max_salida       = COALESCE(?, max_salida),
                    capacidades      = COALESCE(?, capacidades),
                    visto_en         = ?,
                    retirado_en      = NULL
              WHERE id = ?'
        );

        $insertar = $pdo->prepare(
            'INSERT INTO modelos_ia
                (proveedor_id, identificador, nombre_visible, proposito, origen,
                 descubierto_en, visto_en, ventana_contexto, max_salida, capacidades,
                 es_primario, orden_fallback, activo, costos_verificados)
             VALUES (?, ?, ?, ?, \'descubierto\', ?, ?, ?, ?, ?, 0, 99, 0, 0)'
        );

        foreach ($modelos as $modelo) {
            $identificadores[] = $modelo->identificador;

            $existe->execute([$proveedorId, $modelo->identificador, $modelo->proposito]);
            $id = $existe->fetchColumn();

            $capacidades = $modelo->capacidades === []
                ? null
                : json_encode($modelo->capacidades, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($id !== false) {
                $refrescar->execute([
                    $modelo->nombreVisible,
                    $modelo->ventanaContexto,
                    $modelo->maxSalida,
                    $capacidades,
                    $ahora,
                    $id,
                ]);
                $vistos++;
                continue;
            }

            $insertar->execute([
                $proveedorId,
                $modelo->identificador,
                $modelo->nombreVisible,
                $modelo->proposito,
                $ahora,
                $ahora,
                $modelo->ventanaContexto,
                $modelo->maxSalida,
                $capacidades,
            ]);
            $nuevos++;
        }

        return [
            'nuevos' => $nuevos,
            'vistos' => $vistos,
            'retirados' => $this->retirar($proveedorId, $identificadores, $ahora),
        ];
    }

    /**
     * Marca lo que el proveedor dejó de listar.
     *
     * No se borra la fila: `consumo_ia` la referencia y el histórico de gasto
     * tiene que seguir siendo legible dentro de un año.
     *
     * Solo se retira lo que esta sincronización llegó a ver alguna vez
     * (`visto_en IS NOT NULL`). Un modelo dado de alta a mano que el
     * proveedor nunca listó —un identificador con una errata, o uno de
     * acceso privado— no es un retiro: es una fila que nunca fue confirmada,
     * y tratarla como retirada sería inventar un hecho.
     *
     * @param list<string> $identificadores los que sí siguen vivos
     */
    private function retirar(string $proveedorId, array $identificadores, string $ahora): int
    {
        $sql = 'UPDATE modelos_ia
                   SET retirado_en = ?
                 WHERE proveedor_id = ?
                   AND retirado_en IS NULL
                   AND visto_en IS NOT NULL';

        $parametros = [$ahora, $proveedorId];

        if ($identificadores !== []) {
            $huecos = implode(',', array_fill(0, count($identificadores), '?'));
            $sql .= " AND identificador NOT IN ({$huecos})";
            $parametros = [...$parametros, ...$identificadores];
        }

        $stmt = $this->bd->pdo()->prepare($sql);
        $stmt->execute($parametros);

        $retirados = $stmt->rowCount();

        if ($retirados > 0) {
            $this->avisarSiAlgunoEraPrimario($proveedorId);
        }

        return $retirados;
    }

    /**
     * Un primario retirado no apaga el bot —la cascada de `orden_fallback` lo
     * recoge— pero sí significa que se está sirviendo desde el suplente sin
     * que nadie lo haya decidido. Eso hay que decirlo fuerte.
     *
     * Se registra en `error`, no en `warn`: es la única condición de este
     * servicio que exige que alguien haga algo hoy.
     */
    private function avisarSiAlgunoEraPrimario(string $proveedorId): void
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT identificador, proposito FROM modelos_ia
              WHERE proveedor_id = ? AND es_primario = 1 AND retirado_en IS NOT NULL'
        );
        $stmt->execute([$proveedorId]);

        foreach ($stmt->fetchAll() as $fila) {
            $this->log->error('catalogo_modelos.primario_retirado', [
                'modelo' => $fila['identificador'],
                'proposito' => $fila['proposito'],
                'accion' => 'El proveedor retiró el modelo primario. La cascada de '
                    . 'fallback lo cubre, pero hay que elegir sustituto en el panel.',
            ]);
        }
    }

    private function secreto(Descubridor $descubridor, string $claveProveedor): ?string
    {
        $clave = $descubridor->claveCredencial();

        if ($clave === null) {
            return null;
        }

        return $this->credenciales->obtener($claveProveedor, $clave);
    }

    /** @param array{nuevos:int,vistos:int,retirados:int} $conteo */
    private function registrar(
        string $proveedorId,
        bool $ok,
        array $conteo,
        int $latenciaMs,
        ?string $error,
    ): void {
        $this->bd->pdo()->prepare(
            'INSERT INTO sincronizaciones_modelos
                (proveedor_id, ok, nuevos, vistos, retirados, latencia_ms, error)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $proveedorId,
            $ok ? 1 : 0,
            $conteo['nuevos'],
            $conteo['vistos'],
            $conteo['retirados'],
            $latenciaMs,
            $error,
        ]);
    }
}
