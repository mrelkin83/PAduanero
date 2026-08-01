<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var bool  $iaPausada
 * @var bool  $modoSombra
 * @var int   $precio
 * @var string $pasarela
 * @var list<string> $pendientes
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Tablero';

$contenido = static function () use (
    $e,
    $ctx,
    $iaPausada,
    $modoSombra,
    $precio,
    $pasarela,
    $pendientes,
    $desde,
    $hasta,
    $embudo,
    $landing,
    $puedeAnotarInversion,
): void { ?>

    <?php /* Los dos interruptores, siempre a la vista: «que nunca se olvide
             encendida o apagada por descuido» (PANEL_ADMIN §2.1). */ ?>
    <section class="grid gap-4 sm:grid-cols-2">
        <div class="tarjeta p-4">
            <p class="rotulo">Motor de IA</p>
            <p class="mt-2 text-lg font-semibold <?= $iaPausada ? 'text-sello' : '' ?>">
                <?= $iaPausada ? 'PAUSADA' : 'Activa' ?>
            </p>
            <p class="mt-1 text-sm text-acero">
                <?= $iaPausada
                    ? 'El bot está callado. Chatwoot y WhatsApp siguen funcionando.'
                    : 'El bot responde según su horario.' ?>
            </p>
        </div>

        <div class="tarjeta p-4">
            <p class="rotulo">Modo sombra</p>
            <p class="mt-2 text-lg font-semibold <?= $modoSombra ? 'text-ambar' : '' ?>">
                <?= $modoSombra ? 'ENCENDIDO' : 'Envío automático' ?>
            </p>
            <p class="mt-1 text-sm text-acero">
                <?= $modoSombra
                    ? 'La IA escribe como nota privada; no envía nada al cliente.'
                    : 'La IA responde directamente al cliente.' ?>
            </p>
        </div>
    </section>

    <section class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="tarjeta p-4">
            <p class="rotulo">Tarifa vigente</p>
            <p class="mt-2 font-mono text-lg font-semibold">
                $<?= $e(number_format($precio, 0, ',', '.')) ?>
            </p>
            <p class="mt-1 text-sm text-acero">
                En pesos. Las reservas ya creadas conservan el precio que tenían.
            </p>
        </div>

        <div class="tarjeta p-4">
            <p class="rotulo">Pasarela activa</p>
            <p class="mt-2 font-mono text-lg font-semibold"><?= $e($pasarela ?: '—') ?></p>
            <?php if ($ctx->puede('pagos.credenciales.ver')): ?>
                <a href="/panel/pagos" class="mt-1 inline-block text-sm underline">Probar conexión</a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($pendientes !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Antes de poder cobrar</h2>
        <ul class="mt-3 space-y-2">
            <?php foreach ($pendientes as $pendiente): ?>
                <li class="aviso aviso-atencion"><?= $e($pendiente) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php /* ── El embudo por canal (Etapa 8) ─────────────────────────── */ ?>
    <section class="mt-10">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="rotulo">Embudo por canal</h2>

            <form method="get" action="/panel" class="flex flex-wrap items-center gap-2 text-sm">
                <input type="date" name="desde" value="<?= $e($desde) ?>" class="campo" style="max-width:11rem">
                <span class="text-acero">a</span>
                <input type="date" name="hasta" value="<?= $e($hasta) ?>" class="campo" style="max-width:11rem">
                <button type="submit" class="boton-secundario">Ver</button>
            </form>
        </div>

        <?php if ($embudo === []): ?>
            <p class="mt-3 text-sm text-acero">Sin contactos en el rango.</p>
        <?php else: ?>
        <div class="mt-3 overflow-x-auto">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Canal</th>
                    <th>Leads</th>
                    <th>Casos</th>
                    <th>Pagadas</th>
                    <th>Conversión</th>
                    <th>Inversión</th>
                    <th>Costo / lead</th>
                    <th>Ingresos</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($embudo as $f): ?>
                <tr>
                    <td class="font-mono text-sm"><?= $e($f['canal']) ?></td>
                    <td><?= $f['leads'] ?></td>
                    <td><?= $f['casos'] ?></td>
                    <td><?= $f['pagadas'] ?></td>
                    <td>
                        <?php /* NULL se pinta como raya, no como 0 %: un cero
                                donde no hay denominador es un dato falso. */ ?>
                        <?= $f['conversion'] !== null
                            ? number_format($f['conversion'] * 100, 1) . ' %'
                            : '—' ?>
                    </td>
                    <td>
                        <?= $f['inversion_cop'] > 0
                            ? '$' . number_format($f['inversion_cop'], 0, ',', '.')
                            : '<span class="text-acero">sin anotar</span>' ?>
                    </td>
                    <td>
                        <?= $f['costo_por_lead_cop'] !== null
                            ? '$' . number_format($f['costo_por_lead_cop'], 0, ',', '.')
                            : '—' ?>
                    </td>
                    <td>$<?= number_format($f['ingresos_cop'], 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php if ($landing !== []): ?>
        <p class="mt-3 text-xs text-acero">
            Landing en el rango:
            <?php foreach ($landing as $l): ?>
                <span class="ml-2 font-mono"><?= $e($l['canal']) ?>·<?= $e($l['tipo']) ?>: <?= $l['eventos'] ?></span>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>

        <?php if ($puedeAnotarInversion): ?>
        <details class="mt-4">
            <summary class="cursor-pointer text-sm text-acero">Anotar inversión mensual de un canal</summary>
            <p class="mt-2 text-xs text-acero">
                Mientras no haya tokens de Meta Ads y Google Ads, la inversión se anota a
                mano. Sin ella, el costo por lead sale como raya — nunca como $0, que
                diría que los leads fueron gratis.
            </p>
            <form method="post" action="/panel/inversion" class="mt-2 flex flex-wrap items-end gap-2">
                <?= $ctx->csrf->campoOculto() ?>
                <label class="text-xs text-acero">
                    Mes
                    <input type="month" name="mes" class="campo mt-1" required>
                </label>
                <label class="text-xs text-acero">
                    Canal
                    <input name="canal" class="campo mt-1 font-mono" placeholder="meta" required>
                </label>
                <label class="text-xs text-acero">
                    Pesos
                    <input name="monto_cop" class="campo mt-1" inputmode="numeric" placeholder="1500000" required>
                </label>
                <button type="submit" class="boton-secundario">Guardar</button>
            </form>
        </details>
        <?php endif; ?>
    </section>

    <p class="mt-8 text-sm text-acero">
        Las conversaciones viven en Chatwoot, no aquí (ADR-006).
    </p>

<?php };

require __DIR__ . '/_disposicion.php';
