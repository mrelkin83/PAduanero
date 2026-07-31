<?php

declare(strict_types=1);

use App\Soporte\Fechas;
use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var array{entidad:string,accion:string} $filtros
 * @var list<string> $entidades
 * @var list<string> $acciones
 * @var list<array<string,mixed>> $registros
 * @var list<array<string,mixed>> $historialConfig
 * @var int $pagina
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Bitácora';

$contenido = static function () use ($e, $filtros, $entidades, $acciones, $registros, $historialConfig, $pagina): void { ?>

    <form method="get" action="/panel/auditoria" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="rotulo">Entidad</label>
            <select name="entidad" class="campo mt-1 w-44">
                <option value="">Todas</option>
                <?php foreach ($entidades as $valor): ?>
                    <option value="<?= $e($valor) ?>" <?= $filtros['entidad'] === $valor ? 'selected' : '' ?>>
                        <?= $e($valor) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="rotulo">Acción</label>
            <select name="accion" class="campo mt-1 w-44">
                <option value="">Todas</option>
                <?php foreach ($acciones as $valor): ?>
                    <option value="<?= $e($valor) ?>" <?= $filtros['accion'] === $valor ? 'selected' : '' ?>>
                        <?= $e($valor) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="boton boton-secundario">Filtrar</button>
    </form>

    <table class="tabla mt-5">
        <thead>
            <tr><th>Cuándo (UTC)</th><th>Actor</th><th>Entidad</th><th>Acción</th><th>Detalle</th></tr>
        </thead>
        <tbody>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td class="whitespace-nowrap font-mono text-xs"><?= $e((string) $r['creado_en']) ?></td>
                <td class="text-xs"><?= $e((string) $r['actor']) ?></td>
                <td class="font-mono text-xs"><?= $e((string) $r['entidad']) ?></td>
                <td class="font-mono text-xs"><?= $e((string) $r['accion']) ?></td>
                <td class="font-mono text-xs text-acero"><?= $e((string) ($r['detalle'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($registros === []): ?>
            <tr><td colspan="5" class="text-acero">Sin registros con esos filtros.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if (count($registros) >= 50): ?>
        <a href="?pagina=<?= $pagina + 1 ?>" class="mt-3 inline-block text-sm underline">Página siguiente</a>
    <?php endif; ?>

    <section class="mt-10">
        <h2 class="rotulo">Cambios de configuración</h2>
        <table class="tabla mt-3">
            <thead>
                <tr><th>Cuándo (UTC)</th><th>Clave</th><th>Antes</th><th>Después</th><th>Quién</th><th>Motivo</th></tr>
            </thead>
            <tbody>
            <?php foreach ($historialConfig as $h): ?>
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs"><?= $e((string) $h['creado_en']) ?></td>
                    <td class="font-mono text-xs"><?= $e((string) $h['clave']) ?></td>
                    <td class="font-mono text-xs text-acero"><?= $e((string) ($h['valor_anterior'] ?? '')) ?></td>
                    <td class="font-mono text-xs"><?= $e((string) $h['valor_nuevo']) ?></td>
                    <td class="text-xs"><?= $e((string) ($h['usuario_nombre'] ?? '—')) ?></td>
                    <td class="text-xs"><?= $e((string) ($h['motivo'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($historialConfig === []): ?>
                <tr><td colspan="6" class="text-acero">Todavía no se ha cambiado ninguna configuración.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php };

require __DIR__ . '/_disposicion.php';
