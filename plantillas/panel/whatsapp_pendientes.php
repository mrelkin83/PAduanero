<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Chats sin responder: lo que quedó esperando tras una desconexión.
 *
 * Tres tiempos, y el orden es la gracia: la pantalla LISTA, «Analizar»
 * PROPONE (la IA redacta, nada sale), y «Enviar» manda solo lo que la
 * persona marcó — con el texto ya editado si hizo falta. La respuesta llega
 * al cliente como nota de voz con la voz configurada en Medios y voz.
 *
 * @var \App\Panel\Contexto $ctx
 * @var list<array{jid:string,telefono:string,nombre:string,tipo:string,texto:string,cuando:int}> $lista
 * @var array<string,array{ok:bool,responder:bool,texto:string,motivo:string,error:string}> $propuestas
 * @var string $errorCanal
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Pendientes sin responder';

$haceCuanto = static function (int $ts): string {
    $seg = max(0, time() - $ts);
    if ($seg < 3600) {
        return 'hace ' . intdiv($seg, 60) . ' min';
    }
    if ($seg < 86400) {
        return 'hace ' . intdiv($seg, 3600) . ' h';
    }

    return 'hace ' . intdiv($seg, 86400) . ' días';
};

$contenido = static function () use ($e, $ctx, $lista, $propuestas, $errorCanal, $haceCuanto): void { ?>

    <p class="text-sm text-acero">
        Chats donde la última palabra la tiene el cliente — típicamente porque la
        línea estuvo desconectada. «Analizar» le pide a la IA una propuesta por
        chat; nada se envía hasta que la revises y la marques. Lo enviado sale
        como <strong>nota de voz</strong> con la voz configurada.
        <a class="underline" href="/panel/whatsapp/conversaciones">Ver conversaciones</a> ·
        <a class="underline" href="/panel/whatsapp">Configuración</a>.
    </p>

    <?php if ($errorCanal !== ''): ?>
        <p class="aviso-error mt-4"><?= $e($errorCanal) ?></p>
    <?php elseif ($lista === []): ?>
        <p class="mt-4 text-sm text-acero">No hay chats esperando respuesta. 🎉</p>
    <?php else: ?>

        <?php if ($ctx->puede('casos.editar')): ?>
        <form method="post" action="/panel/whatsapp/pendientes/analizar" class="mt-4">
            <?= $ctx->csrf->campoOculto() ?>
            <button type="submit" class="boton">Analizar con IA (<?= count($lista) ?>)</button>
            <span class="ml-2 text-xs text-acero">
                La IA lee cada chat y redacta una propuesta. Puede tardar un momento.
            </span>
        </form>
        <?php endif; ?>

        <form method="post" action="/panel/whatsapp/pendientes/enviar" class="mt-4">
            <?= $ctx->csrf->campoOculto() ?>

            <div class="grid gap-3">
            <?php foreach ($lista as $p):
                $prop = $propuestas[$p['jid']] ?? null;
                $marcar = $prop !== null && $prop['ok'] && $prop['responder'] && $prop['texto'] !== '';
            ?>
                <div class="tarjeta p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">
                                <?= $e($p['nombre'] !== '' ? $p['nombre'] : $p['telefono']) ?>
                                <span class="text-xs text-acero">· <?= $e($p['telefono']) ?> · <?= $e($haceCuanto($p['cuando'])) ?></span>
                            </p>
                            <p class="mt-1 text-sm whitespace-pre-wrap"><?= $e(mb_substr($p['texto'], 0, 400)) ?></p>
                        </div>
                        <?php if ($ctx->puede('casos.editar')): ?>
                        <label class="flex shrink-0 items-center gap-2 text-sm">
                            <input type="checkbox" name="enviar[]" value="<?= $e($p['jid']) ?>" <?= $marcar ? 'checked' : '' ?>>
                            Enviar
                        </label>
                        <?php endif; ?>
                    </div>

                    <input type="hidden" name="telefono[<?= $e($p['jid']) ?>]" value="<?= $e($p['telefono']) ?>">

                    <?php if ($prop !== null && !$prop['ok']): ?>
                        <p class="aviso-error mt-2 text-sm">La IA no pudo proponer: <?= $e($prop['error']) ?></p>
                    <?php elseif ($prop !== null && !$prop['responder']): ?>
                        <p class="mt-2 text-sm text-acero">
                            La IA sugiere <strong>no responder</strong><?= $prop['motivo'] !== '' ? ': ' . $e($prop['motivo']) : '.' ?>
                            Si no estás de acuerdo, escribe la respuesta y marca «Enviar».
                        </p>
                    <?php elseif ($prop !== null && $prop['motivo'] !== ''): ?>
                        <p class="mt-2 text-xs text-acero"><?= $e($prop['motivo']) ?></p>
                    <?php endif; ?>

                    <?php if ($ctx->puede('casos.editar')): ?>
                    <textarea name="texto[<?= $e($p['jid']) ?>]" rows="3" maxlength="1500"
                              class="campo mt-2 w-full text-sm"
                              placeholder="La respuesta que va a sonar como nota de voz. Sin enlaces ni datos que se dicten mal."><?= $e($prop['texto'] ?? '') ?></textarea>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <?php if ($ctx->puede('casos.editar')): ?>
            <div class="mt-4 flex items-center justify-between gap-2">
                <p class="text-xs text-acero">
                    Solo salen los chats marcados. Cada envío deja la conversación contigo
                    (HUMANO_ATENDIENDO), como responder a mano.
                </p>
                <button type="submit" class="boton">Enviar notas de voz</button>
            </div>
            <?php endif; ?>
        </form>

    <?php endif; ?>

<?php };

require __DIR__ . '/_disposicion.php';
