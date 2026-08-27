<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $compras
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Compras de cursos';

$contenido = static function () use ($e, $ctx, $compras): void {
    $editable = $ctx->puede('cursos.editar');
    ?>
    <h2 class="rotulo">Compras de cursos</h2>

    <table class="tabla mt-4">
        <thead><tr><th>Curso</th><th>Comprador</th><th>Correo</th><th>Precio</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($compras as $c): ?>
        <tr>
            <td><?= $e((string) $c['titulo']) ?></td>
            <td><?= $e((string) $c['nombre']) ?></td>
            <td><?= $e((string) $c['correo']) ?></td>
            <td class="font-mono">$<?= $e(number_format((int) $c['precio_cop'], 0, ',', '.')) ?></td>
            <td><?= $e((string) $c['estado']) ?></td>
            <td>
                <?php if ($editable && $c['estado'] !== 'pagada'): ?>
                <form method="post" action="/panel/cursos/compras/aprobar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $c['id']) ?>">
                    <button type="submit" class="text-sm underline"
                            onclick="return confirm('¿Aprobar esta compra a mano? Se le enviará el correo de registro al comprador.')">
                        Aprobar a mano
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($editable && $c['estado'] === 'pagada' && $c['comprador_id'] === null): ?>
                <form method="post" action="/panel/cursos/compras/reenviar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $c['id']) ?>">
                    <button type="submit" class="text-sm underline">Reenviar acceso</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ($compras === []): ?>
        <tr><td colspan="6" class="text-acero">Todavía no hay compras.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php };

require __DIR__ . '/_disposicion.php';
