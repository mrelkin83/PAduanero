<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Conversaciones del bot: lista, lectura y respuesta puntual.
 *
 * El ADR-006 sigue en pie — esto no es una bandeja: es UN campo de texto por
 * conversación (decisión del PO, 2026-08-24). Responder a mano deja la
 * conversación en HUMANO_ATENDIENDO para que la IA no conteste encima;
 * «Devolver a la IA» es el camino de vuelta y se conserva a propósito.
 *
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $lista
 * @var array<string,mixed>|null  $abierta
 * @var list<array<string,mixed>> $mensajes
 * @var list<array<string,mixed>> $pagosRevision
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Conversaciones de WhatsApp';

$estados = [
    'IA_ACTIVA' => 'IA atendiendo',
    'IA_PAUSADA' => 'IA en pausa',
    'HUMANO_ATENDIENDO' => 'Con una persona',
    'CERRADA' => 'Cerrada',
];

$contenido = static function () use ($e, $ctx, $lista, $abierta, $mensajes, $estados, $pagosRevision): void { ?>

    <p class="text-sm text-acero">
        Las últimas 100. Responder desde aquí deja la conversación contigo
        (la IA no contesta encima) hasta que la devuelvas.
        <a class="underline" href="/panel/whatsapp">Volver a la configuración</a>.
    </p>

    <?php if ($pagosRevision !== []): ?>
    <section class="mt-4">
        <h2 class="rotulo">Pagos por verificar</h2>
        <p class="mt-1 text-sm text-acero">
            Transferencias directas (Nequi, banco): el cliente mandó su comprobante y
            espera. Aprobar el pago confirma la cita, crea el evento en el calendario
            y le avisa al cliente por WhatsApp.
        </p>
        <table class="tabla mt-2">
            <thead><tr><th>Cliente</th><th>Monto</th><th>Cita</th><th>Comprobante</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pagosRevision as $p): ?>
                <tr>
                    <td>
                        <?= $e((string) ($p['nombre_contacto'] ?: 'Sin nombre')) ?>
                        <a class="block text-xs underline" href="/panel/whatsapp/conversaciones?ver=<?= (int) $p['conversacion_id'] ?>">ver conversación</a>
                    </td>
                    <td class="font-mono">$<?= $e(number_format((float) $p['monto'], 0, ',', '.')) ?></td>
                    <td class="font-mono text-xs"><?= $e((string) ($p['cita_inicio'] ?? '—')) ?></td>
                    <td>
                        <?php if (($p['comprobante_media_ruta'] ?? '') !== ''): ?>
                            <a class="underline" target="_blank" href="/panel/whatsapp/comprobante?pago=<?= (int) $p['pago_id'] ?>">abrir</a>
                        <?php else: ?>
                            <span class="text-acero">sin adjunto</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ctx->puede('casos.editar')): ?>
                        <form method="post" action="/panel/whatsapp/pagos/aprobar">
                            <?= $ctx->csrf->campoOculto() ?>
                            <input type="hidden" name="pedido_id" value="<?= (int) $p['pedido_id'] ?>">
                            <button type="submit" class="boton">Aprobar pago</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <div class="mt-4 grid gap-6 lg:grid-cols-2">

        <div>
            <?php if ($lista === []): ?>
                <p class="tarjeta p-4 text-sm">Todavía no hay conversaciones.</p>
            <?php else: ?>
            <table class="tabla">
                <thead><tr><th>Contacto</th><th>Estado</th><th>Último mensaje</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $c): ?>
                    <tr>
                        <td>
                            <?= $e((string) ($c['nombre_contacto'] ?: 'Sin nombre')) ?>
                            <span class="block font-mono text-xs text-acero"><?= $e((string) $c['telefono']) ?></span>
                        </td>
                        <td><?= $e($estados[$c['estado']] ?? (string) $c['estado']) ?></td>
                        <td class="font-mono text-xs"><?= $e((string) ($c['ultimo_mensaje_at'] ?? '')) ?></td>
                        <td><a class="underline" href="/panel/whatsapp/conversaciones?ver=<?= (int) $c['id'] ?>">leer</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div>
            <?php if ($abierta === null): ?>
                <p class="tarjeta p-4 text-sm text-acero">Elija una conversación para leerla.</p>
            <?php else: ?>
            <div class="tarjeta p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="rotulo">
                        <?= $e((string) ($abierta['nombre_contacto'] ?: $abierta['telefono'])) ?>
                        · <?= $e($estados[$abierta['estado']] ?? (string) $abierta['estado']) ?>
                    </h2>

                    <?php if ($abierta['estado'] !== 'IA_ACTIVA' && $ctx->puede('casos.editar')): ?>
                    <form method="post" action="/panel/whatsapp/conversaciones/reanudar">
                        <?= $ctx->csrf->campoOculto() ?>
                        <input type="hidden" name="conversacion_id" value="<?= (int) $abierta['id'] ?>">
                        <button type="submit" class="boton">Devolver a la IA</button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="mt-3 grid gap-2">
                <?php foreach ($mensajes as $m): ?>
                    <div class="rounded border p-2 text-sm <?= $m['direccion'] === 'entrante' ? '' : 'bg-crema' ?>">
                        <p class="text-xs text-acero">
                            <?= $m['direccion'] === 'entrante' ? 'Cliente' : 'Bot' ?>
                            · <?= $e((string) $m['created_at']) ?>
                            <?php if ($m['tipo'] !== 'texto'): ?> · <?= $e((string) $m['tipo']) ?><?php endif; ?>
                        </p>
                        <p class="mt-1 whitespace-pre-wrap"><?= $e((string) ($m['contenido'] ?: $m['transcripcion'] ?: '')) ?></p>
                    </div>
                <?php endforeach; ?>
                </div>

                <?php if ($ctx->puede('casos.editar')): ?>
                <form method="post" action="/panel/whatsapp/conversaciones/responder" class="mt-4">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="conversacion_id" value="<?= (int) $abierta['id'] ?>">
                    <label class="rotulo" for="texto-respuesta">Responder al cliente</label>
                    <textarea id="texto-respuesta" name="texto" rows="3" required maxlength="4000"
                              class="campo mt-1 w-full"
                              placeholder="El mensaje sale por el WhatsApp del negocio tal cual lo escribas."></textarea>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <p class="text-xs text-acero">Al enviar, la conversación queda contigo.</p>
                        <button type="submit" class="boton">Enviar</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php };

require __DIR__ . '/_disposicion.php';
