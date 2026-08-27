<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\BD;
use App\Repositorios\CompraCursoRepo;
use App\Repositorios\CompradorEnlaceRepo;
use App\Soporte\Smtp;
use App\Wa\ConexionCompartida;

/**
 * Confirma una compra pagada: marca el estado, avisa a Pedro por WhatsApp,
 * y le manda al comprador un enlace de un solo uso para completar su
 * registro (o iniciar sesión, si ya tiene cuenta — eso lo decide la
 * plantilla de `/mis-cursos/completar`, no esta clase).
 *
 * Llamada desde dos sitios: el webhook de Wompi (automático) y el "aprobar
 * a mano" del panel (respaldo si el webhook nunca llega) — por eso es su
 * propia clase y no vive dentro del webhook.
 */
final class ConfirmadorCompra
{
    private const MINUTOS_VIGENCIA_ENLACE = 60 * 48; // 48 horas

    public function __construct(
        private readonly CompraCursoRepo $compras,
        private readonly CompradorEnlaceRepo $enlaces,
        private readonly ConexionCompartida $conexion,
        private readonly BD $bd,
        private readonly ?Smtp $smtp,
        private readonly string $urlBase,
    ) {
    }

    public function confirmar(string $compraId): void
    {
        $compra = $this->compras->porId($compraId);

        // Ya confirmada (webhook duplicado, o aprobación manual después de
        // que el webhook ya llegó): no repetir el aviso ni el enlace.
        if ($compra === null || $compra['estado'] === 'pagada') {
            return;
        }

        $this->compras->marcarPagada($compraId);

        $stmt = $this->bd->pdo()->prepare('SELECT titulo FROM cursos WHERE id = ?');
        $stmt->execute([$compra['curso_id']]);
        $tituloCurso = (string) $stmt->fetchColumn();

        $this->conexion->avisarPedro(sprintf(
            'Nuevo pago de curso: %s (%s) compró "%s".',
            $compra['nombre'],
            $compra['correo'],
            $tituloCurso,
        ));

        $token = $this->enlaces->crear('completar_registro', null, $compraId, self::MINUTOS_VIGENCIA_ENLACE);
        $enlaceUrl = rtrim($this->urlBase, '/') . '/mis-cursos/completar?token=' . $token;

        if ($this->smtp !== null) {
            $this->smtp->enviar(
                (string) $compra['correo'],
                'Su acceso al curso: ' . $tituloCurso,
                "Hola {$compra['nombre']},\n\n"
                    . "Su pago del curso \"{$tituloCurso}\" fue confirmado.\n\n"
                    . "Complete su registro (o inicie sesión si ya tiene cuenta) en este enlace:\n{$enlaceUrl}\n\n"
                    . "Este enlace es válido por 48 horas.\n",
            );
        }
    }

    /**
     * Reenvía el enlace de acceso de una compra YA pagada — para cuando el
     * correo original no llegó (SMTP caído en ese momento, fue a spam) y
     * nadie completó el registro todavía. No repite el aviso de WhatsApp a
     * Pedro: eso ya ocurrió cuando se confirmó el pago la primera vez.
     */
    public function reenviarAcceso(string $compraId): bool
    {
        $compra = $this->compras->porId($compraId);

        if ($compra === null || $compra['estado'] !== 'pagada' || $compra['comprador_id'] !== null) {
            return false;
        }

        if ($this->smtp === null) {
            return false;
        }

        $stmt = $this->bd->pdo()->prepare('SELECT titulo FROM cursos WHERE id = ?');
        $stmt->execute([$compra['curso_id']]);
        $tituloCurso = (string) $stmt->fetchColumn();

        $token = $this->enlaces->crear('completar_registro', null, $compraId, self::MINUTOS_VIGENCIA_ENLACE);
        $enlaceUrl = rtrim($this->urlBase, '/') . '/mis-cursos/completar?token=' . $token;

        return $this->smtp->enviar(
            (string) $compra['correo'],
            'Su acceso al curso: ' . $tituloCurso,
            "Hola {$compra['nombre']},\n\n"
                . "Le reenviamos el acceso al curso \"{$tituloCurso}\".\n\n"
                . "Complete su registro (o inicie sesión si ya tiene cuenta) en este enlace:\n{$enlaceUrl}\n\n"
                . "Este enlace es válido por 48 horas.\n",
        );
    }
}
