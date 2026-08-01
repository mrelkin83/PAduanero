<?php

declare(strict_types=1);

namespace App\Modelos;

/**
 * Una persona que escribió.
 *
 * **El NIT no está aquí y es a propósito.** `contactos.nit_cifrado` se guarda
 * cifrado (regla 13) y este objeto viaja por todo el motor: al prompt, a los
 * registros, a las vistas del panel. Meterlo dentro significaría descifrarlo
 * en cada lectura y confiar en que nadie lo serialice por error. En su lugar
 * hay `tieneNit`, que es lo que casi siempre se necesita saber, y
 * `ContactoRepo::nit()`, que descifra bajo petición explícita y deja huella.
 */
final readonly class Contacto
{
    public function __construct(
        public string $id,
        public string $telefono,
        public ?int $chatwootContactId,
        public ?string $nombre,
        public ?string $tipoPersona,
        public ?string $razonSocial,
        public bool $tieneNit,
        public ?string $ciudad,
        public string $canalOrigen,
        public ?string $utmSource,
        public ?string $utmCampaign,
        public bool $bloqueado,
        public string $creadoEn,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (string) $fila['id'],
            telefono: (string) $fila['telefono'],
            chatwootContactId: $fila['chatwoot_contact_id'] !== null
                ? (int) $fila['chatwoot_contact_id']
                : null,
            nombre: $fila['nombre'] !== null ? (string) $fila['nombre'] : null,
            tipoPersona: $fila['tipo_persona'] !== null ? (string) $fila['tipo_persona'] : null,
            razonSocial: $fila['razon_social'] !== null ? (string) $fila['razon_social'] : null,
            tieneNit: ($fila['nit_cifrado'] ?? null) !== null,
            ciudad: $fila['ciudad'] !== null ? (string) $fila['ciudad'] : null,
            canalOrigen: (string) $fila['canal_origen'],
            utmSource: $fila['utm_source'] !== null ? (string) $fila['utm_source'] : null,
            utmCampaign: $fila['utm_campaign'] !== null ? (string) $fila['utm_campaign'] : null,
            bloqueado: (bool) $fila['bloqueado'],
            creadoEn: (string) $fila['creado_en'],
        );
    }

    /** Cómo dirigirse a esta persona sin inventarse un nombre que no dio. */
    public function tratamiento(): string
    {
        if ($this->nombre !== null && trim($this->nombre) !== '') {
            return trim($this->nombre);
        }

        return $this->razonSocial !== null && trim($this->razonSocial) !== ''
            ? trim($this->razonSocial)
            : '';
    }
}
