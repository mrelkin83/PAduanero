<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Soporte\EnviadorCorreo;

/**
 * Cola de correos: el sitio ENCOLA y el cron ENVÍA (bin/enviar-correos.php),
 * reutilizando App\Soporte\Smtp. Desacopla el envío de la petición web y le
 * da reintentos — antes un SMTP lento o caído durante una compra perdía el
 * correo de acceso o bloqueaba la respuesta.
 *
 * `encolar()` nunca lanza: si la inserción falla, el flujo de negocio (una
 * compra, una cita) no debe romperse por un correo.
 */
final class ColaCorreos
{
    /** Un correo no se reintenta indefinidamente: 3 strikes y queda 'fallido'. */
    public const MAX_INTENTOS = 3;

    /** Buzón del despacho: a él llegan los avisos internos (nueva compra, cita). */
    public const CORREO_DESPACHO = 'info@pedroabogadoaduanero.com';

    public function __construct(private readonly BD $bd)
    {
    }

    /** Deja un correo HTML listo para que el cron lo envíe. Devuelve el id o null. */
    public function encolar(string $destinatario, string $asunto, string $cuerpoHtml, string $tipo = 'general', ?string $cuerpoTexto = null): ?int
    {
        $destinatario = trim($destinatario);
        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        try {
            $this->bd->pdo()->prepare(
                'INSERT INTO correos_cola (destinatario, asunto, cuerpo_html, cuerpo_texto, tipo)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$destinatario, mb_substr($asunto, 0, 255), $cuerpoHtml, $cuerpoTexto, mb_substr($tipo, 0, 40)]);

            return (int) $this->bd->pdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Envía los pendientes (los que no agotaron los reintentos). Devuelve el
     * conteo {enviados, fallidos}. Sin SMTP configurado no toca la cola: los
     * correos esperan a que lo esté.
     *
     * @return array{enviados:int,fallidos:int,pendientes_sin_smtp:int}
     */
    public function procesarPendientes(?EnviadorCorreo $smtp, int $limite = 50): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT * FROM correos_cola WHERE estado = ? AND intentos < ? ORDER BY id ASC LIMIT ' . max(1, $limite)
        );
        $stmt->execute(['pendiente', self::MAX_INTENTOS]);
        $pendientes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($pendientes === []) {
            return ['enviados' => 0, 'fallidos' => 0, 'pendientes_sin_smtp' => 0];
        }
        if ($smtp === null) {
            return ['enviados' => 0, 'fallidos' => 0, 'pendientes_sin_smtp' => count($pendientes)];
        }

        $enviados = 0;
        $fallidos = 0;
        foreach ($pendientes as $c) {
            $ok = $smtp->enviarHtml(
                (string) $c['destinatario'],
                (string) $c['asunto'],
                (string) $c['cuerpo_html'],
                $c['cuerpo_texto'] !== null ? (string) $c['cuerpo_texto'] : null,
            );
            $intentos = (int) $c['intentos'] + 1;

            if ($ok) {
                $this->bd->pdo()->prepare(
                    "UPDATE correos_cola SET estado = 'enviado', intentos = ?, enviado_en = NOW(), ultimo_error = NULL WHERE id = ?"
                )->execute([$intentos, $c['id']]);
                $enviados++;
            } else {
                // 'fallido' solo cuando se agotan los reintentos; si no, vuelve
                // a 'pendiente' para el siguiente tick del cron.
                $estado = $intentos >= self::MAX_INTENTOS ? 'fallido' : 'pendiente';
                $this->bd->pdo()->prepare(
                    'UPDATE correos_cola SET estado = ?, intentos = ?, ultimo_error = ? WHERE id = ?'
                )->execute([$estado, $intentos, 'El envío SMTP no fue aceptado.', $c['id']]);
                $fallidos++;
            }
        }

        return ['enviados' => $enviados, 'fallidos' => $fallidos, 'pendientes_sin_smtp' => 0];
    }

    /** Vuelve a poner un correo en cola (reintento manual desde el panel). */
    public function reintentar(int $id): bool
    {
        $stmt = $this->bd->pdo()->prepare(
            "UPDATE correos_cola SET estado = 'pendiente', intentos = 0, ultimo_error = NULL WHERE id = ?"
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /** Últimos correos para la bitácora del panel. */
    public function ultimos(int $limite = 100): array
    {
        return $this->bd->pdo()->query(
            'SELECT id, destinatario, asunto, tipo, estado, intentos, ultimo_error, creado_en, enviado_en
               FROM correos_cola ORDER BY id DESC LIMIT ' . max(1, $limite)
        )->fetchAll(\PDO::FETCH_ASSOC);
    }
}
