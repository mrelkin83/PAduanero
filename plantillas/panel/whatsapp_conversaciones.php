<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Conversaciones del bot: lista y lectura.
 *
 * Solo lectura a propósito, salvo «devolver a la IA»: responder al cliente se
 * hace desde el WhatsApp del negocio, no desde aquí — el panel no es una
 * bandeja (ADR-006).
 *
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $lista
 * @var array<string,mixed>|null  $abierta
 * @var list<array<string,mixed>> $mensajes
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

$contenido = static function () use ($e, $ctx, $lista, $abierta, $mensajes, $estados): void { ?>

    <p class="text-sm text-acero">
        Las últimas 100. Para responder en persona: WhatsApp del negocio.
        <a class="underline" href="/panel/whatsapp">Volver a la configuración</a>.
    </p>

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
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php };

require __DIR__ . '/_disposicion.php';
