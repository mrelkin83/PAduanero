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
        // Opcional: sin outbox la sincronización sigue funcionando y el aviso
        // queda solo en el registro y en el panel. Es lo que permite correr
        // este servicio en pruebas sin montar la cola entera.
        private readonly ?Outbox $outbox = null,
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
    public function sincronizarTodo(bool $soloActivos = false): array
    {
        // Por defecto se consultan TODOS los proveedores, activos o no.
        //
        // Descubrir es una lectura: no enciende nada, no mete nada en la
        // cascada, y los modelos siguen entrando inactivos y sin costo. Pero
        // saltarse los inactivos convertía el alta de un proveedor en un
        // callejón sin salida: dabas de alta DeepSeek, guardabas su llave,
        // pulsabas sincronizar y no pasaba nada — sin error y sin explicación,
        // porque el proveedor nacía apagado.
        //
        // `$soloActivos` existe para el cron: ahí sí interesa no gastar
        // peticiones diarias contra proveedores que nadie usa.
        $sql = 'SELECT id, clave, nombre, base_url, formato_api, activo
                  FROM proveedores_ia';

        if ($soloActivos) {
            $sql .= ' WHERE activo = 1';
        }

        $proveedores = $this->bd->pdo()->query($sql . ' ORDER BY activo DESC, clave')->fetchAll();

        $resumen = [];

        foreach ($proveedores as $proveedor) {
            $resumen[] = $this->sincronizar($proveedor);
        }

        return $resumen;
    }

    /**
     * Un proveedor concreto, por su clave.
     *
     * Lo usa el botón «Cargar modelos» de cada fila del panel. Existe porque
     * el caso normal es querer los modelos de UNO —el que se acaba de dar de
     * alta— y no pagar la espera de consultarlos todos.
     *
     * @return array{proveedor:string,ok:bool,nuevos:int,vistos:int,retirados:int,error:?string}|null
     */
    public function sincronizarPorClave(string $clave): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, clave, nombre, base_url, formato_api, activo
               FROM proveedores_ia WHERE clave = ?'
        );
        $stmt->execute([$clave]);
        $proveedor = $stmt->fetch();

        return $proveedor === false ? null : $this->sincronizar($proveedor);
    }

    /**
     * Los identificadores que el proveedor anuncia AHORA, para poblar un
     * desplegable. No toca la base.
     *
     * Es distinto de `sincronizar()` a propósito. Aquél es un acto de
     * inventario —da de alta filas, marca retiros, deja bitácora—; esto es
     * una consulta para pintar una lista mientras alguien elige. Mezclarlos
     * haría que abrir un desplegable escribiera en `modelos_ia`.
     *
     * Si el proveedor no contesta se cae a la lista de referencia, y el
     * campo `origen` dice cuál de las dos se está viendo: una lista escrita
     * a mano envejece, y quien elige tiene derecho a saber que la está
     * mirando.
     *
     * @param  array<string,mixed> $proveedor fila de `proveedores_ia`
     * @return array{modelos:list<string>,origen:string,nota:string}
     */
    public function listarEnVivo(array $proveedor): array
    {
        $clave = (string) $proveedor['clave'];

        try {
            $descubridor = $this->descubridores[(string) $proveedor['formato_api']]
                ?? throw new DescubrimientoFallido(
                    "No hay descubridor para el formato «{$proveedor['formato_api']}»"
                );

            $modelos = $descubridor->listar(
                (string) $proveedor['base_url'],
                $this->secreto($descubridor, $clave),
            );

            $identificadores = array_map(
                static fn (ModeloDescubierto $m): string => $m->identificador,
                $modelos,
            );

            sort($identificadores);

            if ($identificadores === []) {
                return [
                    'modelos' => CatalogoProveedores::modelosDeReferencia($clave),
                    'origen' => 'referencia',
                    'nota' => 'El proveedor contestó pero no anunció ningún modelo. '
                        . 'Suele ser una credencial sin acceso al catálogo. Lista de referencia.',
                ];
            }

            return [
                'modelos' => $identificadores,
                'origen' => 'api',
                'nota' => count($identificadores)
                    . ' modelos consultados en vivo desde la API del proveedor.',
            ];
        } catch (DescubrimientoFallido | CredencialNoEncontradaException $e) {
            $referencia = CatalogoProveedores::modelosDeReferencia($clave);

            return [
                'modelos' => $referencia,
                'origen' => 'referencia',
                'nota' => 'No se pudo consultar al proveedor (' . mb_substr($e->getMessage(), 0, 160)
                    . ($referencia === []
                        ? '). No hay lista de referencia para este proveedor.'
                        : '). Se muestra una lista de referencia, que puede estar desactualizada.'),
            ];
        }
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
     * que nadie lo haya decidido.
     *
     * Y **precisamente por eso hay que gritarlo**: no hay caída visible que
     * obligue a mirar. El registro en `error` lo ve quien lea los logs; el
     * aviso por el outbox lo ve Pedro en su teléfono, que es quien tiene que
     * elegir sustituto.
     *
     * Va por el outbox y no por una llamada directa: esto corre dentro de la
     * sincronización, y ADR-004 dice que ningún I/O externo cuelga de ahí. Si
     * Evolution estuviera lento, la sincronización entera se arrastraría con
     * él.
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

            $this->outbox?->encolar('alerta.modelo_retirado', [
                'modelo' => (string) $fila['identificador'],
                'proposito' => (string) $fila['proposito'],
            ]);
        }
    }

    /**
     * La credencial del proveedor, si la hay.
     *
     * Sin credencial se intenta igual, con `null`, y **decide el proveedor**.
     * Antes se abortaba aquí, y eso rompía dos casos reales:
     *
     *  · Catálogos públicos. OpenRouter lista sus modelos sin autenticar y
     *    Ollama tampoco pide nada. Negarse a preguntar dejaba «No hay
     *    credencial» en pantalla para un endpoint que habría contestado.
     *  · El orden natural de trabajo. Das de alta un proveedor y lo primero
     *    que quieres es ver qué ofrece, antes de ir a por una llave. Exigirla
     *    para mirar invierte ese orden sin ganar nada.
     *
     * Y cuando sí hace falta, el 401 del proveedor es mejor diagnóstico que
     * el nuestro: distingue «no mandaste llave» de «la llave no vale», que es
     * justo lo que uno necesita saber en ese momento.
     */
    private function secreto(Descubridor $descubridor, string $claveProveedor): ?string
    {
        $clave = $descubridor->claveCredencial();

        if ($clave === null) {
            return null;
        }

        try {
            return $this->credenciales->obtener($claveProveedor, $clave);
        } catch (CredencialNoEncontradaException) {
            return null;
        }
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
