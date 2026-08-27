<?php

declare(strict_types=1);

namespace App\Cuenta;

use App\Core\Peticion;
use App\Core\Respuesta;
use App\Repositorios\CompraCursoRepo;
use App\Servicios\Cursos;
use ElkinLinan\WhatsappAiEngine\Payments\PaymentAdapterInterface;

/**
 * Checkout público de un curso. Depende de `PaymentAdapterInterface`, no de
 * `ConexionCompartida` en concreto — así una prueba inyecta un adaptador
 * falso sin tocar `wa_config` ni la red.
 */
final class ComprasControlador
{
    public function __construct(
        private readonly Cursos $cursos,
        private readonly CompraCursoRepo $compras,
        private readonly ?PaymentAdapterInterface $wompi,
        private readonly string $urlBase,
    ) {
    }

    public function formulario(Peticion $peticion, string $slug): Respuesta
    {
        $curso = $this->cursoComprable($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        return Respuesta::vista('cuenta/comprar', [
            'curso' => $curso,
            'error' => $peticion->consulta['error'] ?? null,
        ]);
    }

    public function procesar(Peticion $peticion, string $slug): Respuesta
    {
        $curso = $this->cursoComprable($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        $nombre = trim((string) ($peticion->formulario['nombre'] ?? ''));
        $correo = trim((string) ($peticion->formulario['correo'] ?? ''));

        if ($nombre === '' || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirigirAlFormulario($slug, 'Escriba su nombre y un correo válido.');
        }

        $compraId = $this->compras->crear($curso['id'], $nombre, $correo, (int) $curso['precio_cop']);

        if ($this->wompi === null) {
            $this->compras->marcarFallida($compraId);

            return $this->redirigirAlFormulario($slug, 'El cobro no está disponible en este momento. Intente más tarde.');
        }

        $redirectUrl = rtrim($this->urlBase, '/') . "/cursos/{$slug}/gracias?compra={$compraId}";

        $resultado = $this->wompi->crearCobro(
            (float) $curso['precio_cop'],
            $compraId,
            'Curso: ' . (string) $curso['titulo'],
            ['nombre' => $nombre],
            $redirectUrl,
        );

        if (!$resultado['ok']) {
            $this->compras->marcarFallida($compraId);

            return $this->redirigirAlFormulario($slug, 'No se pudo generar el cobro. Intente de nuevo.');
        }

        $this->compras->guardarReferencia($compraId, $resultado['referencia'], $resultado['externo_id'] ?? null);

        return new Respuesta('', 302, ['Location' => $resultado['enlace']]);
    }

    public function gracias(Peticion $peticion, string $slug): Respuesta
    {
        $curso = $this->cursos->porSlug($slug);

        if ($curso === null) {
            return Respuesta::texto('Curso no encontrado.', 404);
        }

        $compraId = (string) ($peticion->consulta['compra'] ?? '');
        $compra = $compraId !== '' ? $this->compras->porId($compraId) : null;

        $estadoMostrado = 'desconocido';

        if ($compra !== null) {
            $estadoMostrado = (string) $compra['estado'];

            // Solo informativo: nunca escribe nada. La única fuente de
            // verdad de que un pago ocurrió es el webhook (Task 9).
            if ($estadoMostrado === 'pendiente' && $this->wompi !== null && $compra['referencia_wompi'] !== null) {
                $consulta = $this->wompi->consultar((string) $compra['referencia_wompi']);
                if ($consulta['ok']) {
                    $estadoMostrado = $consulta['estado'];
                }
            }
        }

        return Respuesta::vista('cuenta/gracias', [
            'curso' => $curso,
            'estadoMostrado' => $estadoMostrado,
        ]);
    }

    /** @return array<string,mixed>|null solo cursos publicados son comprables */
    private function cursoComprable(string $slug): ?array
    {
        $curso = $this->cursos->porSlug($slug);

        return ($curso !== null && $curso['estado'] === 'publicado') ? $curso : null;
    }

    private function redirigirAlFormulario(string $slug, string $error): Respuesta
    {
        return new Respuesta('', 302, [
            'Location' => "/cursos/{$slug}/comprar?" . http_build_query(['error' => $error]),
        ]);
    }
}
