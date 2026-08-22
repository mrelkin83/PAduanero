<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * Reparaciones. La tienda de tecnología cotiza, recibe el equipo y el cliente
 * pregunta por el avance — tres momentos distintos, tres métodos.
 */
interface SoportaServicioTecnico
{
    public function cotizarServicio(string $tipoEquipo, string $falla, array $datos = []): array;
    public function crearOrdenServicio(array $conversacion, array $datos): array;
    public function estadoOrdenServicio(string $numeroOrden): array;
}
