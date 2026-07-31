<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;

/**
 * Agenda y tarifas.
 *
 * «El precio se define aquí, nunca en código» (docs/PANEL_ADMIN.md §2.3). Y
 * al cambiarlo, **las reservas ya creadas conservan el suyo**: `consultas`
 * tiene su propia columna `precio_cop`, congelada al reservar. Cambiar la
 * tarifa no toca ninguna consulta viva, y esa es justamente la razón de que
 * ese precio esté duplicado en dos tablas.
 *
 * El precio va en PESOS enteros (ADR-010). La conversión a centavos ocurre
 * solo en `Pagos::crearLink()`.
 */
final class TarifasControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }

    public function listar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'agenda.ver');

        $modalidades = $this->bd->pdo()->query(
            'SELECT * FROM modalidades_asesoria ORDER BY orden, nombre'
        )->fetchAll();

        $horarios = $this->bd->pdo()->query(
            'SELECT * FROM horarios ORDER BY dia_semana, hora_inicio'
        )->fetchAll();

        // Reservas vivas: se enseña cuántas hay y a qué precio, para que
        // quede claro que subir la tarifa no las toca.
        $vivas = $this->bd->pdo()->query(
            "SELECT COUNT(*) AS n, precio_cop FROM consultas
              WHERE estado IN ('reservada','pagada') GROUP BY precio_cop"
        )->fetchAll();

        return $this->vista('panel/tarifas', [
            'ctx' => $ctx,
            'modalidades' => $modalidades,
            'horarios' => $horarios,
            'reservasVivas' => $vivas,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'agenda.editar');

        $id = $ctx->campo('id');
        $precio = $ctx->campo('precio_cop');
        $duracion = $ctx->campo('duracion_min');

        if (preg_match('/^\d+$/', $precio) !== 1 || preg_match('/^\d+$/', $duracion) !== 1) {
            return $this->redirigirCon('/panel/tarifas', 'error', 'El precio y la duración deben ser números enteros.');
        }

        $precio = (int) $precio;
        $duracion = (int) $duracion;

        // Guarda contra teclear centavos donde van pesos.
        //
        // El umbral es 10 millones, no 50: el error que hay que atrapar es
        // exactamente ×100 sobre la tarifa real, y $400.000 × 100 son
        // $40.000.000 — que con un tope de 50 millones pasaba de largo. Una
        // asesoría de una hora por encima de diez millones no existe, así que
        // cualquier cosa así es un error de dedo o de unidades.
        //
        // El error es fácil justo porque la pasarela SÍ cobra en centavos
        // (ADR-010): la conversión existe, solo que vive en Pagos::crearLink()
        // y no aquí.
        if ($precio >= 10_000_000) {
            return $this->redirigirCon(
                '/panel/tarifas',
                'error',
                'El precio va en PESOS, no en centavos. ¿Seguro que son $'
                    . number_format($precio, 0, ',', '.') . '? '
                    . 'Para $400.000 se escribe 400000.',
            );
        }

        if ($duracion < 15 || $duracion > 480) {
            return $this->redirigirCon('/panel/tarifas', 'error', 'La duración debe estar entre 15 y 480 minutos.');
        }

        $stmt = $this->bd->pdo()->prepare('SELECT * FROM modalidades_asesoria WHERE id = ?');
        $stmt->execute([$id]);
        $antes = $stmt->fetch();

        if ($antes === false) {
            return $this->redirigirCon('/panel/tarifas', 'error', 'Esa modalidad no existe.');
        }

        $this->bd->pdo()->prepare(
            'UPDATE modalidades_asesoria
                SET nombre = ?, descripcion = ?, duracion_min = ?, precio_cop = ?,
                    modalidad = ?, requiere_pago = ?, activo = ?
              WHERE id = ?'
        )->execute([
            $ctx->campo('nombre', (string) $antes['nombre']),
            $ctx->campo('descripcion'),
            $duracion,
            $precio,
            in_array($ctx->campo('modalidad'), ['virtual', 'presencial', 'telefonica'], true)
                ? $ctx->campo('modalidad')
                : $antes['modalidad'],
            (int) ($ctx->campo('requiere_pago') === '1'),
            (int) ($ctx->campo('activo') === '1'),
            $id,
        ]);

        $this->auditoria->registrar('modalidad', $id, 'actualizar', $ctx->actor(), [
            'precio_antes' => (int) $antes['precio_cop'],
            'precio_despues' => $precio,
            'duracion_antes' => (int) $antes['duracion_min'],
            'duracion_despues' => $duracion,
        ], $ctx->ip());

        $mensaje = 'Modalidad actualizada.';

        if ((int) $antes['precio_cop'] !== $precio) {
            $mensaje .= ' Las reservas ya creadas conservan su precio anterior.';
        }

        return $this->redirigirCon('/panel/tarifas', 'ok', $mensaje);
    }
}
