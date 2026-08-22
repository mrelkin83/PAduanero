<?php

declare(strict_types=1);

use App\Soporte\Fechas;
use App\Soporte\Vista;

/**
 * Citas agendadas por el bot.
 *
 * @var \App\Panel\Contexto $ctx
 * @var list<array<string,mixed>> $citas
 * @var array{ok:string,error:string} $avisos
 */

$e = Vista::e(...);
$titulo = 'Citas de WhatsApp';

$estados = ['reservada' => 'Reservada', 'confirmada' => 'Confirmada', 'cancelada' => 'Cancelada'];

$pagos = [
    'PAYMENT_PENDING' => 'pendiente', 'PAYMENT_INITIATED' => 'iniciado',
    'PAYMENT_SCREENSHOT_RECEIVED' => 'comprobante recibido', 'PAYMENT_VALIDATING' => 'validando',
    'PAYMENT_VERIFIED' => 'verificado', 'PAYMENT_REJECTED' => 'rechazado',
    'PAYMENT_REVIEW_REQUIRED' => 'requiere revisión', 'PAYMENT_EXPIRED' => 'expirado',
    'PAYMENT_REFUNDED' => 'reembolsado',
];

$contenido = static function () use ($e, $ctx, $citas, $estados, $pagos): void { ?>

    <p class="text-sm text-acero">
        Las últimas 200. La hora es de Bogotá. Una cita <strong>reservada</strong> tiene la
        franja tomada pero espera el pago; al verificarse pasa a <strong>confirmada</strong>
        y se crea el evento en el calendario.
        <a class="underline" href="/panel/whatsapp">Volver a la configuración</a>.
    </p>

    <?php if ($citas === []): ?>
        <p class="tarjeta mt-4 p-4 text-sm">Todavía no hay citas agendadas por el bot.</p>
    <?php else: ?>
    <div class="mt-4 overflow-x-auto">
        <table class="tabla">
            <thead>
                <tr><th>Fecha y hora</th><th>Cliente</th><th>Teléfono</th><th>Servicio</th>
                    <th>Estado</th><th>Pago</th><th>Precio</th><th>Meet</th></tr>
            </thead>
            <tbody>
            <?php foreach ($citas as $c): ?>
                <tr>
                    <td class="font-mono whitespace-nowrap">
                        <?= $e(Fechas::fechaNatural(substr((string) $c['inicio'], 0, 10))) ?>
                        · <?= $e(substr((string) $c['inicio'], 11, 5)) ?>
                    </td>
                    <td><?= $e((string) $c['nombre']) ?>
                        <?php if (!empty($c['correo'])): ?>
                            <span class="block text-xs text-acero"><?= $e((string) $c['correo']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="font-mono"><?= $e((string) $c['telefono']) ?></td>
                    <td><?= $e((string) ($c['servicio'] ?? '')) ?>
                        <?php if (!empty($c['motivo'])): ?>
                            <span class="block text-xs text-acero"><?= $e(mb_substr((string) $c['motivo'], 0, 80)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $e($estados[$c['estado']] ?? (string) $c['estado']) ?></td>
                    <td><?= $e($pagos[$c['estado_pago'] ?? ''] ?? '—') ?></td>
                    <td class="font-mono">$<?= $e(number_format((int) $c['precio_cop'], 0, ',', '.')) ?></td>
                    <td>
                        <?php if (!empty($c['gcal_meet_url'])): ?>
                            <a class="underline" href="<?= $e((string) $c['gcal_meet_url']) ?>" target="_blank" rel="noopener">enlace</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php };

require __DIR__ . '/_disposicion.php';
