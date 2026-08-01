<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $versiones
 * @var array<string,array{ok:bool,motivo:string}> $gates
 * @var array{id:string,version:int,contenido:string}|null $activo
 * @var bool $puedeAprobar
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Prompts del bot';

$contenido = static function () use ($e, $ctx, $versiones, $gates, $activo, $puedeAprobar): void {
    ?>

    <section>
        <p class="text-sm text-acero">
            Toda versión nace <strong>inactiva</strong>. Activarla es la firma del abogado
            sobre lo que el bot va a decir — no sobre la redacción, que puede no ser suya,
            sino sobre la responsabilidad profesional de lo que salga de ahí.
        </p>
        <p class="mt-2 text-sm text-acero">
            Y antes de la firma, el conjunto dorado: una versión no se activa hasta haber
            pasado en verde contra el modelo que está hablando. Sin eso quedaba un hueco
            por el que se colaba lo mismo que el catálogo de modelos impide por el otro
            lado — no se puede cambiar el modelo sin dorado, pero se conseguía el mismo
            efecto cambiando el prompt.
        </p>
    </section>

    <?php if ($activo === null): ?>
    <section class="aviso aviso-error mt-6">
        <p class="font-semibold">No hay ninguna versión activa.</p>
        <p class="mt-1 text-sm">
            El motor no habla sin instrucciones aprobadas: cada intento termina en
            escalamiento a humano. Cargue una versión y actívela.
        </p>
    </section>
    <?php endif; ?>

    <section class="mt-8">
        <h2 class="rotulo">Versiones</h2>

        <?php if ($versiones === []): ?>
            <p class="mt-3 text-sm text-acero">
                Todavía no hay ninguna. Se cargan desde el repositorio con
                <span class="font-mono">php bin/cargar-prompt.php db/prompts/conversacion.v1.txt</span>,
                para que el texto viva en un archivo revisable y no solo en la base.
            </p>
        <?php endif; ?>

        <?php foreach ($versiones as $v):
            $esActiva = (int) $v['activo'] === 1;
            $gate = $gates[(string) $v['id']] ?? null;
            ?>
        <article class="tarjeta mt-4 p-4">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="font-mono font-medium">v<?= (int) $v['version'] ?></span>

                <?php if ($esActiva): ?>
                    <span class="etiqueta etiqueta-ok">activa</span>
                <?php elseif ($gate !== null && $gate['ok']): ?>
                    <span class="etiqueta etiqueta-aviso">lista para activar</span>
                <?php else: ?>
                    <span class="etiqueta">inactiva</span>
                <?php endif; ?>

                <span class="text-xs text-acero"><?= $e((string) $v['creado_en']) ?></span>
            </div>

            <?php if (($v['notas_cambio'] ?? null) !== null): ?>
                <p class="mt-2 text-sm"><?= $e((string) $v['notas_cambio']) ?></p>
            <?php endif; ?>

            <?php if ($esActiva && ($v['aprobado_en'] ?? null) !== null): ?>
                <p class="mt-1 text-xs text-acero">
                    Aprobada por <?= $e((string) ($v['aprobado_por_nombre'] ?? '—')) ?>
                    el <?= $e((string) $v['aprobado_en']) ?>.
                </p>
            <?php endif; ?>

            <?php if (!$esActiva && $gate !== null && !$gate['ok']): ?>
                <p class="mt-2 text-xs text-sello"><?= $e($gate['motivo']) ?></p>
            <?php endif; ?>

            <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-acero/15 pt-3">
                <?php if ($activo !== null && !$esActiva): ?>
                <a class="boton-secundario"
                   href="/panel/prompts/diff?a=<?= $e($activo['id']) ?>&amp;b=<?= $e((string) $v['id']) ?>">
                    Ver diferencias con la activa
                </a>
                <?php endif; ?>

                <?php
                /* Activar es `ia.prompts.aprobar`: solo el abogado. El
                   super_admin edita y prueba, no firma (ADR-007). */
                ?>
                <?php if ($puedeAprobar && !$esActiva): ?>
                <form method="post" action="/panel/prompts/activar">
                    <?= $ctx->csrf->campoOculto() ?>
                    <input type="hidden" name="id" value="<?= $e((string) $v['id']) ?>">
                    <button type="submit" class="boton" <?= ($gate !== null && $gate['ok']) ? '' : 'disabled' ?>>
                        Activar esta versión
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </section>

    <section class="mt-10">
        <h2 class="rotulo">Nueva versión</h2>
        <p class="mt-2 text-sm text-acero">
            Nace inactiva. Después:
            <span class="font-mono">php bin/correr-dorado.php --prompt=&lt;n&gt;</span>
        </p>

        <form method="post" action="/panel/prompts" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>

            <label class="rotulo">Contenido</label>
            <textarea name="contenido" rows="18" class="campo mt-1 font-mono text-xs"
                      placeholder="Eres el asistente virtual del despacho…"><?= $e($activo['contenido'] ?? '') ?></textarea>

            <label class="rotulo mt-4 block">Qué cambia y por qué</label>
            <input name="notas" class="campo mt-1"
                   placeholder="Ej.: refuerza la prohibición de plazos tras el caso plazo-02 del dorado">

            <button type="submit" class="boton mt-4">Crear versión inactiva</button>
        </form>
    </section>

    <?php
};

require __DIR__ . '/_disposicion.php';
