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

$contenido = static function () use ($e, $ctx, $iaPausada, $modoSombra, $precio, $pasarela, $pendientes): void { ?>

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

    <p class="mt-8 text-sm text-acero">
        Las conversaciones viven en Chatwoot, no aquí (ADR-006). Las métricas del
        embudo llegan en la Etapa 8.
    </p>

<?php };

require __DIR__ . '/_disposicion.php';
