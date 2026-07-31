<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $modalidades
 * @var list<array<string,mixed>> $horarios
 * @var list<array<string,mixed>> $reservasVivas
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Agenda y tarifas';

$dias = [0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles',
         4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

$contenido = static function () use ($e, $ctx, $modalidades, $horarios, $reservasVivas, $dias): void {
    $editable = $ctx->puede('agenda.editar');
    ?>

    <section>
        <h2 class="rotulo">Modalidades de asesoría</h2>
        <p class="mt-2 text-sm text-acero">
            El precio va en <strong>pesos enteros</strong>. Al cambiarlo, las reservas
            ya creadas conservan el suyo: cada consulta guarda su precio congelado
            al reservar.
        </p>

        <?php foreach ($modalidades as $m): ?>
        <form method="post" action="/panel/tarifas" class="tarjeta mt-4 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="rotulo">Nombre</label>
                    <input name="nombre" value="<?= $e((string) $m['nombre']) ?>"
                           class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
                </div>

                <div class="sm:col-span-2">
                    <label class="rotulo">Descripción</label>
                    <textarea name="descripcion" rows="2" class="campo mt-1"
                              <?= $editable ? '' : 'disabled' ?>><?= $e((string) $m['descripcion']) ?></textarea>
                </div>

                <div>
                    <label class="rotulo">Precio (pesos)</label>
                    <input name="precio_cop" type="number" min="0" step="1000"
                           value="<?= $e((string) $m['precio_cop']) ?>"
                           class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
                </div>

                <div>
                    <label class="rotulo">Duración (minutos)</label>
                    <input name="duracion_min" type="number" min="15" max="480"
                           value="<?= $e((string) $m['duracion_min']) ?>"
                           class="campo mt-1 font-mono" <?= $editable ? '' : 'disabled' ?>>
                </div>

                <div>
                    <label class="rotulo">Modalidad</label>
                    <select name="modalidad" class="campo mt-1" <?= $editable ? '' : 'disabled' ?>>
                        <?php foreach (['virtual', 'presencial', 'telefonica'] as $opcion): ?>
                            <option value="<?= $e($opcion) ?>" <?= $m['modalidad'] === $opcion ? 'selected' : '' ?>>
                                <?= $e(ucfirst($opcion)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-end gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="requiere_pago" value="1"
                               <?= (int) $m['requiere_pago'] === 1 ? 'checked' : '' ?>
                               <?= $editable ? '' : 'disabled' ?>>
                        Requiere pago
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="activo" value="1"
                               <?= (int) $m['activo'] === 1 ? 'checked' : '' ?>
                               <?= $editable ? '' : 'disabled' ?>>
                        Activa
                    </label>
                </div>
            </div>

            <?php if ($editable): ?>
                <button type="submit" class="boton mt-4">Guardar modalidad</button>
            <?php endif; ?>
        </form>
        <?php endforeach; ?>
    </section>

    <?php if ($reservasVivas !== []): ?>
    <section class="mt-8">
        <h2 class="rotulo">Reservas vivas y su precio congelado</h2>
        <table class="tabla mt-3">
            <thead><tr><th>Consultas</th><th>Precio congelado</th></tr></thead>
            <tbody>
            <?php foreach ($reservasVivas as $r): ?>
                <tr>
                    <td><?= $e((string) $r['n']) ?></td>
                    <td class="font-mono">$<?= $e(number_format((int) $r['precio_cop'], 0, ',', '.')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <section class="mt-8">
        <h2 class="rotulo">Horarios de atención</h2>
        <table class="tabla mt-3">
            <thead><tr><th>Día</th><th>Desde</th><th>Hasta</th><th>Activo</th></tr></thead>
            <tbody>
            <?php foreach ($horarios as $h): ?>
                <tr>
                    <td><?= $e($dias[(int) $h['dia_semana']] ?? '?') ?></td>
                    <td class="font-mono"><?= $e(substr((string) $h['hora_inicio'], 0, 5)) ?></td>
                    <td class="font-mono"><?= $e(substr((string) $h['hora_fin'], 0, 5)) ?></td>
                    <td><?= (int) $h['activo'] === 1 ? 'Sí' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="mt-2 text-sm text-acero">
            La edición de horarios y bloqueos llega con la agenda de la Etapa 5,
            que es cuando empiezan a usarse para calcular cupos.
        </p>
    </section>

<?php };

require __DIR__ . '/_disposicion.php';
