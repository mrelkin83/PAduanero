<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\CredencialRepo;
use App\Servicios\CatalogoModelos;
use App\Servicios\CatalogoProveedores;
use App\Servicios\Credenciales;
use App\Servicios\GateDorado;
use App\Servicios\Llm;

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
        private readonly Llm $llm,
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
            $gates[(string) $m['id']] = $this->gate->estado($m);
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

        // Modelos de referencia por proveedor: solo para que una ficha sin
        // descubrir no aparezca vacía y sin explicación. NO se insertan.
        $referencia = [];

        foreach ($proveedores as $p) {
            $tiene = array_filter(
                $modelos,
                static fn (array $m): bool => $m['proveedor_clave'] === $p['clave'],
            );

            if ($tiene === []) {
                $referencia[(string) $p['clave']] = CatalogoProveedores::modelosDeReferencia(
                    (string) $p['clave'],
                );
            }
        }

        // Cuántos modelos tiene cada proveedor en el catálogo. Sin esto, la
        // fila de un proveedor recién sincronizado se ve igual que la de uno
        // que nunca trajo nada.
        $conteoModelos = [];

        foreach ($modelos as $m) {
            $k = (string) $m['proveedor_clave'];
            $conteoModelos[$k] = ($conteoModelos[$k] ?? 0) + 1;
        }

        // Lo que necesita la pantalla de «Proveedor de IA»: qué se puede
        // elegir, qué está elegido hoy, y qué costo tiene ya registrado cada
        // modelo para no volver a teclearlo.
        $elegibles = [];

        foreach (CatalogoProveedores::CONOCIDOS as $k => $d) {
            $elegibles[$k] = [
                'nombre' => $d['nombre'],
                'formato_api' => $d['formato_api'],
                'pais_servidor' => $d['pais_servidor'],
                'dadoDeAlta' => false,
                'clave_guardada' => '',
            ];
        }

        foreach ($proveedores as $p) {
            $k = (string) $p['clave'];
            $mascaras = $credenciales[$k]['filas'] ?? [];

            $elegibles[$k] = [
                'nombre' => (string) $p['nombre'],
                'formato_api' => (string) $p['formato_api'],
                'pais_servidor' => (string) ($p['pais_servidor'] ?? ''),
                'dadoDeAlta' => true,
                'clave_guardada' => (string) ($mascaras[0]['mascara'] ?? ''),
            ];
        }

        ksort($elegibles);

        $costosConocidos = [];
        $enUso = null;

        foreach ($modelos as $m) {
            $costosConocidos[$m['proveedor_clave'] . '|' . $m['identificador']] = [
                'entrada' => $m['costo_entrada_usd_1m'],
                'salida' => $m['costo_salida_usd_1m'],
                'verificado' => (int) $m['costos_verificados'] === 1,
            ];

            if ((int) $m['es_primario'] === 1 && $m['proposito'] === 'conversacion') {
                $enUso = $m;
            }
        }

        return $this->vista('panel/ia', [
            'ctx' => $ctx,
            'proveedores' => $proveedores,
            'modelos' => $modelos,
            'conteoModelos' => $conteoModelos,
            'elegibles' => $elegibles,
            'costosConocidos' => $costosConocidos,
            'enUso' => $enUso,
            'credenciales' => $credenciales,
            'referencia' => $referencia,
            'disponibles' => CatalogoProveedores::disponibles(array_column($proveedores, 'clave')),
            'gates' => $gates,
            'puedeEscribir' => $ctx->puede('ia.proveedores.escribir'),
            'puedePromover' => $ctx->puede('ia.modelos.promover'),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /**
     * Da de alta un proveedor.
     *
     * Dos caminos: elegir uno del catálogo conocido —que rellena URL, formato
     * y país— o escribirlo a mano. El segundo existe porque cualquier
     * endpoint compatible con OpenAI sirve, y no tiene sentido que añadir uno
     * exija tocar código.
     *
     * Nace **inactivo**, igual que un modelo descubierto. Encenderlo es un
     * acto aparte: dar de alta un proveedor no debería poner nada en la
     * cascada sin que alguien lo mire.
     */
    public function crearProveedor(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $clave = mb_strtolower(trim($ctx->campo('clave')));

        if (preg_match('/^[a-z0-9_-]{2,30}$/', $clave) !== 1) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'La clave del proveedor debe ser de 2 a 30 caracteres: letras, números, guion o guion bajo.',
            );
        }

        $conocido = CatalogoProveedores::CONOCIDOS[$clave] ?? null;

        $nombre = $ctx->campo('nombre') !== ''
            ? $ctx->campo('nombre')
            : ($conocido['nombre'] ?? $clave);

        $baseUrl = $ctx->campo('base_url') !== ''
            ? $ctx->campo('base_url')
            : ($conocido['base_url'] ?? '');

        $formato = $ctx->campo('formato_api') !== ''
            ? $ctx->campo('formato_api')
            : ($conocido['formato_api'] ?? 'openai_compatible');

        if (!in_array($formato, ['anthropic', 'openai_compatible', 'ollama'], true)) {
            return $this->redirigirCon('/panel/ia', 'error', 'Formato de API desconocido.');
        }

        if (preg_match('#^https?://#i', $baseUrl) !== 1) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'La URL base debe empezar por http:// o https://.',
            );
        }

        $pais = $ctx->campo('pais_servidor') !== ''
            ? $ctx->campo('pais_servidor')
            : ($conocido['pais_servidor'] ?? null);

        try {
            $this->bd->pdo()->prepare(
                'INSERT INTO proveedores_ia (clave, nombre, base_url, formato_api, pais_servidor, activo)
                 VALUES (?, ?, ?, ?, ?, 0)'
            )->execute([$clave, $nombre, rtrim($baseUrl, '/'), $formato, $pais]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->redirigirCon('/panel/ia', 'error', "Ya existe un proveedor «{$clave}».");
            }

            throw $e;
        }

        $this->auditoria->registrar(
            'proveedor_ia',
            null,
            'crear',
            $ctx->actor(),
            ['clave' => $clave, 'formato' => $formato, 'pais' => $pais],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            "Proveedor «{$nombre}» dado de alta, inactivo. Guarde su credencial y actívelo.",
        );
    }

    /**
     * Enciende o apaga un proveedor.
     *
     * Apagarlo saca de la cascada todos sus modelos: `Llm` exige
     * `p.activo = 1`. Es la forma de dejar de usar un proveedor sin borrar su
     * histórico de consumo.
     */
    public function alternarProveedor(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $clave = $ctx->campo('clave');

        $stmt = $this->bd->pdo()->prepare('SELECT id, nombre, activo FROM proveedores_ia WHERE clave = ?');
        $stmt->execute([$clave]);
        $proveedor = $stmt->fetch();

        if ($proveedor === false) {
            return $this->redirigirCon('/panel/ia', 'error', 'Ese proveedor no existe.');
        }

        $encender = (int) $proveedor['activo'] === 0;

        // Apagar el proveedor del primario dejaría al motor sin modelo y
        // escalando cada conversación a humano. Se dice antes, no después.
        if (!$encender) {
            $stmt = $this->bd->pdo()->prepare(
                'SELECT identificador FROM modelos_ia WHERE proveedor_id = ? AND es_primario = 1'
            );
            $stmt->execute([$proveedor['id']]);
            $primario = $stmt->fetchColumn();

            if ($primario !== false) {
                return $this->redirigirCon(
                    '/panel/ia',
                    'error',
                    "No se puede apagar: «{$primario}» es el modelo primario. "
                    . 'Ascienda otro antes.',
                );
            }
        }

        $this->bd->pdo()
            ->prepare('UPDATE proveedores_ia SET activo = ? WHERE id = ?')
            ->execute([$encender ? 1 : 0, $proveedor['id']]);

        $this->auditoria->registrar(
            'proveedor_ia',
            (string) $proveedor['id'],
            $encender ? 'activar' : 'desactivar',
            $ctx->actor(),
            ['clave' => $clave],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            $proveedor['nombre'] . ($encender ? ' activado.' : ' desactivado.'),
        );
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
            return $this->redirigirCon('/panel/ia', 'error', 'No hay proveedores registrados.');
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
     * Carga los modelos de UN proveedor, ahora mismo.
     *
     * Es el botón que faltaba. «Sincronizar ahora» consulta a todos y su
     * mensaje habla de totales, así que el resultado del proveedor que acabas
     * de dar de alta se pierde entre los demás. Aquí el mensaje es de ese
     * proveedor: cuántos modelos trajo, o exactamente por qué no trajo ninguno.
     *
     * Funciona con el proveedor apagado a propósito. Descubrir es una lectura;
     * exigir encenderlo antes obligaba a activar un proveedor cuya credencial
     * todavía no se sabe si sirve.
     */
    public function sincronizarProveedor(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $clave = $ctx->campo('clave');
        $resultado = $this->catalogo->sincronizarPorClave($clave);

        if ($resultado === null) {
            return $this->redirigirCon('/panel/ia', 'error', 'Ese proveedor no existe.');
        }

        $this->auditoria->registrar(
            'catalogo_modelos',
            null,
            'sincronizar',
            $ctx->actor(),
            ['proveedor' => $clave, 'ok' => $resultado['ok'], 'nuevos' => $resultado['nuevos']],
            $ctx->ip(),
        );

        if (!$resultado['ok']) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                $clave . ' — ' . $resultado['error'],
            );
        }

        $total = $resultado['nuevos'] + $resultado['vistos'];

        // El caso «0 y 0» es real y desconcertante: el proveedor contestó, pero
        // su lista vino vacía. Sin decirlo, parece que el botón no hizo nada.
        if ($total === 0) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                $clave . ' respondió, pero no anunció ningún modelo. '
                . 'Suele ser una credencial sin acceso al catálogo.',
            );
        }

        $texto = $clave . ': ' . $total . ' modelo(s) en su catálogo';

        if ($resultado['nuevos'] > 0) {
            $texto .= ', ' . $resultado['nuevos'] . ' nuevo(s). '
                . 'Registre su costo para poder activarlos.';
        } else {
            $texto .= '. Ninguno nuevo desde la última vez.';
        }

        if ($resultado['retirados'] > 0) {
            $texto .= ' ' . $resultado['retirados'] . ' dejó de anunciarlo(s) y quedan retirados.';
        }

        return $this->redirigirCon('/panel/ia', 'ok', $texto);
    }

    // ── La pantalla de «Proveedor de IA» ─────────────────────────────────
    //
    // Elegir proveedor, ver sus modelos en vivo, elegir uno, guardar la
    // llave. Tres endpoints: uno lista, otro guarda, otro prueba.

    /**
     * Los modelos que el proveedor anuncia ahora mismo, para el desplegable.
     *
     * No escribe nada: abrir una lista no puede dar de alta filas. Si el
     * proveedor no contesta cae a la lista de referencia y lo dice, porque
     * una lista escrita a mano envejece y quien elige tiene derecho a saber
     * cuál de las dos está mirando.
     */
    public function modelosDe(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $clave = (string) ($ctx->peticion->consulta['proveedor'] ?? '');
        $proveedor = $this->proveedorPorClave($clave);

        if ($proveedor === null) {
            // Todavía no está dado de alta: se puede listar igual si es uno
            // conocido, usando su URL del catálogo. Es lo que permite ver qué
            // ofrece DeepSeek antes de decidir si se quiere DeepSeek.
            $conocido = CatalogoProveedores::CONOCIDOS[$clave] ?? null;

            if ($conocido === null) {
                return Respuesta::json(['modelos' => [], 'origen' => 'ninguno', 'nota' =>
                    'Proveedor desconocido.'], 404);
            }

            $proveedor = [
                'id' => null,
                'clave' => $clave,
                'base_url' => $conocido['base_url'],
                'formato_api' => $conocido['formato_api'],
            ];
        }

        return Respuesta::json($this->catalogo->listarEnVivo($proveedor));
    }

    /**
     * Guarda proveedor, modelo y llave de una sola vez.
     *
     * Es el «Guardar configuración» de la pantalla. Hace, en orden y de forma
     * que cada paso tenga sentido aunque el siguiente falle:
     *
     *  1. Da de alta el proveedor si no existía, y lo activa.
     *  2. Guarda la API key cifrada, si se escribió una. Vacío conserva la
     *     que hubiera: es lo que permite cambiar de modelo sin volver a
     *     pegar la llave.
     *  3. Registra el modelo elegido si no estaba, con su costo.
     *  4. Lo activa y lo asciende a primario si el gate lo permite.
     *
     * El costo se pide aquí y no en otra pantalla porque es la única puerta
     * que no se puede saltar: sin él, el corte por `presupuesto_ia_mensual_usd`
     * no corta nunca, y un guardia que deja de guardar en silencio es peor
     * que no tenerlo.
     */
    public function configurar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $clave = mb_strtolower(trim($ctx->campo('proveedor')));
        $identificador = trim($ctx->campo('modelo'));
        $llave = $ctx->campo('api_key');
        $proposito = $ctx->campo('proposito', 'conversacion');

        if ($identificador === '') {
            return $this->redirigirCon('/panel/ia', 'error', 'Elija un modelo.');
        }

        $proveedor = $this->proveedorPorClave($clave);

        // 1. Alta del proveedor si hace falta.
        if ($proveedor === null) {
            $conocido = CatalogoProveedores::CONOCIDOS[$clave] ?? null;
            $baseUrl = $ctx->campo('base_url') !== ''
                ? rtrim($ctx->campo('base_url'), '/')
                : ($conocido['base_url'] ?? '');

            if (preg_match('#^https?://#i', $baseUrl) !== 1) {
                return $this->redirigirCon(
                    '/panel/ia',
                    'error',
                    'Falta la URL base del endpoint, o no empieza por http:// ni https://.',
                );
            }

            $this->bd->pdo()->prepare(
                'INSERT INTO proveedores_ia (clave, nombre, base_url, formato_api, pais_servidor, activo)
                 VALUES (?, ?, ?, ?, ?, 1)'
            )->execute([
                $clave,
                $conocido['nombre'] ?? $clave,
                $baseUrl,
                $conocido['formato_api'] ?? 'openai_compatible',
                $conocido['pais_servidor'] ?? $ctx->campo('pais_servidor'),
            ]);

            $proveedor = $this->proveedorPorClave($clave);
        }

        if ($proveedor === null) {
            return $this->redirigirCon('/panel/ia', 'error', 'No se pudo registrar el proveedor.');
        }

        // 2. La llave. Vacía conserva la guardada.
        if ($llave !== '') {
            $claveCredencial = self::CLAVE_POR_FORMATO[(string) $proveedor['formato_api']] ?? null;

            if ($claveCredencial !== null) {
                $this->credenciales->guardar(
                    $clave,
                    $claveCredencial,
                    $llave,
                    'produccion',
                    (string) $ctx->usuario?->id,
                );
            }
        }

        $this->bd->pdo()
            ->prepare('UPDATE proveedores_ia SET activo = 1 WHERE id = ?')
            ->execute([$proveedor['id']]);

        // 3. El modelo, con su costo.
        $entrada = $ctx->campo('costo_entrada_usd_1m');
        $salida = $ctx->campo('costo_salida_usd_1m');
        $numero = '/^\d{1,5}(\.\d{1,4})?$/';
        $conCosto = preg_match($numero, $entrada) === 1 && preg_match($numero, $salida) === 1;

        $stmt = $this->bd->pdo()->prepare(
            'SELECT * FROM modelos_ia WHERE proveedor_id = ? AND identificador = ? AND proposito = ?'
        );
        $stmt->execute([$proveedor['id'], $identificador, $proposito]);
        $modelo = $stmt->fetch();

        if ($modelo === false) {
            $this->bd->pdo()->prepare(
                'INSERT INTO modelos_ia
                    (proveedor_id, identificador, nombre_visible, proposito, origen,
                     descubierto_en, es_primario, orden_fallback, activo, costos_verificados)
                 VALUES (?, ?, ?, ?, \'manual\', NOW(), 0, 99, 0, 0)'
            )->execute([$proveedor['id'], $identificador, $identificador, $proposito]);

            $stmt->execute([$proveedor['id'], $identificador, $proposito]);
            $modelo = $stmt->fetch();
        }

        if ($conCosto) {
            $this->bd->pdo()->prepare(
                'UPDATE modelos_ia
                    SET costo_entrada_usd_1m = ?, costo_salida_usd_1m = ?,
                        costos_verificados = 1, costos_verificados_por = ?,
                        costos_verificados_en = NOW()
                  WHERE id = ?'
            )->execute([$entrada, $salida, $ctx->usuario?->id, $modelo['id']]);

            $modelo['costos_verificados'] = 1;
        }

        $this->auditoria->registrar(
            'modelo_ia',
            (string) $modelo['id'],
            'configurar',
            $ctx->actor(),
            ['proveedor' => $clave, 'modelo' => $identificador, 'proposito' => $proposito],
            $ctx->ip(),
        );

        if ((int) $modelo['costos_verificados'] === 0) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'Guardado el proveedor y la llave, pero ' . $identificador . ' queda inactivo: '
                . 'falta su costo por millón de tokens. Sin él el presupuesto mensual no corta nunca.',
            );
        }

        // 4. Activar y, si el gate lo permite, ascender.
        $this->bd->pdo()
            ->prepare('UPDATE modelos_ia SET activo = 1 WHERE id = ?')
            ->execute([$modelo['id']]);

        $modelo['activo'] = 1;

        return $this->ascenderTrasConfigurar($ctx, $modelo, $clave, $identificador);
    }

    /**
     * Prueba la conexión con lo que está guardado.
     *
     * Una llamada real y mínima al modelo elegido. Es la única forma de
     * distinguir «la llave está guardada» de «la llave sirve», que no es lo
     * mismo y se confunde justo cuando importa.
     */
    public function probar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $modelo = $this->modelo($ctx->campo('id'));

        if ($modelo === null) {
            return $this->redirigirCon('/panel/ia', 'error', 'Ese modelo no existe.');
        }

        $inicio = microtime(true);

        try {
            // Por `chatParaConjuntoDorado()` y no por `chat()`: esto corre
            // antes de que exista corrida dorada —es lo que se prueba— y al
            // otro lado no hay ningún cliente, hay un «ok».
            $respuesta = $this->llm->chatParaConjuntoDorado(
                'Responda únicamente con la palabra: ok',
                [['rol' => 'user', 'contenido' => 'ok']],
                (string) $modelo['id'],
                maxTokens: 16,
            );
        } catch (\Throwable $e) {
            return $this->redirigirCon(
                '/panel/ia',
                'error',
                'No respondió: ' . mb_substr($e->getMessage(), 0, 240),
            );
        }

        $segundos = round(microtime(true) - $inicio, 2);

        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            $modelo['identificador'] . ' respondió en ' . $segundos . ' s. '
            . 'Dijo: «' . mb_substr(trim($respuesta->texto), 0, 60) . '».',
        );
    }

    /**
     * Elegir el modelo lo pone en uso.
     *
     * Decisión del PO del 2026-08-01. Antes aquí había dos puertas: el
     * conjunto dorado en verde y la firma del abogado. La primera se retiró
     * (migración 0010); la segunda dejó de ser exclusiva del abogado, así que
     * quien configura el proveedor puede poner el modelo a hablar sin cambiar
     * de sesión.
     *
     * El estado del conjunto dorado se sigue calculando y enseñando: dejó de
     * impedir, no de informar.
     *
     * @param array<string,mixed> $modelo
     */
    private function ascenderTrasConfigurar(
        Contexto $ctx,
        array $modelo,
        string $clave,
        string $identificador,
    ): Respuesta {
        if (!$ctx->puede('ia.modelos.promover')) {
            return $this->redirigirCon(
                '/panel/ia',
                'ok',
                $identificador . ' quedó configurado y activo en ' . $clave . ', '
                . 'pero su usuario no puede ponerlo en uso.',
            );
        }

        $respuesta = $this->promoverModelo($ctx, $modelo);
        $dorado = $this->gate->estado($modelo);

        if ($dorado['ok']) {
            return $respuesta;
        }

        // Se pone en uso igual, pero no en silencio: el aviso es la única
        // señal de que este modelo va a hablar sin que nadie haya comprobado
        // que respeta las reglas inviolables.
        return $this->redirigirCon(
            '/panel/ia',
            'ok',
            $identificador . ' quedó en uso vía ' . $clave . '. Aviso: ' . $dorado['motivo'],
        );
    }

    /** @return array<string,mixed>|null */
    private function proveedorPorClave(string $clave): ?array
    {
        if ($clave === '') {
            return null;
        }

        $stmt = $this->bd->pdo()->prepare('SELECT * FROM proveedores_ia WHERE clave = ?');
        $stmt->execute([$clave]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
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
     * Sigue siendo el acto que el descubrimiento automático deliberadamente
     * no hace: un modelo aparece solo en el catálogo, pero empieza a hablar
     * porque alguien lo decidió, y en `auditoria` queda quién.
     *
     * Lo que ya no exige (PO, 2026-08-01, «quita el gate, elegir el modelo
     * debe ser suficiente»): corrida dorada en verde contra ese modelo. Y
     * `ia.modelos.promover` dejó de ser exclusivo del abogado.
     *
     * Lo que sigue exigiendo, y no por burocracia:
     *
     *  · Costo verificado. Sin él, `presupuesto_ia_mensual_usd` no corta
     *    nunca — un modelo a costo cero jamás agota un presupuesto.
     *  · No retirado. El proveedor dejó de anunciarlo; ponerlo a hablar es
     *    programar un fallo.
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

        return $this->promoverModelo($ctx, $modelo);
    }

    /**
     * El ascenso en sí, ya pasadas las comprobaciones.
     *
     * Separado porque lo usan dos caminos —el botón «Ascender» y el guardado
     * de la pantalla de configuración— y duplicar una transacción que deja el
     * propósito sin primario a mitad es la clase de copia que se desincroniza.
     *
     * @param array<string,mixed> $modelo
     */
    private function promoverModelo(Contexto $ctx, array $modelo): Respuesta
    {
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
