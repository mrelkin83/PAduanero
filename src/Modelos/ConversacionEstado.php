<?php

declare(strict_types=1);

namespace App\Modelos;

use App\Motor\Estados;
use App\Soporte\Fechas;

/**
 * Dónde va una conversación y si la IA puede hablar en ella.
 *
 * `iaActiva` es la regla 8 hecha columna: cuando un humano toma la
 * conversación, la IA queda apagada hasta reactivación explícita. No hay
 * ningún camino en el motor que la vuelva a encender sola.
 *
 * `historial` guarda los últimos turnos y `resumenLargo` lo compacta cuando
 * crecen. El motor de referencia truncaba a 10 turnos sin resumir, y en un
 * caso jurídico eso pierde el contexto que importa (`CLAUDE.md` §7.8).
 */
final readonly class ConversacionEstado
{
    /**
     * @param list<array{role:string,content:string}> $historial
     * @param list<string>                            $buffer
     */
    public function __construct(
        public string $id,
        public int $chatwootConvId,
        public ?string $contactoId,
        public ?string $casoId,
        public ?string $promptVersionId,
        public string $estado,
        public bool $iaActiva,
        public ?string $pausadaHasta,
        public array $historial,
        public ?string $resumenLargo,
        public array $buffer,
        public ?string $bufferHasta,
        public int $turnos,
        public int $tokensConsumidos,
        public ?string $ultimoMensajeEn,
    ) {
    }

    /** @param array<string,mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (string) $fila['id'],
            chatwootConvId: (int) $fila['chatwoot_conv_id'],
            contactoId: $fila['contacto_id'] !== null ? (string) $fila['contacto_id'] : null,
            casoId: $fila['caso_id'] !== null ? (string) $fila['caso_id'] : null,
            promptVersionId: $fila['prompt_version_id'] !== null
                ? (string) $fila['prompt_version_id']
                : null,
            estado: (string) $fila['estado'],
            iaActiva: (bool) $fila['ia_activa'],
            pausadaHasta: $fila['pausada_hasta'] !== null ? (string) $fila['pausada_hasta'] : null,
            historial: self::listaJson($fila['historial'] ?? null),
            resumenLargo: $fila['resumen_largo'] !== null ? (string) $fila['resumen_largo'] : null,
            buffer: array_values(array_filter(
                self::listaJson($fila['buffer_mensajes'] ?? null),
                static fn (mixed $v): bool => is_string($v),
            )),
            bufferHasta: $fila['buffer_hasta'] !== null ? (string) $fila['buffer_hasta'] : null,
            turnos: (int) $fila['turnos'],
            tokensConsumidos: (int) $fila['tokens_consumidos'],
            ultimoMensajeEn: $fila['ultimo_mensaje_en'] !== null
                ? (string) $fila['ultimo_mensaje_en']
                : null,
        );
    }

    public function nodo(): Estados
    {
        return Estados::desde($this->estado);
    }

    /**
     * Si la IA puede responder AHORA.
     *
     * Tres condiciones y las tres tienen que valer. El kill switch global
     * (`motor_ia_pausado`, regla 9) no se consulta aquí porque no es estado de
     * esta conversación: lo mira el motor antes de llegar a preguntar esto.
     *
     * `pausada_hasta` llega **en UTC**: la conexión fija `time_zone = '+00:00'`
     * y la aplicación convierte a Bogotá al presentar. Interpretarlo con
     * `strtotime()` a secas lo leería en la zona por defecto de PHP y erraría
     * en cinco horas — hacia el lado peligroso, además: la pausa parecería
     * haber vencido cuando no. Por eso va por `Fechas::deUtc()` y la
     * comparación es entre objetos, que es absoluta y no depende de zonas.
     */
    public function puedeResponderIa(?string $ahora = null): bool
    {
        if (!$this->iaActiva || !$this->nodo()->admiteIa()) {
            return false;
        }

        if ($this->pausadaHasta === null) {
            return true;
        }

        $momento = $ahora === null
            ? Fechas::ahora()
            : new \DateTimeImmutable($ahora, Fechas::zona());

        return Fechas::deUtc($this->pausadaHasta) <= $momento;
    }

    /** @return list<mixed> */
    private static function listaJson(mixed $crudo): array
    {
        if (is_array($crudo)) {
            return array_values($crudo);
        }

        if (!is_string($crudo) || $crudo === '') {
            return [];
        }

        $datos = json_decode($crudo, true);

        return is_array($datos) ? array_values($datos) : [];
    }
}
