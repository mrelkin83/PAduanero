<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
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
    $credenciales,
    $referencia,
    $disponibles,
    $gates,
    $puedeEscribir,
    $puedePromover,
): void {
    $nuevos = array_filter(
        $modelos,
        static fn (array $m): bool => $m['origen'] === 'descubierto'
            && (int) $m['costos_verificados'] === 0
            && $m['retirado_en'] === null,
    );

    $primariosRetirados = array_filter(
        $modelos,
        static fn (array $m): bool => (int) $m['es_primario'] === 1 && $m['retirado_en'] !== null,
    );
    ?>

    <?php /* ── Proveedor de IA ────────────────────────────────────────────
            Elegir proveedor, ver sus modelos EN VIVO desde su propia API,
            elegir uno y guardar la llave. Todo en una pantalla, que es como
            se configura esto en cualquier sitio y no había razón para que
            aquí fuera distinto. */ ?>
    <?php if ($puedeEscribir): ?>
    <section class="tarjeta p-5">
        <h2 class="text-lg font-semibold">🤖 Proveedor de IA</h2>
        <p class="mt-1 text-sm text-acero">
            El proveedor y modelo elegidos se usan en todas las generaciones. La API key
            se guarda cifrada en la base de datos y nunca vuelve al navegador.
        </p>

        <?php if ($enUso !== null): ?>
        <p class="mt-3 text-sm">
            En uso ahora:
            <span class="font-mono font-medium"><?= $e((string) $enUso['identificador']) ?></span>
            <span class="text-acero">vía <?= $e((string) $enUso['proveedor_nombre']) ?></span>
        </p>
        <?php else: ?>
        <p class="aviso aviso-error mt-3 text-sm">
            Ningún modelo está en uso todavía. El motor no puede responder.
        </p>
        <?php endif; ?>

        <form method="post" action="/panel/ia/configurar" class="mt-4 space-y-4">
            <?= $ctx->csrf->campoOculto() ?>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="etiqueta" for="campo-proveedor">Proveedor</label>
                    <select id="campo-proveedor" name="proveedor" class="campo">
                        <?php foreach ($elegibles as $k => $d): ?>
                        <option value="<?= $e((string) $k) ?>"
                            <?= $enUso !== null && $enUso['proveedor_clave'] === $k ? 'selected' : '' ?>>
                            <?= $e((string) $d['nombre']) ?><?= $d['dadoDeAlta'] ? '' : ' — sin dar de alta' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p id="pais-proveedor" class="mt-1 text-xs text-acero"></p>
                </div>

                <div>
                    <label class="etiqueta" for="campo-modelo">Modelo</label>
                    <select id="campo-modelo" name="modelo" class="campo">
                        <option value="">Cargando modelos…</option>
                    </select>
                    <input type="text" id="campo-modelo-otro" class="campo mt-2 hidden"
                           placeholder="Escriba el identificador exacto del modelo">
                    <p id="modelo-origen" class="mt-1 text-xs text-acero"></p>
                </div>
            </div>

            <div id="grupo-url" class="hidden">
                <label class="etiqueta" for="campo-url">URL base del endpoint (compatible con OpenAI)</label>
                <input type="text" id="campo-url" name="base_url" class="campo"
                       placeholder="https://mi-servidor/v1">
            </div>

            <div>
                <label class="etiqueta" for="campo-clave">API key del proveedor</label>
                <input type="password" id="campo-clave" name="api_key" class="campo" autocomplete="off">
                <p id="clave-estado" class="mt-1 text-xs text-acero"></p>
            </div>

            <?php /* El costo no lo publica ningún proveedor en su endpoint de
                    modelos, así que se teclea. No es burocracia: es lo único
                    que hace que el corte por presupuesto mensual corte. Un
                    modelo a costo cero nunca agota un presupuesto, y un
                    guardia que deja de guardar en silencio es peor que no
                    tenerlo. Quien lo teclea queda en `auditoria`. */ ?>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="etiqueta" for="campo-costo-entrada">
                        Costo de entrada · USD por millón de tokens
                    </label>
                    <input type="text" id="campo-costo-entrada" name="costo_entrada_usd_1m"
                           class="campo" inputmode="decimal" placeholder="5">
                </div>
                <div>
                    <label class="etiqueta" for="campo-costo-salida">
                        Costo de salida · USD por millón de tokens
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

    <section class="mt-8">
        <p class="text-sm text-acero">
            El catálogo se sincroniza solo con lo que cada proveedor anuncia: si sale un
            modelo nuevo, aparece aquí al día siguiente sin tocar código.
            <strong>Lo que no es automático es empezar a usarlo.</strong> Un modelo nuevo
            entra inactivo y sin costo. Ascenderlo a primario lo firma el abogado —igual
            que aprobar un prompt, y por la misma razón: cambia lo que el bot dice— y
            solo después de que el conjunto dorado haya corrido en verde contra ese
            modelo con el prompt que está activo hoy.
        </p>
    </section>

    <?php if ($primariosRetirados !== []): ?>
    <section class="aviso aviso-error mt-6">
        <p class="font-semibold">Un modelo primario fue retirado por su proveedor.</p>
        <p class="mt-1 text-sm">
            La cascada de fallback lo está cubriendo, así que el bot sigue respondiendo,
            pero está sirviendo desde el suplente sin que nadie lo haya decidido.
            Elija sustituto.
        </p>
        <ul class="mt-2 font-mono text-sm">
            <?php foreach ($primariosRetirados as $m): ?>
                <li><?= $e((string) $m['identificador']) ?> · <?= $e((string) $m['proposito']) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($nuevos !== []): ?>
    <section class="aviso aviso-ok mt-6">
        <p class="font-semibold">
            <?= count($nuevos) ?> modelo(s) nuevo(s) sin revisar.
        </p>
        <p class="mt-1 text-sm">
            Ningún proveedor publica sus precios en el endpoint de modelos, así que el
            costo hay que registrarlo a mano. Sin él, el corte por presupuesto mensual
            no corta: un modelo a coste cero nunca agota un presupuesto.
        </p>
    </section>
    <?php endif; ?>

    <section class="mt-8">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="rotulo">Proveedores</h2>

            <?php if ($puedeEscribir): ?>
            <form method="post" action="/panel/ia/sincronizar">
                <?= $ctx->csrf->campoOculto() ?>
                <button type="submit" class="boton-secundario">Sincronizar ahora</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if ($proveedores === []): ?>
            <p class="mt-3 text-sm text-acero">
                No hay proveedores registrados. Sin al menos uno activo y con credencial,
                el motor no puede llamar a ningún modelo.
            </p>
        <?php endif; ?>

        <div class="mt-3 overflow-x-auto">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Proveedor</th>
                    <th>Formato</th>
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
                    <td class="font-mono text-xs"><?= $e((string) $p['formato_api']) ?></td>
                    <td class="text-sm">
                        <?php /* Dato de cumplimiento: dónde se procesa el contenido de
                                los casos. Ver CLAUDE.md §9. */ ?>
                        <?= $e((string) ($p['pais_servidor'] ?? '—')) ?>
                    </td>
                    <td class="text-sm">
                        <?php $cuantos = $conteoModelos[(string) $p['clave']] ?? 0; ?>
                        <?php if ($cuantos === 0): ?>
                            <span class="text-acero">—</span>
                        <?php else: ?>
                            <span class="font-medium"><?= $cuantos ?></span>
                            <span class="text-xs text-acero">en catálogo</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm">
                        <?php if ($p['ultima_sincro'] === null): ?>
                            <span class="text-acero">nunca</span>
                        <?php elseif ((int) $p['ultima_ok'] === 1): ?>
                            <?= $e((string) $p['ultima_sincro']) ?>
                        <?php else: ?>
                            <span class="text-sello">
                                <?= $e((string) $p['ultima_sincro']) ?> —
                                <?= $e((string) $p['ultimo_error']) ?>
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
                   descubierto ninguno. Sirve para que la ficha no aparezca
                   vacía y sin explicación. NO son filas de `modelos_ia`: un
                   modelo entra al catálogo cuando el proveedor lo anuncia
                   (ADR-016), no porque figure en una lista escrita a mano. */
                $refs = $referencia[(string) $p['clave']] ?? [];
                ?>
                <?php if ($refs !== []): ?>
                <tr>
                    <td colspan="6" class="text-xs text-acero">
                        Sin descubrir todavía. Pulse «Cargar modelos» para traer los que
                        anuncia hoy. Suele ofrecer:
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

    <?php if ($puedeEscribir): ?>
    <section class="mt-10">
        <h2 class="rotulo">Añadir proveedor</h2>
        <p class="mt-2 text-sm text-acero">
            Elija uno conocido —rellena URL, formato y país— o escriba los datos de
            cualquier endpoint compatible con OpenAI. Nace <strong>inactivo</strong>:
            darlo de alta no lo mete en la cascada.
        </p>

        <form method="post" action="/panel/ia/proveedor" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>

            <?php if ($disponibles !== []): ?>
            <p class="text-sm">
                Conocidos sin dar de alta:
                <?php foreach ($disponibles as $clave => $d): ?>
                    <button type="submit" name="clave" value="<?= $e((string) $clave) ?>"
                            class="boton-secundario mt-1"><?= $e((string) $d['nombre']) ?></button>
                <?php endforeach; ?>
            </p>
            <p class="mt-2 text-xs text-acero">
                Pulsar uno lo da de alta con sus valores. Para otro cualquiera, use el
                formulario de abajo.
            </p>
            <?php endif; ?>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="rotulo">Clave</label>
                    <input name="clave" class="campo mt-1 font-mono" placeholder="mi-proveedor">
                </div>
                <div>
                    <label class="rotulo">Nombre visible</label>
                    <input name="nombre" class="campo mt-1" placeholder="Mi proveedor">
                </div>
                <div class="sm:col-span-2">
                    <label class="rotulo">URL base</label>
                    <input name="base_url" class="campo mt-1 font-mono"
                           placeholder="https://api.ejemplo.com/v1">
                </div>
                <div>
                    <label class="rotulo">Formato de API</label>
                    <select name="formato_api" class="campo mt-1">
                        <option value="openai_compatible">Compatible con OpenAI</option>
                        <option value="anthropic">Anthropic</option>
                        <option value="ollama">Ollama</option>
                    </select>
                </div>
                <div>
                    <label class="rotulo">País del servidor</label>
                    <input name="pais_servidor" class="campo mt-1" placeholder="Estados Unidos">
                    <p class="mt-1 text-xs text-acero">
                        Dato de cumplimiento: si el contenido de los casos sale de Colombia,
                        el aviso de habeas data debe declarar transferencia internacional.
                    </p>
                </div>
            </div>

            <button type="submit" class="boton mt-4">Dar de alta</button>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($puedeEscribir): ?>
    <section class="mt-10">
        <h2 class="rotulo">Credenciales de los proveedores</h2>
        <p class="mt-2 text-sm text-acero">
            Van aquí y no en Pagos: una llave de LLM no es una pasarela de cobro, y quien
            la busque no va a mirar ahí. Se guardan cifradas con el mismo mecanismo
            (AES-256-GCM) y <strong>el valor no vuelve a salir nunca</strong> por esta
            pantalla — solo la máscara.
        </p>

        <?php foreach ($proveedores as $p):
            $cred = $credenciales[(string) $p['clave']] ?? null;
            if ($cred === null) {
                continue;
            }
            $guardada = $cred['filas'][0] ?? null;
            ?>
        <form method="post" action="/panel/ia/credencial" class="tarjeta mt-4 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <input type="hidden" name="servicio" value="<?= $e((string) $p['clave']) ?>">

            <div class="flex flex-wrap items-baseline gap-x-3">
                <span class="font-medium"><?= $e((string) $p['nombre']) ?></span>
                <span class="font-mono text-xs text-acero"><?= $e($cred['clave']) ?></span>
                <?php if ($guardada !== null): ?>
                    <span class="mascara"><?= $e((string) $guardada['mascara']) ?></span>
                    <span class="text-xs text-acero">
                        guardada el <?= $e((string) $guardada['actualizado_en']) ?>
                    </span>
                <?php else: ?>
                    <span class="etiqueta etiqueta-error">sin guardar</span>
                <?php endif; ?>
            </div>

            <div class="mt-3 flex flex-wrap items-end gap-2">
                <input name="valor" type="password" autocomplete="off"
                       class="campo font-mono" style="max-width:26rem"
                       placeholder="<?= $guardada !== null ? 'Escriba una nueva para reemplazarla' : 'Pegue la llave aquí' ?>">
                <button type="submit" class="boton-secundario">Guardar</button>
            </div>
        </form>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="mt-10">
        <h2 class="rotulo">Modelos</h2>

        <?php if ($modelos === []): ?>
            <p class="mt-3 text-sm text-acero">
                Todavía no hay modelos. Corra la sincronización.
            </p>
        <?php endif; ?>

        <?php foreach ($modelos as $m):
            $retirado = $m['retirado_en'] !== null;
            $verificado = (int) $m['costos_verificados'] === 1;
            $primario = (int) $m['es_primario'] === 1;
            ?>
        <article class="tarjeta mt-4 p-4 <?= $retirado ? 'opacity-60' : '' ?>">

            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="font-mono font-medium"><?= $e((string) $m['identificador']) ?></span>
                <span class="text-sm text-acero"><?= $e((string) $m['nombre_visible']) ?></span>

                <?php if ($primario): ?>
                    <span class="etiqueta etiqueta-ok">primario</span>
                <?php endif; ?>
                <?php if ($retirado): ?>
                    <span class="etiqueta etiqueta-error">retirado por el proveedor</span>
                <?php elseif (!$verificado): ?>
                    <span class="etiqueta etiqueta-aviso">nuevo · sin costo verificado</span>
                <?php elseif ((int) $m['activo'] === 0): ?>
                    <span class="etiqueta">inactivo</span>
                <?php endif; ?>
            </div>

            <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-acero">Proveedor</dt>
                    <dd><?= $e((string) $m['proveedor_clave']) ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-acero">Propósito</dt>
                    <dd><?= $e((string) $m['proposito']) ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-acero">Ventana de contexto</dt>
                    <dd class="font-mono">
                        <?= $m['ventana_contexto'] === null
                            ? '—'
                            : $e(number_format((int) $m['ventana_contexto'], 0, ',', '.')) ?>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-acero">Costo USD / 1M (ent · sal)</dt>
                    <dd class="font-mono">
                        <?= $verificado
                            ? $e((string) $m['costo_entrada_usd_1m']) . ' · '
                              . $e((string) $m['costo_salida_usd_1m'])
                            : '<span class="text-sello">sin registrar</span>' ?>
                    </dd>
                </div>
            </dl>

            <?php if ($m['costos_verificados_en'] !== null): ?>
                <p class="mt-1 text-xs text-acero">
                    Costo verificado el <?= $e((string) $m['costos_verificados_en']) ?>.
                </p>
            <?php endif; ?>

            <?php if ((string) $m['proposito'] === 'conversacion'): ?>
                <p class="mt-1 text-xs text-acero">
                    Conjunto dorado:
                    <?php if ((string) $m['dorado_estado'] === 'verde'): ?>
                        <span class="text-verde">verde</span>
                        el <?= $e((string) $m['dorado_en']) ?>
                        · <?= (int) $m['dorado_casos'] ?> casos
                    <?php elseif ((string) $m['dorado_estado'] === 'rojo'): ?>
                        <span class="text-sello">rojo</span>
                        el <?= $e((string) $m['dorado_en']) ?>
                        · <?= (int) $m['dorado_fallos'] ?> de <?= (int) $m['dorado_casos'] ?> en rojo
                    <?php else: ?>
                        <span class="text-sello">sin correr</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (($puedeEscribir || $puedePromover) && !$retirado): ?>
            <div class="mt-3 flex flex-wrap items-end gap-3 border-t border-acero/15 pt-3">

                <?php if ($puedeEscribir): ?>
                <form method="post" action="/panel/ia/costo" class="flex flex-wrap items-end gap-2">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                    <label class="text-xs text-acero">
                        Entrada
                        <input name="costo_entrada_usd_1m" class="campo mt-1 w-24 font-mono"
                               inputmode="decimal"
                               value="<?= $e((string) ($m['costo_entrada_usd_1m'] ?? '')) ?>">
                    </label>
                    <label class="text-xs text-acero">
                        Salida
                        <input name="costo_salida_usd_1m" class="campo mt-1 w-24 font-mono"
                               inputmode="decimal"
                               value="<?= $e((string) ($m['costo_salida_usd_1m'] ?? '')) ?>">
                    </label>
                    <button type="submit" class="boton-secundario">Guardar costo</button>
                </form>
                <?php endif; ?>

                <?php if ($puedeEscribir && !$primario): ?>
                <form method="post" action="/panel/ia/activo">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                    <button type="submit" class="boton-secundario">
                        <?= (int) $m['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                    </button>
                </form>
                <?php endif; ?>

                <?php
                /* Ascender es del abogado (ia.modelos.promover), no del
                   super_admin: es la firma sobre lo que el bot dirá, no una
                   tarea técnica. Ver ADR-007 y ADR-016. */
                ?>
                <?php if ($puedePromover && !$primario):
                    $gate = $gates[(string) $m['id']] ?? ['ok' => false, 'motivo' => ''];
                    $listo = $verificado && $gate['ok'];
                    ?>
                <form method="post" action="/panel/ia/promover">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                    <button type="submit" class="boton" <?= $listo ? '' : 'disabled' ?>>
                        Hacer primario
                    </button>
                </form>
                <?php if (!$listo): ?>
                    <p class="text-xs text-sello">
                        <?= $e($verificado
                            ? (string) $gate['motivo']
                            : 'Falta registrar y verificar el costo.') ?>
                    </p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </section>

    <?php
};

/**
 * Script de la pantalla.
 *
 * Solo hace una cosa que no se puede hacer sin él: pedir los modelos del
 * proveedor elegido mientras alguien está eligiendo. El resto —guardar,
 * probar— son formularios normales que funcionan igual con JS apagado.
 *
 * Los datos van embebidos como JSON y no repartidos en atributos `data-`
 * porque son mapas, no valores sueltos.
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

// La pantalla solo existe para quien puede escribir; sin el select no hay
// nada que animar.
if (selProveedor) {
    selProveedor.addEventListener('change', alCambiarProveedor);
    selModelo.addEventListener('change', alCambiarModelo);
    alCambiarProveedor();
}

function alCambiarProveedor() {
    const clave = selProveedor.value;
    const info = PROVEEDORES[clave] || {};

    // La URL base solo se pide cuando no la sabemos: un proveedor conocido
    // ya la trae, y ofrecer el campo invita a escribir una que no es.
    $('grupo-url').classList.toggle('hidden', Boolean(info.dadoDeAlta) || esConocido(clave));

    $('pais-proveedor').textContent = info.pais_servidor
        ? 'Procesa en: ' + info.pais_servidor
        : '';

    const campoClave = $('campo-clave');
    campoClave.value = '';

    // Nunca la llave: solo su máscara, y el aviso de que dejarlo vacío la
    // conserva. Si no se dijera, cambiar de modelo obligaría a volver a
    // pegarla, y quien no la tenga a mano la borraría sin querer.
    if (info.clave_guardada) {
        campoClave.placeholder = 'Guardada: ' + info.clave_guardada + ' — escriba solo para reemplazarla';
        $('clave-estado').textContent = 'Hay una llave guardada y cifrada. Deje el campo vacío para conservarla.';
    } else {
        campoClave.placeholder = 'Pegue aquí la API key';
        $('clave-estado').textContent = 'No hay llave guardada para este proveedor.';
    }

    cargarModelos(clave);
}

function esConocido(clave) {
    const info = PROVEEDORES[clave];
    return Boolean(info && info.formato_api && !info.personalizado);
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
    // El `name` se mueve para que solo uno de los dos campos viaje: si los
    // dos se llamaran `modelo`, ganaría el último y sería el equivocado.
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
            : 'Costo precargado sin verificar: compruébelo en la página de precios del proveedor antes de guardar.';
        return;
    }

    entrada.value = '';
    salida.value = '';
    $('costo-nota').textContent = 'Ningún proveedor publica precios en su endpoint de modelos. '
        + 'Sin costo, el corte por presupuesto mensual no corta y el modelo queda inactivo.';
}
    <?php
};

require __DIR__ . '/_disposicion.php';
