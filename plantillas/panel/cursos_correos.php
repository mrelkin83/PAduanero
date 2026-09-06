<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Bitácora de la cola de correos: qué se envió, qué está pendiente y qué falló.
 *
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $correos
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Correos enviados';

$estados = [
    'pendiente' => 'Pendiente',
    'enviado'   => 'Enviado',
    'fallido'   => 'Fallido',
];

$contenido = static function () use ($e, $ctx, $correos, $estados): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo">Correos enviados</h2>
    <p class="mt-2 text-sm text-acero">
        Los correos de cursos y citas salen por una cola que un cron envía cada
        minuto, reintentando hasta 3 veces. Aquí ves su estado; un correo
        fallido se puede volver a poner en la cola.
    </p>

    <?php if ($correos === []): ?>
        <p class="mt-4 text-sm text-acero">Todavía no se ha encolado ningún correo.</p>
    <?php else: ?>
        <div class="overflow-x-auto">
        <table class="tabla mt-4">
            <thead><tr>
                <th>Fecha</th><th>Para</th><th>Asunto</th><th>Tipo</th>
                <th>Estado</th><th>Intentos</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($correos as $c): ?>
                <tr>
                    <td class="whitespace-nowrap text-xs"><?= $e((string) $c['creado_en']) ?></td>
                    <td class="text-sm"><?= $e((string) $c['destinatario']) ?></td>
                    <td class="text-sm"><?= $e((string) $c['asunto']) ?></td>
                    <td class="text-xs text-acero"><?= $e((string) $c['tipo']) ?></td>
                    <td class="text-sm">
                        <?php $est = (string) $c['estado']; ?>
                        <span class="<?= $est === 'fallido' ? 'text-alerta' : ($est === 'enviado' ? 'text-oro' : '') ?>">
                            <?= $e($estados[$est] ?? $est) ?>
                        </span>
                        <?php if ($est === 'fallido' && !empty($c['ultimo_error'])): ?>
                            <br><span class="text-xs text-acero"><?= $e((string) $c['ultimo_error']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center text-sm"><?= (int) $c['intentos'] ?></td>
                    <td>
                        <?php if ($editable && $est === 'fallido'): ?>
                        <form method="post" action="/panel/cursos/correos/reintentar">
                            <?= $ctx->csrf->campoOculto() ?>
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="boton-secundario">Reintentar</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
<?php };

require __DIR__ . '/_disposicion.php';
