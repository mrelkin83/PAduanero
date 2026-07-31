<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var string|null $secreto
 * @var string|null $uri
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Seguridad de mi cuenta';

$contenido = static function () use ($e, $ctx, $secreto, $uri): void {
    $usuario = $ctx->usuario;
    ?>

    <section class="tarjeta p-4">
        <p class="rotulo">Verificación en dos pasos</p>

        <?php if ($usuario->totpActivo): ?>
            <p class="mt-2 text-verde">Activa.</p>
            <p class="mt-1 text-sm text-acero">
                Al entrar se le pedirá el código de su aplicación de autenticación.
            </p>

        <?php elseif ($secreto === null): ?>
            <?php if ($usuario->exigeTotp()): ?>
                <p class="aviso aviso-atencion mt-2">
                    Su rol maneja credenciales y aprueba lo que dice el bot.
                    La verificación en dos pasos es obligatoria.
                </p>
            <?php endif; ?>

            <form method="post" action="/panel/seguridad/totp" class="mt-3">
                <?= $ctx->csrf->campoOculto() ?>
                <button type="submit" class="boton">Activar verificación en dos pasos</button>
            </form>

        <?php else: ?>
            <p class="mt-2 text-sm">
                Añada esta cuenta a su aplicación de autenticación (Google Authenticator,
                Aegis, 1Password…) y confirme con el código que muestre.
            </p>

            <div class="mt-4">
                <p class="rotulo">Clave de configuración</p>
                <p class="mt-1 select-all break-all font-mono text-lg tracking-widest"><?= $e($secreto) ?></p>
            </div>

            <details class="mt-3">
                <summary class="cursor-pointer text-sm underline">Ver enlace completo</summary>
                <p class="mt-2 select-all break-all font-mono text-xs text-acero"><?= $e((string) $uri) ?></p>
            </details>

            <?php /* Sin código QR: generarlo exige una dependencia nueva
                     (endroid/qr-code) y las dependencias necesitan aprobación
                     del PO. Todas las apps de autenticación permiten meter la
                     clave a mano. */ ?>
            <p class="mt-2 text-xs text-acero">
                Si su aplicación pide escanear un QR, use la opción de introducir la
                clave manualmente.
            </p>

            <form method="post" action="/panel/seguridad/totp/confirmar" class="mt-5 flex flex-wrap items-end gap-3">
                <?= $ctx->csrf->campoOculto() ?>
                <div>
                    <label for="codigo" class="rotulo">Código de 6 dígitos</label>
                    <input id="codigo" name="codigo" required maxlength="6" inputmode="numeric"
                           autocomplete="one-time-code"
                           class="campo mt-1 w-40 text-center font-mono text-lg tracking-[0.3em]">
                </div>
                <button type="submit" class="boton">Confirmar y activar</button>
            </form>

            <p class="mt-3 text-xs text-acero">
                La activación no se completa hasta que el código coincida. Así no se
                queda usted fuera con un secreto que el teléfono nunca guardó.
            </p>
        <?php endif; ?>
    </section>

<?php };

require __DIR__ . '/_disposicion.php';
