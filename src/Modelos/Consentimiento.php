<?php

declare(strict_types=1);

namespace App\Modelos;

/**
 * Habeas data (Ley 1581 de 2012).
 *
 * Se guarda el **texto exacto** que se le mostró a la persona, no una
 * referencia a la política vigente. Cuando el aviso cambie —y va a cambiar—,
 * lo que hay que poder demostrar dentro de dos años es qué decía el día que
 * esta persona dijo que sí, no qué dice hoy.
 *
 * `otorgado` puede ser falso: una negativa también se registra. Sin la fila,
 * el motor no sabría distinguir «dijo que no» de «todavía no le he
 * preguntado», y volvería a preguntar a alguien que ya se negó.
 */
final readonly class Consentimiento
{
    /** @param array<string,mixed> $evidencia */
    public function __construct(
        public string $id,
        public string $contactoId,
        public string $versionPolitica,
        public string $textoMostrado,
        public bool $otorgado,
        public array $evidencia,
        public string $otorgadoEn,
        public ?string $revocadoEn,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        $evidencia = $fila['evidencia'] ?? null;

        if (is_string($evidencia)) {
            $evidencia = json_decode($evidencia, true);
        }

        return new self(
            id: (string) $fila['id'],
            contactoId: (string) $fila['contacto_id'],
            versionPolitica: (string) $fila['version_politica'],
            textoMostrado: (string) $fila['texto_mostrado'],
            otorgado: (bool) $fila['otorgado'],
            evidencia: is_array($evidencia) ? $evidencia : [],
            otorgadoEn: (string) $fila['otorgado_en'],
            revocadoEn: $fila['revocado_en'] !== null ? (string) $fila['revocado_en'] : null,
        );
    }

    /** Vigente: dijo que sí y no lo ha revocado. */
    public function vigente(): bool
    {
        return $this->otorgado && $this->revocadoEn === null;
    }
}
