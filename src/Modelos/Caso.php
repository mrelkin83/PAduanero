<?php

declare(strict_types=1);

namespace App\Modelos;

/**
 * Un asunto jurídico concreto.
 *
 * `puntajeLead` está aquí porque el panel lo usa para ordenar la bandeja, y
 * **no debe salir nunca hacia el contacto** (`CLAUDE.md` §3.2). Quien componga
 * un mensaje a partir de este objeto tiene que saltárselo a mano; no hay
 * forma de que el tipo lo impida, así que queda dicho aquí y probado en el
 * conjunto dorado.
 */
final readonly class Caso
{
    public function __construct(
        public string $id,
        public string $contactoId,
        public ?string $radicadoInterno,
        public string $area,
        public string $tipoCaso,
        public ?string $entidad,
        public ?string $seccional,
        public string $urgencia,
        public ?bool $tieneActoAdmin,
        public ?string $fechaActo,
        public ?string $numeroActo,
        public ?int $valorEstimadoCop,
        public ?string $descripcionCliente,
        public ?string $resumenMotor,
        public int $puntajeLead,
        public string $estado,
        public ?string $motivoDescarte,
        public bool $requiereRevision,
        public string $creadoEn,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (string) $fila['id'],
            contactoId: (string) $fila['contacto_id'],
            radicadoInterno: $fila['radicado_interno'] !== null
                ? (string) $fila['radicado_interno']
                : null,
            area: (string) $fila['area'],
            tipoCaso: (string) $fila['tipo_caso'],
            entidad: $fila['entidad'] !== null ? (string) $fila['entidad'] : null,
            seccional: $fila['seccional'] !== null ? (string) $fila['seccional'] : null,
            urgencia: (string) $fila['urgencia'],
            tieneActoAdmin: $fila['tiene_acto_admin'] !== null
                ? (bool) $fila['tiene_acto_admin']
                : null,
            fechaActo: $fila['fecha_acto'] !== null ? (string) $fila['fecha_acto'] : null,
            numeroActo: $fila['numero_acto'] !== null ? (string) $fila['numero_acto'] : null,
            valorEstimadoCop: $fila['valor_estimado_cop'] !== null
                ? (int) $fila['valor_estimado_cop']
                : null,
            descripcionCliente: $fila['descripcion_cliente'] !== null
                ? (string) $fila['descripcion_cliente']
                : null,
            resumenMotor: $fila['resumen_motor'] !== null ? (string) $fila['resumen_motor'] : null,
            puntajeLead: (int) $fila['puntaje_lead'],
            estado: (string) $fila['estado'],
            motivoDescarte: $fila['motivo_descarte'] !== null
                ? (string) $fila['motivo_descarte']
                : null,
            requiereRevision: (bool) $fila['requiere_revision'],
            creadoEn: (string) $fila['creado_en'],
        );
    }
}
