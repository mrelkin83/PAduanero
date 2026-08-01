<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Proveedores y modelos de IA.
 *
 * La pantalla se reordenó el 2026-08-01 porque era ilegible: pintaba una
 * tarjeta por cada modelo, y con un proveedor como OpenRouter —que anuncia
 * más de trescientos— la página pesaba más de un megabyte y lo único que
 * alguien viene a hacer aquí, elegir con qué modelo habla el bot, quedaba
 * enterrado.
 *
 * Ahora hay tres bloques y un cajón:
 *
 *   1. Elegir proveedor y modelo. Es a lo que se viene.
 *   2. Qué está en uso ahora.
 *   3. Proveedores, en tabla compacta.
 *   · El catálogo completo, plegado, en tabla y con buscador.
 *
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $proveedores
 * @var list<array<string,mixed>> $modelos
 * @var array<string,int> $conteoModelos
 * @var array<string,array<string,mixed>> $elegibles
 * @var array<string,array{entrada:?string,salida:?string,verificado:bool}> $costosConocidos
 * @var array<string,mixed>|null $enUso
 * @var array<string,array{clave:string,filas:list<array<string,mixed>>}> $credenciales
 * @var array<string,list<string>> $referencia
 * @var array<string,array<string,mixed>> $disponibles
 * @var array<string,array{ok:bool,motivo:string}> $gates
 * @var bool $puedeEscribir
 * @var bool $puedePromover
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Modelos de IA';

$contenido = static function () use (
    $e,
    $ctx,
    $proveedores,
    $modelos,
    $conteoModelos,
    $elegibles,
    $enUso,
    $referencia,
    $gates,
    $puedeEscribir,
    $puedePromover,
): void {
    // Lo que pide decisión hoy, separado del inventario. Un modelo primario
    // retirado por su proveedor es lo único que puede romper el bot sin dar
    // ningún síntoma: la cascada lo cubre y nadie se entera.
    $primariosRetirados = array_filter(
        $modelos,
        static fn (array $m): bool => (int) $m['es_primario'] === 1 && $m['retirado_en'] !== null,
    );

    // «Configurado» es lo que alguien tocó: activo, primario o con costo
    // verificado. El resto es catálogo, y va al cajón.
    $configurados = array_filter(
        $modelos,
        static fn (array $m): bool => (int) $m['activo'] === 1
            || (int) $m['es_primario'] === 1
            || (int) $m['costos_verificados'] === 1,
    );

    $catalogo = array_filter(
        $modelos,
        static fn (array $m): bool => !in_array($m, $configurados, true),
    );
    ?>

    <?php if ($primariosRetirados !== []): ?>
    <section class="aviso aviso-error">
        <p class="font-semibold">Un modelo primario fue retirado por su proveedor.</p>
        <p class="mt-1 text-sm">
            La cascada lo está cubriendo, así que el bot sigue respondiendo, pero está
            sirviendo desde el suplente sin que nadie lo haya decidido. Elija sustituto.
        </p>
        <ul class="mt-2 font-mono text-sm">
            <?php foreach ($primariosRetirados as $m): ?>
                <li><?= $e((string) $m['identificador']) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php /* ── 1. A lo que se viene ──────────────────────────────────── */ ?>
    <?php if ($puedeEscribir): ?>
    <section class="tarjeta p-4">
        <h2 class="text-lg font-semibold">🤖 Proveedor de IA</h2>
        <p class="mt-1 text-sm text-acero">
            El proveedor y modelo elegidos se usan en todas las generaciones. La API key
            se guarda cifrada en la base de datos y nunca vuelve al navegador.
        </p>

        <form method="post" action="/panel/ia/configurar" class="mt-4 space-y-4">
            <?= $ctx->csrf->campoOculto() ?>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="rotulo" for="campo-proveedor">Proveedor</label>
                    <select id="campo-proveedor" name="proveedor" class="campo">
                        <?php foreach ($elegibles as $k => $d): ?>
                        <option value="<?= $e((string) $k) ?>"
                            <?= $enUso !== null && $enUso['proveedor_clave'] === $k ? 'selected' : '' ?>>
                            <?= $e((string) $d['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p id="pais-proveedor" class="mt-1 text-xs text-acero"></p>
                </div>

                <div>
                    <label class="rotulo" for="campo-modelo">Modelo</label>
                    <select id="campo-modelo" name="modelo" class="campo">
                        <option value="">Cargando modelos…</option>
                    </select>
                    <input type="text" id="campo-modelo-otro" class="campo mt-2 hidden"
                           placeholder="Escriba el identificador exacto del modelo">
                    <p id="modelo-origen" class="mt-1 text-xs text-acero"></p>
                </div>
            </div>

            <div id="grupo-url" class="hidden">
                <label class="rotulo" for="campo-url">
                    URL base del endpoint (compatible con OpenAI)
                </label>
                <input type="text" id="campo-url" name="base_url" class="campo"
                       placeholder="https://mi-servidor/v1">
            </div>

            <div>
                <label class="rotulo" for="campo-clave">API key del proveedor</label>
                <input type="password" id="campo-clave" name="api_key" class="campo" autocomplete="off">
                <p id="clave-estado" class="mt-1 text-xs text-acero"></p>
            </div>

            <?php /* El costo no lo publica ningún proveedor en su endpoint de
                    modelos, así que se teclea. No es burocracia: es lo único
                    que hace que el corte por presupuesto mensual corte. Un
                    modelo a costo cero nunca agota un presupuesto, y un
                    guardia que deja de guardar en silencio es peor que no
                    tenerlo. Quien lo teclea queda en `auditoria`. */ ?>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="rotulo" for="campo-costo-entrada">
                        Costo de entrada · USD / millón de tokens
                    </label>
                    <input type="text" id="campo-costo-entrada" name="costo_entrada_usd_1m"
                           class="campo" inputmode="decimal" placeholder="5">
                </div>
                <div>
                    <label class="rotulo" for="campo-costo-salida">
                        Costo de salida · USD / millón de tokens
                    </label>
                    <input type="text" id="campo-costo-salida" name="costo_salida_usd_1m"
                           class="campo" inputmode="decimal" placeholder="25">
                </div>
            </div>
            <p id="costo-nota" class="text-xs text-acero"></p>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="boton">Guardar configuración</button>
                <?php if ($enUso !== null): ?>
                <button type="submit" form="probar-conexion" class="boton-secundario">
                    Probar conexión
                </button>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($enUso !== null): ?>
        <form method="post" action="/panel/ia/probar" id="probar-conexion" class="hidden">
            <?= $ctx->csrf->campoOculto() ?>
            <input type="hidden" name="id" value="<?= $e((string) $enUso['id']) ?>">
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php /* ── 2. Qué está en uso ────────────────────────────────────── */ ?>
    <section class="mt-8">
        <h2 class="rotulo">En uso</h2>

        <?php if ($enUso === null): ?>
            <p class="aviso aviso-error mt-3 text-sm">
                Ningún modelo está en uso. El motor no puede responder y escalará
                cada conversación a humano.
            </p>
        <?php else: ?>
            <?php $dorado = $gates[(string) $enUso['id']] ?? ['ok' => false, 'motivo' => '']; ?>
            <p class="mt-3">
                <span class="font-mono font-medium"><?= $e((string) $enUso['identificador']) ?></span>
                <span class="text-sm text-acero">
                    vía <?= $e((string) $enUso['proveedor_nombre']) ?>
                    · <?= $e((string) ($enUso['costo_entrada_usd_1m'] ?? '?')) ?> /
                    <?= $e((string) ($enUso['costo_salida_usd_1m'] ?? '?')) ?> USD por millón
                </span>
            </p>
            <?php /* El conjunto dorado dejó de bloquear el 2026-08-01, pero
                    su estado sigue siendo el único dato objetivo sobre si
                    este modelo respeta las reglas inviolables. Callarlo
                    sería peor que no tenerlo. */ ?>
            <p class="mt-1 text-xs <?= $dorado['ok'] ? 'text-acero' : 'text-sello' ?>">
                <?= $e((string) $dorado['motivo']) ?>
            </p>
        <?php endif; ?>

        <?php
        $suplentes = array_filter(
            $configurados,
            static fn (array $m): bool => (int) $m['activo'] === 1
                && (int) $m['es_primario'] === 0
                && $m['proposito'] === 'conversacion',
        );
        ?>
        <?php if ($suplentes !== []): ?>
        <p class="mt-2 text-xs text-acero">
            Suplentes, por orden: <?= $e(implode(' · ', array_map(
                static fn (array $m): string => (string) $m['identificador'],
                $suplentes,
            ))) ?>
        </p>
        <?php endif; ?>
    </section>

    <?php /* ── 3. Proveedores ────────────────────────────────────────── */ ?>
    <section class="mt-8">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="rotulo">Proveedores</h2>

            <?php if ($puedeEscribir): ?>
            <form method="post" action="/panel/ia/sincronizar">
                <?= $ctx->csrf->campoOculto() ?>
                <button type="submit" class="boton-secundario">Sincronizar todos</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="mt-3 overflow-x-auto">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Proveedor</th>
                    <th>País del servidor</th>
                    <th>Modelos</th>
                    <th>Última sincronización</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($proveedores as $p): ?>
                <tr class="<?= (int) $p['activo'] === 0 ? 'opacity-50' : '' ?>">
                    <td>
                        <span class="font-medium"><?= $e((string) $p['nombre']) ?></span>
                        <span class="ml-1 font-mono text-xs text-acero"><?= $e((string) $p['clave']) ?></span>
                    </td>
                    <td class="text-sm">
                        <?php /* Dato de cumplimiento: dónde se procesa el contenido
                                de los casos. Ver CLAUDE.md §9. */ ?>
                        <?= $e((string) ($p['pais_servidor'] ?? '—')) ?>
                    </td>
                    <td class="text-sm">
                        <?php $cuantos = $conteoModelos[(string) $p['clave']] ?? 0; ?>
                        <?= $cuantos === 0
                            ? '<span class="text-acero">—</span>'
                            : '<span class="font-medium">' . $cuantos . '</span>' ?>
                    </td>
                    <td class="text-sm">
                        <?php if ($p['ultima_sincro'] === null): ?>
                            <span class="text-acero">nunca</span>
                        <?php elseif ((int) $p['ultima_ok'] === 1): ?>
                            <?= $e((string) $p['ultima_sincro']) ?>
                        <?php else: ?>
                            <span class="text-sello" title="<?= $e((string) $p['ultimo_error']) ?>">
                                falló · <?= $e((string) $p['ultima_sincro']) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($puedeEscribir): ?>
                        <div class="flex flex-wrap gap-2">
                            <?php /* Funciona con el proveedor apagado: descubrir es
                                    una lectura. Exigir activarlo antes obligaba a
                                    encender un proveedor cuya credencial todavía no
                                    se sabe si sirve. */ ?>
                            <form method="post" action="/panel/ia/proveedor/sincronizar">
                                <?= $ctx->csrf->campoOculto() ?>
                                <input type="hidden" name="clave" value="<?= $e((string) $p['clave']) ?>">
                                <button type="submit" class="boton-secundario">Cargar modelos</button>
                            </form>
                            <form method="post" action="/panel/ia/proveedor/activo">
                                <?= $ctx->csrf->campoOculto() ?>
                                <input type="hidden" name="clave" value="<?= $e((string) $p['clave']) ?>">
                                <button type="submit" class="boton-secundario">
                                    <?= (int) $p['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php
                /* Modelos de referencia: solo cuando el proveedor todavía no ha
                   descubierto ninguno. NO son filas de `modelos_ia`: un modelo
                   entra al catálogo cuando el proveedor lo anuncia (ADR-016),
                   no porque figure en una lista escrita a mano. */
                $refs = $referencia[(string) $p['clave']] ?? [];
                ?>
                <?php if ($refs !== []): ?>
                <tr>
                    <td colspan="5" class="text-xs text-acero">
                        Sin descubrir. Pulse «Cargar modelos». Suele ofrecer:
                        <span class="font-mono"><?= $e(implode(' · ', $refs)) ?></span>
                        — lista de referencia, no del catálogo.
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <?php /* ── Modelos configurados ──────────────────────────────────── */ ?>
    <?php if ($configurados !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Modelos configurados</h2>
        <div class="mt-3 overflow-x-auto">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Modelo</th>
                    <th>Proveedor</th>
                    <th>USD / millón</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($configurados as $m):
                $primario = (int) $m['es_primario'] === 1;
                $retirado = $m['retirado_en'] !== null;
                ?>
                <tr class="<?= $retirado ? 'opacity-60' : '' ?>">
                    <td class="font-mono text-sm"><?= $e((string) $m['identificador']) ?></td>
                    <td class="text-sm"><?= $e((string) $m['proveedor_nombre']) ?></td>
                    <td class="font-mono text-sm">
                        <?= $e((string) ($m['costo_entrada_usd_1m'] ?? '—')) ?> /
                        <?= $e((string) ($m['costo_salida_usd_1m'] ?? '—')) ?>
                    </td>
                    <td class="text-sm">
                        <?php if ($retirado): ?>
                            <span class="etiqueta etiqueta-error">retirado</span>
                        <?php elseif ($primario): ?>
                            <span class="etiqueta etiqueta-ok">en uso</span>
                        <?php elseif ((int) $m['activo'] === 1): ?>
                            <span class="etiqueta">suplente</span>
                        <?php else: ?>
                            <span class="etiqueta">inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($puedeEscribir && !$primario && !$retirado): ?>
                        <div class="flex flex-wrap gap-2">
                            <form method="post" action="/panel/ia/activo">
                                <?= $ctx->csrf->campoOculto() ?>
                                <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                                <button type="submit" class="boton-secundario">
                                    <?= (int) $m['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                            <?php if ($puedePromover && (int) $m['costos_verificados'] === 1): ?>
                            <form method="post" action="/panel/ia/promover">
                                <?= $ctx->csrf->campoOculto() ?>
                                <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                                <button type="submit" class="boton-secundario">Poner en uso</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <?php /* ── El cajón: el catálogo entero ──────────────────────────── */ ?>
    <?php if ($catalogo !== []): ?>
    <section class="mt-8">
        <details>
            <summary class="cursor-pointer text-sm font-medium">
                Catálogo descubierto — <?= count($catalogo) ?> modelo(s) que ningún
                proveedor ha configurado todavía
            </summary>

            <p class="mt-2 text-sm text-acero">
                Lo que cada proveedor anuncia hoy. Entran solos e inactivos: para usar uno,
                elíjalo arriba. Un catálogo compatible con OpenAI devuelve además todo lo
                que el proveedor tenga publicado —transcripción, imágenes, moderación,
                instantáneas viejas—, así que la lista es larga y mayormente irrelevante.
            </p>

            <input type="search" id="filtro-catalogo" class="campo mt-3"
                   placeholder="Filtrar por identificador o proveedor…" autocomplete="off">

            <div class="mt-3 overflow-x-auto" style="max-height:24rem;overflow-y:auto">
            <table class="tabla">
                <thead>
                    <tr><th>Modelo</th><th>Proveedor</th><th>Contexto</th></tr>
                </thead>
                <tbody id="cuerpo-catalogo">
                <?php foreach ($catalogo as $m): ?>
                    <tr data-busca="<?= $e(mb_strtolower(
                        $m['identificador'] . ' ' . $m['proveedor_clave']
                    )) ?>" class="<?= $m['retirado_en'] !== null ? 'opacity-50' : '' ?>">
                        <td class="font-mono text-xs"><?= $e((string) $m['identificador']) ?></td>
                        <td class="text-xs"><?= $e((string) $m['proveedor_clave']) ?></td>
                        <td class="text-xs text-acero">
                            <?= $m['ventana_contexto'] !== null
                                ? number_format((int) $m['ventana_contexto'], 0, ',', '.')
                                : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
    </section>
    <?php endif; ?>

    <?php /* ── Dar de alta un proveedor a mano ───────────────────────── */ ?>
    <?php if ($puedeEscribir): ?>
    <section class="mt-8">
        <details>
            <summary class="cursor-pointer text-sm font-medium">
                Añadir un proveedor que no está en la lista
            </summary>

            <p class="mt-2 text-sm text-acero">
                Cualquier endpoint compatible con OpenAI. Nace inactivo: darlo de alta
                no lo mete en la cascada.
            </p>

            <form method="post" action="/panel/ia/proveedor" class="mt-3 grid gap-3 sm:grid-cols-2">
                <?= $ctx->csrf->campoOculto() ?>
                <div>
                    <label class="rotulo">Clave</label>
                    <input name="clave" class="campo font-mono" placeholder="mi-proveedor">
                </div>
                <div>
                    <label class="rotulo">Nombre visible</label>
                    <input name="nombre" class="campo" placeholder="Mi proveedor">
                </div>
                <div class="sm:col-span-2">
                    <label class="rotulo">URL base</label>
                    <input name="base_url" class="campo font-mono"
                           placeholder="https://api.ejemplo.com/v1">
                </div>
                <div>
                    <label class="rotulo">Formato de API</label>
                    <select name="formato_api" class="campo">
                        <option value="openai_compatible">Compatible con OpenAI</option>
                        <option value="anthropic">Anthropic</option>
                        <option value="ollama">Ollama</option>
                    </select>
                </div>
                <div>
                    <label class="rotulo">País del servidor</label>
                    <input name="pais_servidor" class="campo" placeholder="Estados Unidos">
                    <p class="mt-1 text-xs text-acero">
                        Si el contenido de los casos sale de Colombia, el aviso de habeas
                        data debe declarar transferencia internacional.
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="boton">Dar de alta</button>
                </div>
            </form>
        </details>
    </section>
    <?php endif; ?>

    <?php
};

/**
 * Script de la pantalla.
 *
 * Dos cosas que no se pueden hacer sin él: pedir los modelos del proveedor
 * elegido mientras alguien está eligiendo, y filtrar un catálogo de
 * trescientas filas sin recargar. Todo lo demás son formularios normales que
 * funcionan igual con JS apagado.
 */
$scripts = static function () use ($elegibles, $costosConocidos, $enUso): void {
    $aJson = static fn (mixed $v): string => (string) json_encode(
        $v,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT,
    );
    ?>
'use strict';

const PROVEEDORES = <?= $aJson($elegibles) ?>;
const COSTOS = <?= $aJson($costosConocidos) ?>;
const EN_USO = <?= $aJson($enUso === null ? null : [
    'proveedor' => $enUso['proveedor_clave'],
    'modelo' => $enUso['identificador'],
]) ?>;

const $ = (id) => document.getElementById(id);

const selProveedor = $('campo-proveedor');
const selModelo = $('campo-modelo');
const otroModelo = $('campo-modelo-otro');

// La pantalla solo existe entera para quien puede escribir.
if (selProveedor) {
    selProveedor.addEventListener('change', alCambiarProveedor);
    selModelo.addEventListener('change', alCambiarModelo);
    alCambiarProveedor();
}

function alCambiarProveedor() {
    const clave = selProveedor.value;
    const info = PROVEEDORES[clave] || {};

    // La URL base solo se pide cuando no la sabemos: un proveedor conocido ya
    // la trae, y ofrecer el campo invita a escribir una que no es.
    $('grupo-url').classList.toggle('hidden', Boolean(info.formato_api));

    $('pais-proveedor').textContent = info.pais_servidor
        ? 'Procesa en: ' + info.pais_servidor
        : '';

    const campoClave = $('campo-clave');
    campoClave.value = '';

    // Nunca la llave: solo su máscara, y el aviso de que dejarlo vacío la
    // conserva. Sin decirlo, cambiar de modelo obligaría a volver a pegarla y
    // quien no la tenga a mano la borraría sin querer.
    if (info.clave_guardada) {
        campoClave.placeholder = 'Guardada: ' + info.clave_guardada + ' — escriba solo para reemplazarla';
        $('clave-estado').textContent = 'Hay una llave guardada y cifrada. Deje el campo vacío para conservarla.';
    } else {
        campoClave.placeholder = 'Pegue aquí la API key';
        $('clave-estado').textContent = 'No hay llave guardada para este proveedor.';
    }

    cargarModelos(clave);
}

async function cargarModelos(clave) {
    selModelo.innerHTML = '<option value="">Cargando modelos…</option>';
    $('modelo-origen').textContent = '';

    let datos = { modelos: [], origen: 'ninguno', nota: '' };

    try {
        const r = await fetch('/panel/ia/modelos?proveedor=' + encodeURIComponent(clave), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });
        datos = await r.json();
    } catch (e) {
        datos.nota = 'No se pudo consultar al proveedor: ' + e.message;
    }

    const guardado = EN_USO && EN_USO.proveedor === clave ? EN_USO.modelo : '';
    const lista = datos.modelos || [];

    if (guardado && !lista.includes(guardado)) lista.unshift(guardado);

    selModelo.innerHTML =
        '<option value="">— elija un modelo —</option>' +
        lista.map((m) => '<option value="' + m + '">' + m + '</option>').join('') +
        '<option value="__otro__">✎ Otro (escribir el identificador)…</option>';

    selModelo.value = guardado && lista.includes(guardado) ? guardado : '';

    // Distinguir «en vivo» de «lista de referencia» importa: la segunda está
    // escrita a mano y envejece. Quien elige tiene derecho a saber cuál mira.
    const nota = $('modelo-origen');
    nota.textContent = (datos.origen === 'api' ? '✔ ' : '⚠ ') + (datos.nota || '');
    nota.className = 'mt-1 text-xs ' + (datos.origen === 'api' ? 'text-acero' : 'text-sello');

    alCambiarModelo();
}

function alCambiarModelo() {
    const otro = selModelo.value === '__otro__';
    otroModelo.classList.toggle('hidden', !otro);
    // El `name` se mueve para que solo uno de los dos campos viaje: si los dos
    // se llamaran `modelo`, ganaría el último y sería el equivocado.
    selModelo.name = otro ? '' : 'modelo';
    otroModelo.name = otro ? 'modelo' : '';

    if (otro) {
        otroModelo.focus();
        $('costo-nota').textContent = 'Modelo escrito a mano: teclee también su costo.';
        return;
    }

    const conocido = COSTOS[selProveedor.value + '|' + selModelo.value];
    const entrada = $('campo-costo-entrada');
    const salida = $('campo-costo-salida');

    if (conocido && conocido.entrada !== null) {
        entrada.value = String(parseFloat(conocido.entrada));
        salida.value = String(parseFloat(conocido.salida));
        $('costo-nota').textContent = conocido.verificado
            ? 'Costo ya verificado. Guardar lo vuelve a confirmar a su nombre.'
            : 'Costo precargado sin verificar: compruébelo en la página de precios del proveedor.';
        return;
    }

    entrada.value = '';
    salida.value = '';
    $('costo-nota').textContent = 'Ningún proveedor publica precios en su endpoint de modelos. '
        + 'Sin costo, el corte por presupuesto mensual no corta y el modelo queda inactivo.';
}

// Filtro del catálogo. Sin esto, trescientas filas son un muro.
const filtro = $('filtro-catalogo');

if (filtro) {
    filtro.addEventListener('input', () => {
        const q = filtro.value.trim().toLowerCase();

        for (const fila of $('cuerpo-catalogo').rows) {
            fila.hidden = q !== '' && !fila.dataset.busca.includes(q);
        }
    });
}
    <?php
};

require __DIR__ . '/_disposicion.php';
