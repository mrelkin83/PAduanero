<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\CredencialRepo;
use App\Servicios\CatalogoModelos;
use App\Servicios\Credenciales;
use App\Servicios\GateDorado;

/**
 * Proveedores y modelos de IA (docs/PANEL_ADMIN.md §2.5).
 *
 * Aquí es donde el descubrimiento automático se convierte en adopción, y
 * donde deja de ser automático. Lo que el cron trajo aparece como «nuevo,
 * sin revisar»; lo que el bot usa lo elige una persona.
 *
 * Cuatro puertas antes de que un modelo pueda ser primario:
 *
 *  1. Costo registrado y marcado como verificado. Sin esto el corte por
 *     `presupuesto_ia_mensual_usd` no corta: un modelo a coste cero nunca
 *     agota un presupuesto. Lo impone además un CHECK en base.
 *  2. Activo.
 *  3. No retirado. Esta la impone este controlador y no el CHECK, a
 *     propósito: el cron tiene que poder anotar el retiro de un modelo que
 *     está en uso, y no podría si la restricción se lo prohibiera. Ver
 *     `0006_catalogo_modelos.sql` §3.
 *  4. Conjunto dorado en verde contra ese modelo y con el prompt activo
 *     (`GateDorado`). Es lo que convierte la firma del abogado en un acto
 *     informado en vez de un trámite sobre un nombre de modelo.
 *
 * Y las dos mitades del ADR-007 dentro de este mismo archivo: todo lo
 * técnico es `ia.proveedores.escribir` (super_admin); ascender es
 * `ia.modelos.promover` (abogado).
 */
final class IaControlador extends ControladorBase
{
    /**
     * Clave de credencial que pide cada formato de API.
     *
     * Ollama en la propia máquina no pide nada, y por eso no aparece: un campo
     * de credencial para algo que no la usa invita a inventarse una.
     *
     * @var array<string,string>
     */
    private const CLAVE_POR_FORMATO = [
        'anthropic' => 'api_key',
        'openai_compatible' => 'api_key',
    ];

    public function __construct(
        private readonly BD $bd,
        private readonly CatalogoModelos $catalogo,
        private readonly GateDorado $gate,
        private readonly Credenciales $credenciales,
        private readonly CredencialRepo $credencialRepo,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }

    public function inicio(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.ver');

        $pdo = $this->bd->pdo();

        $proveedores = $pdo->query(
            'SELECT p.*,
                    (SELECT s.ejecutado_en FROM sincronizaciones_modelos s
                      WHERE s.proveedor_id = p.id ORDER BY s.id DESC LIMIT 1) AS ultima_sincro,
                    (SELECT s.ok FROM sincronizaciones_modelos s
                      WHERE s.proveedor_id = p.id ORDER BY s.id DESC LIMIT 1) AS ultima_ok,
                    (SELECT s.error FROM sincronizaciones_modelos s
                      WHERE s.proveedor_id = p.id ORDER BY s.id DESC LIMIT 1) AS ultimo_error
               FROM proveedores_ia p ORDER BY p.activo DESC, p.clave'
        )->fetchAll();

        // Orden de la lista: primero lo que pide decisión. Un modelo nuevo
        // sin revisar y un primario retirado son las dos cosas que alguien
        // tiene que mirar hoy; el resto es inventario.
        $modelos = $pdo->query(
            'SELECT m.*, p.clave AS proveedor_clave, p.nombre AS proveedor_nombre
               FROM modelos_ia m
               JOIN proveedores_ia p ON p.id = m.proveedor_id
              ORDER BY (m.es_primario = 1 AND m.retirado_en IS NOT NULL) DESC,
                       (m.origen = \'descubierto\' AND m.costos_verificados = 0
                        AND m.retirado_en IS NULL) DESC,
                       m.es_primario DESC,
                       p.clave, m.proposito, m.orden_fallback, m.identificador'
        )->fetchAll();

        // El motivo del gate se calcula aquí y se enseña en la ficha: un
        // botón deshabilitado sin explicación manda a alguien a leer código.
        $gates = [];

        foreach ($modelos as $m) {
            $gates[(string) $m['id']] = $this->gate->puedePromover($m);
        }

        // Máscaras, nunca el valor. `Credenciales::obtener()` no se expone por
        // HTTP jamás; esto va por `CredencialRepo`, cuyo SELECT ni siquiera
        // incluye la columna cifrada.
        $credenciales = [];

        foreach ($proveedores as $p) {
            $clave = self::CLAVE_POR_FORMATO[(string) $p['formato_api']] ?? null;

            if ($clave !== null) {
                $credenciales[(string) $p['clave']] = [
                    'clave' => $clave,
                    'filas' => $this->credencialRepo->resumen((string) $p['clave']),
                ];
            }
        }

        return $this->vista('panel/ia', [
            'ctx' => $ctx,
            'proveedores' => $proveedores,
            'modelos' => $modelos,
            'credenciales' => $credenciales,
            'gates' => $gates,
            'puedeEscribir' => $ctx->puede('ia.proveedores.escribir'),
            'puedePromover' => $ctx->puede('ia.modelos.promover'),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /**
     * Guarda la credencial de un proveedor de IA.
     *
     * **Aquí y no en `/panel/pagos`.** Ese módulo es de pasarelas de cobro y
     * solo conoce Wompi, Bold y MercadoPago; una key de LLM no es un pago, y
     * quien la busque no va a mirar ahí. El servicio de credenciales es el
     * mismo —mismo cifrado, misma auditoría, misma regla de que el valor no
     * sale nunca por HTTP— pero la pantalla está donde corresponde.
     *
     * Es `ia.proveedores.escribir`: super_admin. El abogado no ve credenciales
     * (ADR-007).
     */
    public function guardarCredencial(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $servicio = $ctx->campo('servicio');
        $valor = $ctx->campo('valor');

        $stmt = $this->bd->pdo()->prepare(
            'SELECT formato_api FROM proveedores_ia WHERE clave = ?'
        );
        $stmt->execute([$servicio]);
        $formato = $stmt->fetchColumn();

        if ($formato === false) {
            return $this->redirigirCon('/panel/ia', 'error', 'Ese proveedor no existe.');
        }

        $clave = self::CLAVE_POR_FORMATO[(string) $formato] ?? null;

        if ($clave === null) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'Ese proveedor no usa credencial: Ollama local no autentica.',
            );
        }

        if ($valor === '') {
            return $this->redirigirCon('/panel/ia', 'error', 'La credencial va vacía.');
        }

        $resultado = $this->credenciales->guardar(
            $servicio,
            $clave,
            $valor,
            'produccion',
            (string) $ctx->usuario?->id,
        );

        $this->auditoria->registrar(
            'credencial',
            null,
            'guardar',
            $ctx->actor(),
            ['servicio' => $servicio, 'clave' => $clave],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            'Credencial guardada (' . $resultado['mascara'] . '). '
            . 'Pulse «Sincronizar ahora» para comprobar que el proveedor la acepta.',
        );
    }

    /**
     * Dispara la sincronización a mano.
     *
     * Existe porque esperar al cron para comprobar que una credencial nueva
     * funciona es una forma lenta de depurar.
     */
    public function sincronizar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $resumen = $this->catalogo->sincronizarTodo();

        if ($resumen === []) {
            return $this->redirigirCon('/panel/ia', 'error', 'No hay proveedores activos.');
        }

        $nuevos = array_sum(array_column($resumen, 'nuevos'));
        $fallidos = array_filter($resumen, static fn (array $f): bool => !$f['ok']);

        $this->auditoria->registrar(
            'catalogo_modelos',
            null,
            'sincronizar',
            $ctx->actor(),
            ['proveedores' => count($resumen), 'nuevos' => $nuevos, 'fallidos' => count($fallidos)],
            $ctx->ip(),
        );

        if ($fallidos !== []) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'Fallaron ' . count($fallidos) . ' de ' . count($resumen) . ' proveedores: '
                . implode('; ', array_map(
                    static fn (array $f): string => $f['proveedor'] . ' — ' . $f['error'],
                    $fallidos,
                )),
            );
        }

        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            $nuevos === 0
                ? 'Catálogo al día. Ningún modelo nuevo.'
                : $nuevos . ' modelo(s) nuevo(s). Registre su costo antes de activarlos.',
        );
    }

    /**
     * Registra el costo de un modelo y lo marca verificado.
     *
     * El costo se teclea porque no hay de dónde leerlo: ningún proveedor lo
     * publica en su endpoint de modelos. Quien lo teclea queda registrado,
     * que es lo que permite responder «¿desde cuándo veníamos calculando el
     * presupuesto con el precio equivocado?» si la factura no cuadra.
     */
    public function guardarCosto(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $id = $ctx->campo('id');
        $entrada = $ctx->campo('costo_entrada_usd_1m');
        $salida = $ctx->campo('costo_salida_usd_1m');

        $numero = '/^\d{1,5}(\.\d{1,4})?$/';

        if (preg_match($numero, $entrada) !== 1 || preg_match($numero, $salida) !== 1) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'Los costos van en dólares por millón de tokens, con punto decimal. '
                . 'Por ejemplo: 5 y 25.',
            );
        }

        $modelo = $this->modelo($id);

        if ($modelo === null) {
            return $this->redirigirCon('/panel/ia', 'error', 'Ese modelo no existe.');
        }

        $this->bd->pdo()->prepare(
            'UPDATE modelos_ia
                SET costo_entrada_usd_1m = ?, costo_salida_usd_1m = ?,
                    costos_verificados = 1, costos_verificados_por = ?,
                    costos_verificados_en = NOW()
              WHERE id = ?'
        )->execute([$entrada, $salida, $ctx->usuario?->id, $id]);

        $this->auditoria->registrar(
            'modelo_ia',
            $id,
            'verificar_costo',
            $ctx->actor(),
            [
                'modelo' => $modelo['identificador'],
                'entrada_usd_1m' => $entrada,
                'salida_usd_1m' => $salida,
            ],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            'Costo registrado para ' . $modelo['identificador'] . '.',
        );
    }

    /** Activa o desactiva. Un modelo activo entra en la cascada de fallback. */
    public function alternarActivo(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $modelo = $this->modelo($ctx->campo('id'));

        if ($modelo === null) {
            return $this->redirigirCon('/panel/ia', 'error', 'Ese modelo no existe.');
        }

        $activar = (int) $modelo['activo'] === 0;

        if ($activar && (int) $modelo['costos_verificados'] === 0) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'Antes de activar ' . $modelo['identificador'] . ' hay que registrar su costo. '
                . 'Un modelo sin costo hace que el presupuesto mensual no corte nunca.',
            );
        }

        if ($activar && $modelo['retirado_en'] !== null) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'El proveedor retiró ' . $modelo['identificador'] . ' de su catálogo.',
            );
        }

        // Desactivar el primario lo dejaría sin sustituto y el CHECK lo
        // impide en base; se explica aquí para que el mensaje sea útil.
        if (!$activar && (int) $modelo['es_primario'] === 1) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'Es el modelo primario de «' . $modelo['proposito'] . '». '
                . 'Ascienda otro antes de desactivarlo.',
            );
        }

        $this->bd->pdo()
            ->prepare('UPDATE modelos_ia SET activo = ? WHERE id = ?')
            ->execute([$activar ? 1 : 0, $modelo['id']]);

        $this->auditoria->registrar(
            'modelo_ia',
            (string) $modelo['id'],
            $activar ? 'activar' : 'desactivar',
            $ctx->actor(),
            ['modelo' => $modelo['identificador']],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            $modelo['identificador'] . ($activar ? ' activado.' : ' desactivado.'),
        );
    }

    /**
     * Asciende un modelo a primario de su propósito.
     *
     * Este es el acto que el descubrimiento automático deliberadamente no
     * hace, y lo firma **el abogado**, no el super_admin: `ia.modelos.promover`
     * es la tercera asimetría del ADR-007, junto a `ia.prompts.aprobar`,
     * `kb.verificar` y `contenido.publicar`. Lo que se firma no es la calidad
     * técnica del modelo —el abogado no la evalúa, igual que no redacta el
     * prompt que aprueba— sino la responsabilidad profesional sobre lo que el
     * bot diga a partir de aquí.
     *
     * Y para que esa firma sea informada y no un trámite, antes pasa el
     * `GateDorado`: no se aprueba un nombre de modelo, se aprueba un modelo
     * que ya demostró no violar las reglas inviolables.
     */
    public function promover(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.modelos.promover');

        $modelo = $this->modelo($ctx->campo('id'));

        if ($modelo === null) {
            return $this->redirigirCon('/panel/ia', 'error', 'Ese modelo no existe.');
        }

        if ((int) $modelo['costos_verificados'] === 0) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'No se puede hacer primario un modelo sin costo verificado.',
            );
        }

        if ($modelo['retirado_en'] !== null) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'El proveedor retiró ' . $modelo['identificador'] . '. No puede ser primario.',
            );
        }

        $gate = $this->gate->puedePromover($modelo);

        if (!$gate['ok']) {
            return $this->redirigirCon('/panel/ia', 'error', $gate['motivo']);
        }

        $pdo = $this->bd->pdo();
        $anterior = null;

        // En transacción y en este orden: `ux_modelo_primario` solo admite
        // un primario por propósito, así que hay que bajar al que está antes
        // de subir al nuevo. Fuera de transacción, un fallo entre las dos
        // sentencias dejaría el propósito sin primario y el motor sin modelo.
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT id, identificador FROM modelos_ia
                  WHERE proposito = ? AND es_primario = 1 FOR UPDATE'
            );
            $stmt->execute([$modelo['proposito']]);
            $filaAnterior = $stmt->fetch();

            if ($filaAnterior !== false) {
                if ($filaAnterior['id'] === $modelo['id']) {
                    $pdo->rollBack();

                    return $this->redirigirCon(
                        '/panel/ia',
                        'ok',
                        $modelo['identificador'] . ' ya era el primario.',
                    );
                }

                $anterior = $filaAnterior['identificador'];

                // El anterior baja a primero de la cascada: sigue activo y
                // es el suplente natural si el nuevo falla.
                $pdo->prepare(
                    'UPDATE modelos_ia SET es_primario = 0, orden_fallback = 1 WHERE id = ?'
                )->execute([$filaAnterior['id']]);
            }

            $pdo->prepare(
                'UPDATE modelos_ia SET es_primario = 1, activo = 1, orden_fallback = 0 WHERE id = ?'
            )->execute([$modelo['id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        // La firma del ADR-016: quién cambió el modelo con el que habla el
        // bot, cuándo, y cuál era el anterior.
        $this->auditoria->registrar(
            'modelo_ia',
            (string) $modelo['id'],
            'promover',
            $ctx->actor(),
            [
                'proposito' => $modelo['proposito'],
                'nuevo' => $modelo['identificador'],
                'anterior' => $anterior,
            ],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            $modelo['identificador'] . ' es ahora el modelo primario de «' . $modelo['proposito']
            . '». Corra el conjunto dorado antes de sacarlo de modo sombra.',
        );
    }

    /** @return array<string,mixed>|null */
    private function modelo(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $stmt = $this->bd->pdo()->prepare('SELECT * FROM modelos_ia WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }
}
