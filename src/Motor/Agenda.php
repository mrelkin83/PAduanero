<?php

declare(strict_types=1);

namespace App\Motor;

use App\Excepciones\SlotOcupadoException;
use App\Repositorios\ConsultaRepo;
use App\Servicios\Config;
use App\Servicios\Pagos;
use App\Soporte\Fechas;
use App\Soporte\Logger;

/**
 * Ejecuta las acciones de agenda que el modelo propone (Etapa 5).
 *
 * El principio que gobierna esta clase: **el modelo propone, la base
 * dispone, y lo que ve el contacto sobre horarios, precios y enlaces sale
 * de plantillas con datos de la base — jamás del texto del LLM.** Un modelo
 * puede alucinar un horario libre o un precio; por eso su texto se
 * complementa con un apéndice generado aquí, y el prompt le prohíbe
 * inventar esos datos por su cuenta.
 *
 * Todo lo que la acción trae se trata como dato sucio (regla 12): fechas
 * validadas contra formato y contra la ventana de agendamiento, ids
 * verificados contra el contacto dueño de la conversación. Una consulta que
 * no es suya no se puede cancelar por mucho que el modelo lo pida.
 *
 * Devuelve `ResultadoAgenda`: el apéndice para el mensaje saliente y, si
 * aplica, el nuevo nodo de la máquina de estados.
 */
final class Agenda
{
    public function __construct(
        private readonly ConsultaRepo $consultas,
        private readonly Pagos $pagos,
        private readonly Config $config,
        private readonly Logger $log,
    ) {
    }

    public function despachar(
        Accion $accion,
        string $contactoId,
        ?string $casoId,
        \App\Modelos\Contacto $contacto,
    ): ResultadoAgenda {
        return match ($accion->nombre) {
            'VER_SLOTS' => $this->verSlots($accion),
            'PROPONER_ASESORIA' => $this->proponer($accion, $contactoId, $casoId, $contacto),
            'CANCELAR_CONSULTA' => $this->cancelar($accion, $contactoId),
            'REAGENDAR_CONSULTA' => $this->reagendar($accion, $contactoId),
            default => ResultadoAgenda::nada(),
        };
    }

    // ── VER_SLOTS ────────────────────────────────────────────────────────

    private function verSlots(Accion $accion): ResultadoAgenda
    {
        $fecha = $this->fechaValida($accion->texto('fecha'));

        if ($fecha === null) {
            return ResultadoAgenda::apendice(
                'Para consultar la disponibilidad necesito la fecha que le interesa.',
            );
        }

        $modalidad = $this->modalidad($accion->texto('modalidadId'));

        if ($modalidad === null) {
            return ResultadoAgenda::nada();
        }

        $slots = $this->consultas->slotsLibres($fecha, (string) $modalidad['id']);

        if ($slots === []) {
            return ResultadoAgenda::apendice(
                'Para el ' . Fechas::fechaNatural($fecha)
                . ' no quedan cupos. ¿Le muestro otro día?',
            );
        }

        // Máximo ocho: una lista de veinte horas no ayuda a decidir.
        $horas = array_map(
            static fn (string $h): string => Fechas::horaNatural($h),
            array_slice($slots, 0, 8),
        );

        return ResultadoAgenda::apendice(
            'Horarios disponibles el ' . Fechas::fechaNatural($fecha) . ': '
            . implode(' · ', $horas) . ' (hora de Colombia).',
        );
    }

    // ── PROPONER_ASESORIA → reserva + enlace de pago ─────────────────────

    private function proponer(
        Accion $accion,
        string $contactoId,
        ?string $casoId,
        \App\Modelos\Contacto $contacto,
    ): ResultadoAgenda {
        // Sin caso no hay propuesta: el embudo califica antes de cobrar. Un
        // modelo que propone pagar en el segundo mensaje mata la venta y
        // además dejaría una consulta sin caso al que colgarse.
        if ($casoId === null) {
            $this->log->warn('agenda.propuesta_sin_caso', ['contacto' => $contactoId]);

            return ResultadoAgenda::nada();
        }

        $fecha = $this->fechaValida($accion->texto('fecha'));
        $hora = $this->horaValida($accion->texto('horaInicio'));
        $modalidad = $this->modalidad($accion->texto('modalidadId'));

        if ($fecha === null || $hora === null || $modalidad === null) {
            return ResultadoAgenda::apendice(
                '¿Qué día y a qué hora le vendría bien la asesoría? Con eso le reservo el cupo.',
            );
        }

        $minutos = max(1, (int) $this->config->get('minutos_reserva_pago', 45));

        try {
            $consulta = $this->consultas->reservar(
                $casoId,
                $contactoId,
                (string) $modalidad['id'],
                $fecha,
                $hora,
                $minutos,
            );
        } catch (SlotOcupadoException) {
            return $this->alternativas($fecha, (string) $modalidad['id']);
        }

        // El precio sale de la modalidad, no del LLM ni de la acción
        // (ADR-010: aquí van PESOS; el factor 100 vive en crearLink).
        $link = $this->pagos->crearLink(
            $consulta->id,
            (int) $modalidad['precio_cop'],
            'Asesoría ' . $modalidad['nombre'] . ' · ' . $fecha,
            $contacto,
        );

        return new ResultadoAgenda(
            'Le reservé el cupo del ' . Fechas::fechaNatural($fecha) . ' a las '
            . Fechas::horaNatural($hora) . '. Para confirmarlo, realice el pago aquí:'
            . "\n" . $link['url'] . "\n"
            . 'El cupo queda guardado por ' . $minutos . ' minutos. Valor: $'
            . number_format((int) $modalidad['precio_cop'], 0, ',', '.') . ' COP.',
            Estados::PENDIENTE_PAGO,
        );
    }

    private function alternativas(string $fecha, string $modalidadId): ResultadoAgenda
    {
        $slots = $this->consultas->slotsLibres($fecha, $modalidadId);

        if ($slots === []) {
            return ResultadoAgenda::apendice(
                'Ese horario acaba de ocuparse y no quedan más cupos ese día. '
                . '¿Le muestro otra fecha?',
            );
        }

        $horas = array_map(
            static fn (string $h): string => Fechas::horaNatural($h),
            array_slice($slots, 0, 5),
        );

        return ResultadoAgenda::apendice(
            'Ese horario acaba de ocuparse. Ese mismo día tengo: '
            . implode(' · ', $horas) . '. ¿Alguno le sirve?',
        );
    }

    // ── CANCELAR / REAGENDAR ─────────────────────────────────────────────

    private function cancelar(Accion $accion, string $contactoId): ResultadoAgenda
    {
        $consulta = $this->consultaDelContacto($accion->texto('consultaId'), $contactoId);

        if ($consulta === null) {
            return ResultadoAgenda::nada();
        }

        // Una asesoría PAGADA no se cancela por chat: hay dinero de por
        // medio y una política de reembolso que aplica una persona. El bot
        // no promete devoluciones (regla 4, por analogía: no compromete
        // resultados que no controla).
        if ($consulta->estado === 'pagada') {
            return ResultadoAgenda::apendice(
                'Su asesoría ya está pagada, así que la cancelación la gestiona '
                . 'directamente el despacho. Ya quedó avisado y le escribirán hoy mismo.',
                escalarPagada: true,
            );
        }

        $this->consultas->cambiarEstado($consulta->id, 'cancelada');

        return ResultadoAgenda::apendice(
            'Listo, la reserva del ' . Fechas::fechaNatural($consulta->fecha) . ' a las '
            . Fechas::horaNatural($consulta->horaInicio) . ' quedó cancelada.',
        );
    }

    private function reagendar(Accion $accion, string $contactoId): ResultadoAgenda
    {
        $consulta = $this->consultaDelContacto($accion->texto('consultaId'), $contactoId);
        $fecha = $this->fechaValida($accion->texto('fecha'));
        $hora = $this->horaValida($accion->texto('horaInicio'));

        if ($consulta === null || $fecha === null || $hora === null) {
            return ResultadoAgenda::nada();
        }

        if ($consulta->estado === 'pagada') {
            return ResultadoAgenda::apendice(
                'Como su asesoría ya está pagada, el cambio de horario se lo confirma '
                . 'directamente el despacho. Ya quedó avisado.',
                escalarPagada: true,
            );
        }

        try {
            $nueva = $this->consultas->reagendar($consulta->id, $fecha, $hora);
        } catch (SlotOcupadoException) {
            return $this->alternativas($fecha, $consulta->modalidadId);
        }

        return ResultadoAgenda::apendice(
            'Su reserva quedó para el ' . Fechas::fechaNatural($nueva->fecha) . ' a las '
            . Fechas::horaNatural($nueva->horaInicio)
            . '. Recuerde que el cupo se confirma con el pago.',
        );
    }

    // ── Validación de lo que trae la acción (regla 12) ───────────────────

    private function fechaValida(?string $fecha): ?string
    {
        if ($fecha === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            return null;
        }

        [$a, $m, $d] = array_map(intval(...), explode('-', $fecha));

        if (!checkdate($m, $d, $a)) {
            return null;
        }

        // Ni en el pasado ni más allá de la ventana del panel: una reserva
        // para dentro de un año es casi seguro una fecha alucinada.
        $maxDias = max(1, (int) $this->config->get('dias_max_anticipacion', 30));

        if ($fecha < Fechas::hoy()
            || $fecha > Fechas::ahora()->modify("+{$maxDias} days")->format('Y-m-d')) {
            return null;
        }

        return $fecha;
    }

    private function horaValida(?string $hora): ?string
    {
        if ($hora === null || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', $hora) !== 1) {
            return null;
        }

        return strlen($hora) === 5 ? $hora . ':00' : $hora;
    }

    /** @return array<string,mixed>|null */
    private function modalidad(?string $modalidadId): ?array
    {
        $modalidad = $modalidadId !== null
            ? $this->consultas->modalidad($modalidadId)
            : $this->consultas->modalidadPorDefecto();

        // Un id que no existe NO cae al default en silencio: si el modelo
        // inventó una modalidad, ofrecer otra con otro precio sin decirlo
        // sería cobrar algo distinto de lo que se habló.
        if ($modalidad === null && $modalidadId === null) {
            $this->log->error('agenda.sin_modalidad_activa', []);
        }

        return $modalidad;
    }

    private function consultaDelContacto(?string $consultaId, string $contactoId): ?\App\Modelos\Consulta
    {
        if ($consultaId === null) {
            return null;
        }

        $consulta = $this->consultas->porId($consultaId);

        // La pertenencia se comprueba SIEMPRE: el id viene del modelo, y el
        // modelo lo leyó de una conversación. Sin esto, un contacto que
        // consiga colar un id ajeno cancelaría la cita de otra persona.
        if ($consulta === null || $consulta->contactoId !== $contactoId) {
            if ($consulta !== null) {
                $this->log->error('agenda.consulta_ajena', [
                    'consulta' => $consultaId,
                    'contacto' => $contactoId,
                ]);
            }

            return null;
        }

        return $consulta;
    }
}
