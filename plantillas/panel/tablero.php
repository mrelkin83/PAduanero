<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Tablero.
 *
 * Se encogió con el motor. Antes abría con los dos interruptores de la IA
 * —pausa y modo sombra— y seguía con el embudo hasta la asesoría pagada.
 * Ahora mide solo lo que ocurre dentro de esta aplicación: qué canal trae
 * visitas y cuántas terminan pulsando el botón de WhatsApp.
 *
 * Eso obliga a un cuidado al leerlo, y por eso está escrito en la pantalla:
 * la conversión que muestra es a CONVERSACIÓN INICIADA, no a cliente. Lo que
 * pase después del clic ocurre en WhatsApp, donde este sistema ya no mira.
 *
 * @var \App\Panel\Contexto $ctx
 * @var int    $precio
 * @var list<string> $pendientes
 * @var string $desde
 * @var string $hasta
 * @var list<array{canal:string,tipo:string,eventos:int}> $canales
 * @var array<string,int> $inversion
 * @var bool   $puedeAnotarInversion
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Tablero';

$contenido = static function () use (
    $e,
    $ctx,
    $precio,
    $pendientes,
    $desde,
    $hasta,
    $canales,
    $inversion,
    $puedeAnotarInversion,
): void {
    /* Se pivota aquí y no en el servicio porque es presentación: la consulta
       devuelve una fila por canal y tipo, que es la forma correcta de
       agregarlo en SQL; la tabla necesita una fila por canal. */
    $filas = [];

    foreach ($canales as $fila) {
        $canal = $fila['canal'];
        $filas[$canal] ??= ['vista' => 0, 'scroll_50' => 0, 'click_whatsapp' => 0, 'perfil' => 0];

        if (str_starts_with($fila['tipo'], 'perfil_')) {
            $filas[$canal]['perfil'] += $fila['eventos'];
        } elseif (isset($filas[$canal][$fila['tipo']])) {
            $filas[$canal][$fila['tipo']] += $fila['eventos'];
        }
    }

    ksort($filas);
    ?>

    <section class="grid gap-4 sm:grid-cols-2">
        <div class="tarjeta p-4">
            <p class="rotulo">Precio de la asesoría</p>
            <p class="mt-2 font-mono text-lg font-semibold">
                $<?= $e(number_format($precio, 0, ',', '.')) ?>
            </p>
            <p class="mt-1 text-xs text-acero">
                En pesos. Es el que se pinta en la landing y en el diagnóstico.
            </p>
        </div>

        <div class="tarjeta p-4">
            <p class="rotulo">Alcance de esta pantalla</p>
            <p class="mt-2 text-sm">Hasta el clic a WhatsApp</p>
            <p class="mt-1 text-xs text-acero">
                Lo que ocurre en la conversación ya no se mide aquí: el motor
                y la pasarela se retiraron.
            </p>
        </div>
    </section>

    <?php if ($pendientes !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Antes de publicar</h2>
        <ul class="mt-3 space-y-2 text-sm">
            <?php foreach ($pendientes as $pendiente): ?>
            <li class="aviso-atencion p-3"><?= $e($pendiente) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="mt-10">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="rotulo">Landing por canal</h2>

            <form method="get" action="/panel" class="flex flex-wrap items-center gap-2 text-sm">
                <input type="date" name="desde" value="<?= $e($desde) ?>" class="campo" style="max-width:11rem">
                <span class="text-acero">a</span>
                <input type="date" name="hasta" value="<?= $e($hasta) ?>" class="campo" style="max-width:11rem">
                <button type="submit" class="boton-secundario">Ver</button>
            </form>
        </div>

        <?php if ($filas === []): ?>
            <p class="mt-3 text-sm text-acero">Sin visitas registradas en el rango.</p>
        <?php else: ?>
        <div class="mt-3 overflow-x-auto">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Canal</th>
                    <th>Vistas</th>
                    <th>Leyeron</th>
                    <th>Diagnóstico</th>
                    <th>Clics a WhatsApp</th>
                    <th>Conversión</th>
                    <th>Inversión</th>
                    <th>Costo / clic</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($filas as $canal => $f): ?>
                <?php
                $gastado = $inversion[$canal] ?? null;

                /* Ambos se pintan como raya cuando no hay denominador, nunca
                   como 0: un cero ahí diría que nadie convirtió o que los
                   clics salieron gratis, y las dos cosas son falsas. */
                $conversion = $f['vista'] > 0 ? $f['click_whatsapp'] / $f['vista'] : null;
                $costo = ($gastado !== null && $f['click_whatsapp'] > 0)
                    ? (int) round($gastado / $f['click_whatsapp'])
                    : null;
                ?>
                <tr>
                    <td class="font-mono text-sm"><?= $e($canal) ?></td>
                    <td><?= $f['vista'] ?></td>
                    <td><?= $f['scroll_50'] ?></td>
                    <td><?= $f['perfil'] ?></td>
                    <td><?= $f['click_whatsapp'] ?></td>
                    <td><?= $conversion !== null ? number_format($conversion * 100, 1) . ' %' : '—' ?></td>
                    <td>
                        <?= $gastado !== null
                            ? '$' . number_format($gastado, 0, ',', '.')
                            : '<span class="text-acero">sin anotar</span>' ?>
                    </td>
                    <td><?= $costo !== null ? '$' . number_format($costo, 0, ',', '.') : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php if ($puedeAnotarInversion): ?>
        <details class="mt-4">
            <summary class="cursor-pointer text-sm text-acero">Anotar inversión mensual de un canal</summary>
            <p class="mt-2 text-xs text-acero">
                Mientras no haya tokens de Meta Ads y Google Ads, la inversión se anota a
                mano. Sin ella, el costo por clic sale como raya — nunca como $0, que
                diría que los clics fueron gratis.
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

<?php };

require __DIR__ . '/_disposicion.php';
