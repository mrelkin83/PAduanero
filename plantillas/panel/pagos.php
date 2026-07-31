<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var string $pasarela
 * @var list<string> $claves
 * @var bool $verCredenciales
 * @var array<string,array<string,mixed>> $estado
 * @var string $urlWebhook
 * @var string $politicaReembolso
 * @var int $horasCancelacion
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Pagos';

$contenido = static function () use ($e, $ctx, $pasarela, $claves, $verCredenciales, $estado, $urlWebhook, $politicaReembolso, $horasCancelacion): void { ?>

    <section class="tarjeta p-4">
        <p class="rotulo">Pasarela activa</p>
        <p class="mt-2 font-mono text-lg font-semibold"><?= $e($pasarela) ?></p>
        <p class="mt-1 text-sm text-acero">
            Se cambia desde Configuración → Pagos.
        </p>
    </section>

    <section class="tarjeta mt-6 p-4">
        <p class="rotulo">URL del webhook</p>
        <p class="mt-2 break-all font-mono text-sm"><?= $e($urlWebhook) ?></p>
        <p class="mt-1 text-sm text-acero">
            Se pega en el panel de la pasarela. El motor solo marca una asesoría
            como pagada por webhook con firma verificada, nunca porque el cliente
            lo diga.
        </p>
    </section>

    <?php if (!$verCredenciales): ?>
        <section class="mt-6">
            <p class="aviso aviso-atencion">
                Las credenciales de la pasarela solo las ve y edita el administrador
                técnico. Es deliberado: no las necesita para su trabajo y así no se
                pueden filtrar desde esta cuenta.
            </p>
        </section>
    <?php else: ?>

    <section class="mt-8">
        <h2 class="rotulo">Credenciales</h2>
        <p class="mt-2 text-sm text-acero">
            Los valores se guardan cifrados y <strong>nunca vuelven a mostrarse</strong>:
            solo se ve la máscara. Para cambiar uno, se escribe el nuevo completo.
        </p>

        <?php foreach (['pruebas', 'produccion'] as $entorno): ?>
        <div class="tarjeta mt-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-semibold">
                    <?= $entorno === 'produccion' ? 'Producción' : 'Pruebas (sandbox)' ?>
                </h3>

                <form method="post" action="/panel/pagos/probar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="servicio" value="<?= $e($pasarela) ?>">
                    <input type="hidden" name="entorno" value="<?= $e($entorno) ?>">
                    <button type="submit" class="boton boton-secundario">Probar conexión</button>
                </form>
            </div>

            <table class="tabla mt-3">
                <thead>
                    <tr><th>Clave</th><th>Guardada</th><th>Última prueba</th><th>Nuevo valor</th></tr>
                </thead>
                <tbody>
                <?php foreach ($claves as $clave): ?>
                    <?php $fila = $estado[$entorno][$clave] ?? null; ?>
                    <tr>
                        <td class="font-mono text-xs"><?= $e($clave) ?></td>
                        <td>
                            <?php if ($fila === null): ?>
                                <span class="text-sello">sin guardar</span>
                            <?php else: ?>
                                <span class="mascara"><?= $e((string) $fila['mascara']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs">
                            <?php if ($fila === null || $fila['ultima_prueba_en'] === null): ?>
                                —
                            <?php elseif ((int) $fila['ultima_prueba_ok'] === 1): ?>
                                <span class="text-verde">correcta</span>
                            <?php else: ?>
                                <span class="text-sello">falló</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="/panel/pagos/credenciales" class="flex gap-2">
                                <?= $ctx->csrf->campoOculto() ?>
                                <input type="hidden" name="servicio" value="<?= $e($pasarela) ?>">
                                <input type="hidden" name="clave" value="<?= $e($clave) ?>">
                                <input type="hidden" name="entorno" value="<?= $e($entorno) ?>">
                                <input name="valor" type="password" autocomplete="off"
                                       placeholder="pegar valor" class="campo">
                                <button type="submit" class="boton">Guardar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="mt-8">
        <h2 class="rotulo">Reembolsos y cancelaciones</h2>

        <div class="tarjeta mt-3 p-4">
            <p class="text-sm">
                Cancelación sin costo: <strong><?= $e((string) $horasCancelacion) ?> horas</strong> antes.
            </p>

            <?php if (trim($politicaReembolso) === ''): ?>
                <p class="aviso aviso-atencion mt-3">
                    La política de reembolso está vacía. Debe redactarla el abogado
                    antes de cobrar el primer peso. Se edita en Configuración → Pagos.
                </p>
            <?php else: ?>
                <p class="mt-3 whitespace-pre-line text-sm"><?= $e($politicaReembolso) ?></p>
            <?php endif; ?>
        </div>
    </section>

    <p class="mt-8 text-sm text-acero">
        El listado de transacciones llega en la Etapa 5, junto con el cobro real.
    </p>

<?php };

require __DIR__ . '/_disposicion.php';
