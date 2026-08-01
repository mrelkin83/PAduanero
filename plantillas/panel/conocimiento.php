<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Base de conocimiento jurídico.
 *
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $documentos
 * @var string $consulta
 * @var list<array{contenido:string,referencia:string,documentoId:string,similitud:float}> $resultados
 * @var bool $puedeCargar
 * @var bool $puedeVerificar
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Conocimiento';

$contenido = static function () use (
    $e,
    $ctx,
    $documentos,
    $consulta,
    $resultados,
    $puedeCargar,
    $puedeVerificar,
): void {
    $pendientes = array_filter(
        $documentos,
        static fn (array $d): bool => $d['verificado_por'] === null,
    );
    ?>

    <section>
        <p class="text-sm text-acero">
            Lo que el bot puede usar como apoyo. La regla que gobierna esta pantalla:
            <strong>ningún fragmento sin verificar entra al RAG</strong>. Cargar un
            documento no lo activa; lo activa la firma del abogado, que responde por la
            vigencia de la norma.
        </p>
    </section>

    <?php if ($pendientes !== [] && $puedeVerificar): ?>
    <section class="aviso aviso-atencion mt-6">
        <p class="font-semibold"><?= count($pendientes) ?> documento(s) esperando su verificación.</p>
        <p class="mt-1 text-sm">Hasta que los firme, el bot no los usa.</p>
    </section>
    <?php endif; ?>

    <?php /* ── Buscador de prueba ─────────────────────────────────────── */ ?>
    <section class="mt-8">
        <h2 class="rotulo">Buscador de prueba</h2>
        <p class="mt-2 text-sm text-acero">
            Mismo camino que usa el bot: lo que aparezca aquí es exactamente lo que el
            motor recuperaría para un mensaje igual.
        </p>

        <form method="get" action="/panel/conocimiento" class="mt-3 flex flex-wrap gap-2">
            <input type="search" name="q" value="<?= $e($consulta) ?>" class="campo"
                   style="max-width:28rem" placeholder="me decomisaron mercancía en el puerto…">
            <button type="submit" class="boton-secundario">Buscar</button>
        </form>

        <?php if ($consulta !== ''): ?>
            <?php if ($resultados === []): ?>
                <p class="mt-3 text-sm text-acero">
                    Nada recuperado. O no hay documentos verificados que hablen de esto,
                    o el texto no coincide léxicamente con ninguno.
                </p>
            <?php endif; ?>

            <?php foreach ($resultados as $r): ?>
            <article class="tarjeta mt-3 p-4">
                <p class="flex flex-wrap gap-x-3 text-xs text-acero">
                    <span class="font-mono"><?= $e($r['referencia'] !== '' ? $r['referencia'] : 'sin referencia') ?></span>
                    <span>similitud <?= number_format($r['similitud'], 3) ?></span>
                </p>
                <p class="mt-2 text-sm whitespace-pre-line"><?= $e($r['contenido']) ?></p>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php /* ── Documentos ─────────────────────────────────────────────── */ ?>
    <section class="mt-8">
        <h2 class="rotulo">Documentos</h2>

        <?php if ($documentos === []): ?>
            <p class="mt-3 text-sm text-acero">Todavía no hay documentos cargados.</p>
        <?php endif; ?>

        <div class="mt-3 overflow-x-auto">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Área</th>
                    <th>Fragmentos</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($documentos as $d): ?>
                <tr class="<?= (int) $d['vigente'] === 0 ? 'opacity-50' : '' ?>">
                    <td>
                        <span class="font-medium"><?= $e((string) $d['titulo']) ?></span>
                        <?php if (($d['referencia'] ?? null) !== null): ?>
                            <span class="ml-1 font-mono text-xs text-acero"><?= $e((string) $d['referencia']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm"><?= $e((string) $d['area']) ?></td>
                    <td class="text-sm">
                        <?= (int) $d['chunks'] ?>
                        <span class="text-xs text-acero">(<?= (int) $d['indexados'] ?> con vector)</span>
                    </td>
                    <td class="text-sm">
                        <?php if ((int) $d['vigente'] === 0): ?>
                            <span class="etiqueta">retirado</span>
                        <?php elseif ($d['verificado_por'] === null): ?>
                            <span class="etiqueta etiqueta-aviso">sin verificar</span>
                        <?php else: ?>
                            <span class="etiqueta etiqueta-ok" title="<?= $e((string) $d['verificado_en']) ?>">
                                ✓ <?= $e((string) $d['verificado_por']) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($puedeVerificar): ?>
                        <div class="flex flex-wrap gap-2">
                            <?php if ($d['verificado_por'] === null && (int) $d['vigente'] === 1): ?>
                            <form method="post" action="/panel/conocimiento/verificar">
                                <?= $ctx->csrf->campoOculto() ?>
                                <input type="hidden" name="id" value="<?= $e((string) $d['id']) ?>">
                                <button type="submit" class="boton">Verificar</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="/panel/conocimiento/vigencia">
                                <?= $ctx->csrf->campoOculto() ?>
                                <input type="hidden" name="id" value="<?= $e((string) $d['id']) ?>">
                                <button type="submit" class="boton-secundario">
                                    <?= (int) $d['vigente'] === 1 ? 'Retirar' : 'Reactivar' ?>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <?php /* ── Cargar ─────────────────────────────────────────────────── */ ?>
    <?php if ($puedeCargar): ?>
    <section class="mt-8">
        <details>
            <summary class="cursor-pointer text-sm font-medium">Cargar un documento</summary>

            <p class="mt-2 text-sm text-acero">
                El texto se trocea solo, por párrafos. Nace <strong>sin verificar</strong>:
                el bot no lo usará hasta la firma del abogado.
            </p>

            <form method="post" action="/panel/conocimiento" class="mt-3 grid gap-3 sm:grid-cols-2">
                <?= $ctx->csrf->campoOculto() ?>
                <div>
                    <label class="rotulo">Título</label>
                    <input name="titulo" class="campo" placeholder="Aprehensión por falta de declaración">
                </div>
                <div>
                    <label class="rotulo">Referencia (norma, concepto, sentencia)</label>
                    <input name="referencia" class="campo" placeholder="Decreto 1165 de 2019, art. …">
                </div>
                <div>
                    <label class="rotulo">Área</label>
                    <select name="area" class="campo">
                        <option value="aduanero">Aduanero</option>
                        <option value="tributario">Tributario</option>
                        <option value="ambos">Ambas</option>
                    </select>
                </div>
                <div>
                    <label class="rotulo">Tipo de caso (opcional, del catálogo)</label>
                    <input name="tipo_caso" class="campo font-mono" placeholder="aprehension_mercancia">
                </div>
                <div class="sm:col-span-2">
                    <label class="rotulo">Contenido</label>
                    <textarea name="contenido" rows="10" class="campo font-mono"
                              placeholder="Pegue aquí el escenario o el extracto normativo…"></textarea>
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="boton">Cargar y trocear</button>
                </div>
            </form>
        </details>
    </section>
    <?php endif; ?>

    <?php
};

require __DIR__ . '/_disposicion.php';
