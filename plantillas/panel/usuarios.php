<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<\App\Modelos\Usuario> $usuarios
 * @var list<array<string,mixed>> $roles
 * @var bool $puedeEditar
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Usuarios';

$contenido = static function () use ($e, $ctx, $usuarios, $roles, $puedeEditar): void { ?>

    <table class="tabla">
        <thead>
            <tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>2FA</th><th>Estado</th></tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= $e($u->nombre) ?></td>
                <td class="font-mono text-xs"><?= $e($u->email) ?></td>
                <td class="font-mono text-xs"><?= $e($u->rol) ?></td>
                <td>
                    <?php if ($u->totpActivo): ?>
                        <span class="text-verde">activa</span>
                    <?php elseif ($u->exigeTotp()): ?>
                        <span class="text-sello">pendiente</span>
                    <?php else: ?>
                        <span class="text-acero">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $u->activo ? 'Activo' : 'Inactivo' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($puedeEditar): ?>
    <section class="mt-8">
        <h2 class="rotulo">Crear usuario</h2>

        <form method="post" action="/panel/usuarios" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="rotulo">Nombre completo</label>
                    <input name="nombre" required class="campo mt-1">
                </div>
                <div>
                    <label class="rotulo">Correo</label>
                    <input name="email" type="email" required class="campo mt-1">
                </div>
                <div>
                    <label class="rotulo">Contraseña (mínimo 12)</label>
                    <input name="password" type="password" required minlength="12"
                           autocomplete="new-password" class="campo mt-1">
                </div>
                <div>
                    <label class="rotulo">Rol</label>
                    <select name="rol_id" class="campo mt-1">
                        <?php foreach ($roles as $rol): ?>
                            <option value="<?= $e((string) $rol['id']) ?>"><?= $e((string) $rol['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="boton mt-4">Crear usuario</button>
        </form>
    </section>
    <?php endif; ?>

<?php };

require __DIR__ . '/_disposicion.php';
