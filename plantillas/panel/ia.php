<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $proveedores
 * @var list<array<string,mixed>> $modelos
 * @var bool $puedeEscribir
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Modelos de IA';

$contenido = static function () use ($e, $ctx, $proveedores, $modelos, $puedeEscribir): void {
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

    <section>
        <p class="text-sm text-acero">
            El catálogo se sincroniza solo con lo que cada proveedor anuncia: si sale un
            modelo nuevo, aparece aquí al día siguiente sin tocar código.
            <strong>Lo que no es automático es empezar a usarlo.</strong> Un modelo nuevo
            entra inactivo y sin costo, y ascenderlo a primario es una decisión firmada:
            cambia lo que el bot dice, igual que cambiar un prompt.
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
                    <th>Última sincronización</th>
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
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

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

            <?php if ($puedeEscribir && !$retirado): ?>
            <div class="mt-3 flex flex-wrap items-end gap-3 border-t border-acero/15 pt-3">

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

                <?php if (!$primario): ?>
                <form method="post" action="/panel/ia/activo">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                    <button type="submit" class="boton-secundario">
                        <?= (int) $m['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                    </button>
                </form>

                <form method="post" action="/panel/ia/promover">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                    <button type="submit" class="boton"
                            <?= $verificado ? '' : 'disabled' ?>>
                        Hacer primario
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </section>

    <?php
};

require __DIR__ . '/_disposicion.php';
