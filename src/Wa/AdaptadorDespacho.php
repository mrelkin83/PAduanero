<?php

declare(strict_types=1);

namespace App\Wa;

use App\Soporte\Fechas;
use ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use ElkinLinan\WhatsappAiEngine\Ports\DbPort;
use ElkinLinan\WhatsappAiEngine\Ports\DomainAdapter;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaCitas;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaReglasDeDominio;

/**
 * Lo que este negocio vende: el TIEMPO del abogado.
 *
 * El «catálogo» es `modalidades_asesoria` —la misma tabla que alimenta el
 * precio de la landing, así que el bot y la página no pueden contradecirse—
 * y la «transacción» es la cita:
 *
 *   crearTransaccion()    → reserva la franja en wa_citas (atómico: índice
 *                           único sobre inicio+slot_activo)
 *   confirmarTransaccion()→ crea el evento en el Google Calendar de Pedro,
 *                           con Meet e invitación al correo del cliente.
 *                           El motor solo llama aquí con el pago verificado
 *                           (o con el cobro configurado en «agenda sin pago»)
 *   cancelarTransaccion() → libera la franja (y borra el evento si existía)
 *
 * Las reglas jurídicas viven en reglasDeDominio(), NO en el prompt editable
 * del panel: son las tres reglas inviolables del CLAUDE.md §3, que ahora
 * también hablan por WhatsApp. Igual que en el diagnóstico, la regla se hace
 * imposible de saltar por construcción, no por convención.
 */
final class AdaptadorDespacho implements DomainAdapter, SoportaCitas, SoportaReglasDeDominio
{
    /** Cuánta antelación mínima exige una cita, en segundos. */
    private const ANTELACION = 3600;

    /** Hasta cuántos días adelante se ofrece agenda. */
    private const HORIZONTE_DIAS = 14;

    public function __construct(
        private readonly DbPort $db,
        private readonly GoogleCalendar $calendario,
    ) {
    }

    /* ── Contexto ─────────────────────────────────────────────────────── */

    public function contextoCliente(array $conversacion): array
    {
        $convId = (int) ($conversacion['id'] ?? 0);
        $abiertas = $convId > 0 ? count($this->transaccionesDe($convId)) : 0;

        return [
            'nombre' => $conversacion['nombre_contacto'] ?? null,
            'es_nuevo' => empty($conversacion['nombre_contacto']) && $abiertas === 0,
            'pedidos_abiertos' => $abiertas,
            'puntos' => null,
        ];
    }

    /* ── Catálogo ─────────────────────────────────────────────────────── */

    public function buscarItems(?string $busqueda = null, array $filtros = [], int $limite = 60): array
    {
        $filas = $this->db->fetchAll(
            'SELECT id, nombre, descripcion, duracion_min, precio_cop, modalidad
               FROM modalidades_asesoria WHERE activo = 1 ORDER BY orden, nombre'
        );

        $items = [];
        foreach ($filas as $f) {
            if ($busqueda !== null && $busqueda !== ''
                && mb_stripos($f['nombre'] . ' ' . $f['descripcion'], $busqueda) === false) {
                continue;
            }
            $items[] = $this->comoItem($f);
            if (count($items) >= $limite) {
                break;
            }
        }

        return $items;
    }

    public function detalleItem(string $id): ?array
    {
        $f = $this->db->fetch(
            'SELECT id, nombre, descripcion, duracion_min, precio_cop, modalidad
               FROM modalidades_asesoria WHERE id = ? AND activo = 1',
            [$id],
        );

        // Pasó en producción (2026-08-22): el modelo mutiló el UUID del
        // servicio al confirmar la cita y la venta murió en «no está en el
        // catálogo» — tres rechazos seguidos y transferencia a humano. Dos
        // redes, las dos sin riesgo porque el precio siempre lo pone esta
        // tabla, jamás el modelo: se acepta también el NOMBRE del servicio,
        // y si el catálogo activo tiene UN solo servicio —que es el caso de
        // este despacho— un id irreconocible resuelve a ese único servicio.
        if (!$f && trim($id) !== '') {
            $f = $this->db->fetch(
                'SELECT id, nombre, descripcion, duracion_min, precio_cop, modalidad
                   FROM modalidades_asesoria WHERE LOWER(nombre) = LOWER(?) AND activo = 1',
                [trim($id)],
            );
        }
        if (!$f) {
            $activos = $this->db->fetchAll(
                'SELECT id, nombre, descripcion, duracion_min, precio_cop, modalidad
                   FROM modalidades_asesoria WHERE activo = 1',
            );
            if (count($activos) === 1) {
                $f = $activos[0];
            }
        }

        return $f ? $this->comoItem($f) : null;
    }

    /** El tiempo no tiene existencias: el cupo lo pone la agenda, no el stock. */
    public function disponibilidad(string $id): ?int
    {
        return null;
    }

    private function comoItem(array $f): array
    {
        return [
            'id' => (string) $f['id'],
            'nombre' => (string) $f['nombre'],
            'descripcion' => (string) ($f['descripcion'] ?? ''),
            // ADR-010: pesos ENTEROS. El float es solo el tipo del contrato.
            'precio' => (float) $f['precio_cop'],
            'duracion_min' => (int) $f['duracion_min'],
            'modalidad' => (string) $f['modalidad'],
            'disponible' => true,
        ];
    }

    /* ── La transacción ───────────────────────────────────────────────── */

    public function calcularTotal(array $items, float $extra = 0.0): array
    {
        $lineas = [];
        $subtotal = 0.0;

        foreach ($items as $i) {
            $id = (string) ($i['producto_id'] ?? '');
            $det = $this->detalleItem($id);
            if ($det === null) {
                throw new \InvalidArgumentException(
                    'Ese servicio no está en el catálogo; consulta el catálogo de nuevo.'
                );
            }
            // Una cita es UNA asesoría: la cantidad no multiplica. Quien
            // necesite dos horas agenda dos citas.
            $lineas[] = ['id' => $id, 'nombre' => $det['nombre'], 'cantidad' => 1, 'precio' => $det['precio']];
            $subtotal += $det['precio'];
        }

        return ['lineas' => $lineas, 'subtotal' => $subtotal, 'total' => $subtotal];
    }

    public function crearTransaccion(array $conversacion, array $items, array $datos = []): array
    {
        $cita = $datos['cita'] ?? null;
        if (!is_array($cita) || empty($cita['inicio'])) {
            throw new \InvalidArgumentException(
                'Falta elegir la hora: consulta la disponibilidad y registra los datos de la cita primero.'
            );
        }

        $inicio = (string) $cita['inicio'];
        if (!$this->dentroDelHorario($inicio)) {
            throw new \InvalidArgumentException(
                'Esa hora está fuera del horario de atención; consulta la disponibilidad de nuevo.'
            );
        }
        if (strtotime($inicio) < time() + self::ANTELACION) {
            throw new \InvalidArgumentException(
                'Esa hora ya quedó demasiado encima; consulta la disponibilidad de nuevo.'
            );
        }

        $primera = $items[0]['producto_id'] ?? '';
        $det = $this->detalleItem((string) $primera);
        if ($det === null) {
            throw new \InvalidArgumentException('Ese servicio no está en el catálogo.');
        }
        $calc = $this->calcularTotal($items);

        // La reserva atómica ES el índice único (inicio, slot_activo): dos
        // conversaciones pidiendo la misma franja llegan aquí y solo una
        // inserta. Sin SELECT previo — el SELECT sería la carrera.
        try {
            $id = $this->db->insert(
                'INSERT INTO wa_citas (conversacion_id, modalidad_id, nombre, correo, telefono,
                                       motivo, inicio, duracion_min, precio_cop, estado, slot_activo)
                 VALUES (?,?,?,?,?,?,?,?,?,\'reservada\',1)',
                [
                    (int) $conversacion['id'],
                    $det['id'],
                    mb_substr(trim((string) ($cita['nombre'] ?? '')), 0, 100)
                        ?: (string) ($conversacion['nombre_contacto'] ?? 'Cliente'),
                    ($cita['correo'] ?? '') !== '' ? mb_substr((string) $cita['correo'], 0, 150) : null,
                    // El teléfono de la cita es el de CONTACTO que dictó el
                    // cliente, no el JID del chat: con remitentes @lid el JID
                    // no es un número al que Pedro pueda llamar. Sin contacto
                    // dictado, queda el JID como último recurso.
                    trim((string) ($cita['telefono'] ?? '')) !== ''
                        ? mb_substr((string) $cita['telefono'], 0, 25)
                        : (string) ($conversacion['telefono'] ?? ''),
                    ($cita['motivo'] ?? '') !== '' ? mb_substr((string) $cita['motivo'], 0, 400) : null,
                    $inicio . ':00',
                    $det['duracion_min'],
                    (int) $det['precio'],
                ],
            );
        } catch (\PDOException $e) {
            if (\App\Core\BD::esDuplicado($e)) {
                throw new \InvalidArgumentException(
                    'Esa hora acaba de ocuparse; consulta la disponibilidad de nuevo y ofrece otra.'
                );
            }
            throw $e;
        }

        return ['id' => (string) $id, 'total' => $calc['total']];
    }

    public function estadoTransaccion(string $id): array
    {
        $c = $this->db->fetch('SELECT * FROM wa_citas WHERE id = ?', [(int) $id]);
        if (!$c) {
            return [];
        }
        $p = $this->db->fetch('SELECT estado_pago FROM wa_pedidos WHERE pedido_id = ?', [(int) $id]);

        $textos = [
            'reservada' => 'Reservada, pendiente de confirmación',
            'confirmada' => 'Confirmada — el cliente recibe la invitación con el enlace de la videollamada en su correo',
            'cancelada' => 'Cancelada',
        ];

        return [
            'cita_id' => (int) $c['id'],
            'fecha' => Fechas::fechaNatural(substr((string) $c['inicio'], 0, 10))
                . ' a las ' . Fechas::horaNatural(substr((string) $c['inicio'], 11, 5)),
            'duracion_min' => (int) $c['duracion_min'],
            'estado' => ($textos[$c['estado']] ?? $c['estado']),
            'estado_pago' => $p['estado_pago'] ?? 'PAYMENT_PENDING',
            'enlace_videollamada' => $c['gcal_meet_url'] ?: null,
            // `total` no es decorativo: PaymentManager::generar() lo exige para
            // saber cuánto cobrar, y sin la clave responde «Pedido no
            // encontrado» — con lo que NINGÚN pago se podía generar. En pesos
            // (ADR-010); los centavos de Wompi los pone su adaptador.
            'total' => (float) $c['precio_cop'],
        ];
    }

    public function transaccionesDe(int $conversacionId): array
    {
        $filas = $this->db->fetchAll(
            'SELECT id FROM wa_citas
              WHERE conversacion_id = ? AND slot_activo = 1 AND inicio >= NOW() - INTERVAL 2 HOUR
              ORDER BY inicio',
            [$conversacionId],
        );

        return array_values(array_filter(array_map(
            fn (array $f): array => $this->estadoTransaccion((string) $f['id']),
            $filas,
        )));
    }

    public function cancelarTransaccion(string $id): array
    {
        $c = $this->db->fetch('SELECT * FROM wa_citas WHERE id = ?', [(int) $id]);
        if (!$c) {
            return ['ok' => false, 'error' => 'Esa cita no existe'];
        }
        if ($c['estado'] === 'cancelada') {
            return ['ok' => true, 'mensaje' => 'Esa cita ya estaba cancelada'];
        }

        $this->db->query(
            "UPDATE wa_citas SET estado = 'cancelada', slot_activo = NULL WHERE id = ?",
            [(int) $id],
        );
        if (!empty($c['gcal_event_id'])) {
            try {
                $this->calendario->cancelarEvento((string) $c['gcal_event_id']);
            } catch (\Throwable) {
                // La franja local ya quedó libre; el evento huérfano lo ve
                // Pedro en su calendario y la invitación avisa al cliente.
            }
        }

        return ['ok' => true, 'mensaje' => 'Cita cancelada; la hora quedó libre de nuevo'];
    }

    public function confirmarTransaccion(string $id): bool
    {
        $c = $this->db->fetch('SELECT * FROM wa_citas WHERE id = ?', [(int) $id]);
        if (!$c || $c['estado'] === 'cancelada') {
            return false;
        }

        $evento = ['ok' => false, 'event_id' => null, 'meet' => null];
        try {
            $evento = $this->calendario->crearEvento($c);
        } catch (\Throwable) {
            // Se confirma igual: el pago ya está verificado y la cita existe
            // en wa_citas, que es la fuente de verdad. Sin evento no hay
            // invitación automática — queda registrado para crearla a mano.
        }

        $this->db->query(
            "UPDATE wa_citas SET estado = 'confirmada', gcal_event_id = ?, gcal_meet_url = ? WHERE id = ?",
            [$evento['event_id'], $evento['meet'], (int) $id],
        );

        $this->avisarAlAbogado($c, $evento);

        return true;
    }

    /**
     * WhatsApp al número de guardia con la cita recién confirmada (decisión
     * del PO, 2026-08-22). Mejor esfuerzo por diseño: el pago ya está
     * verificado y la cita confirmada — un aviso caído no puede deshacer eso,
     * y Pedro la vería igual en su calendario y en el panel.
     *
     * @param array<string,mixed> $cita
     * @param array{ok:bool,event_id:?string,meet:?string} $evento
     */
    private function avisarAlAbogado(array $cita, array $evento): void
    {
        try {
            $cfg = WaConfig::cargar($this->db);
            $guardia = trim((string) ($cfg['handoff_numero'] ?? ''));
            $canal = EvolutionClient::desdeConfig($this->db);
            if ($guardia === '' || $canal === null) {
                return;
            }

            $inicio = (string) $cita['inicio'];
            $telefono = (string) ($cita['telefono'] ?? '');
            $texto = "📅 *Nueva cita confirmada*\n\n"
                . '👤 ' . ((string) ($cita['nombre'] ?? '') ?: 'Sin nombre') . "\n"
                . '🗓 ' . Fechas::fechaNatural(substr($inicio, 0, 10))
                . ' a las ' . Fechas::horaNatural(substr($inicio, 11, 5)) . "\n"
                . '📱 ' . (str_contains($telefono, '@lid')
                    ? 'oculto por WhatsApp — el chat vive en el panel'
                    : ($telefono ?: 'Sin teléfono')) . "\n"
                . '📝 ' . ((string) ($cita['motivo'] ?? '') ?: 'Sin motivo registrado') . "\n"
                . '📧 ' . ((string) ($cita['correo'] ?? '') ?: 'Sin correo')
                . (!empty($evento['meet']) ? "\n🔗 " . $evento['meet'] : '');

            $canal->enviarTexto($guardia, $texto);
        } catch (\Throwable) {
            // Nada: el aviso es cortesía, la cita ya quedó confirmada.
        }
    }

    public function capacidades(): array
    {
        return ['citas'];
    }

    /* ── SoportaCitas ─────────────────────────────────────────────────── */

    public function horariosDisponibles(string $desde, string $hasta): array
    {
        $d = strtotime($desde);
        $h = strtotime($hasta);
        if ($d === false || $h === false || $h <= $d) {
            return [];
        }

        // Nunca antes de la antelación mínima ni después del horizonte.
        $d = max($d, time() + self::ANTELACION);
        $h = min($h, time() + self::HORIZONTE_DIAS * 86400);
        if ($h <= $d) {
            return [];
        }

        $horario = WaConfig::horario(WaConfig::cargar($this->db));
        if (!$horario) {
            return [];
        }
        $duracion = $this->duracionBase();

        // Lo ya reservado, local y de Google, en una sola pasada.
        $ocupado = [];
        foreach ($this->db->fetchAll(
            'SELECT inicio, duracion_min FROM wa_citas
              WHERE slot_activo = 1 AND inicio BETWEEN ? AND ?',
            [date('Y-m-d H:i:s', $d - 7200), date('Y-m-d H:i:s', $h + 7200)],
        ) as $c) {
            $ini = strtotime((string) $c['inicio']);
            $ocupado[] = ['desde' => $ini, 'hasta' => $ini + 60 * (int) $c['duracion_min']];
        }
        $google = $this->calendario->ocupado(date('Y-m-d H:i', $d), date('Y-m-d H:i', $h));
        array_push($ocupado, ...$google['ocupado']);

        $franjas = [];
        for ($dia = strtotime('midnight', $d); $dia <= $h; $dia += 86400) {
            $f = $horario[(string) (int) date('w', $dia)] ?? null;
            if (!$f || empty($f['desde']) || empty($f['hasta'])) {
                continue;
            }
            $inicioDia = strtotime(date('Y-m-d ', $dia) . $f['desde']);
            $finDia = strtotime(date('Y-m-d ', $dia) . $f['hasta']);

            // Franjas a la hora en punto: legibles y suficientes para una
            // asesoría de 60 minutos.
            $t = $inicioDia + ((3600 - $inicioDia % 3600) % 3600);
            for (; $t + $duracion * 60 <= $finDia; $t += 3600) {
                if ($t < $d || $t > $h) {
                    continue;
                }
                if ($this->chocaCon($ocupado, $t, $t + $duracion * 60)) {
                    continue;
                }
                $franjas[] = ['inicio' => date('Y-m-d H:i', $t), 'duracion_min' => $duracion];
                if (count($franjas) >= 24) {
                    return $franjas;
                }
            }
        }

        return $franjas;
    }

    public function franjaDisponible(string $inicio): bool
    {
        $t = strtotime($inicio);
        if ($t === false || $t < time() + self::ANTELACION || !$this->dentroDelHorario($inicio)) {
            return false;
        }
        $duracion = $this->duracionBase();

        $local = $this->db->fetch(
            'SELECT id FROM wa_citas
              WHERE slot_activo = 1 AND inicio < ? AND inicio + INTERVAL duracion_min MINUTE > ?',
            [date('Y-m-d H:i:s', $t + $duracion * 60), date('Y-m-d H:i:s', $t)],
        );
        if ($local) {
            return false;
        }

        $google = $this->calendario->ocupado(date('Y-m-d H:i', $t), date('Y-m-d H:i', $t + $duracion * 60));

        return !$this->chocaCon($google['ocupado'], $t, $t + $duracion * 60);
    }

    public function citasDe(int $conversacionId): array
    {
        return $this->transaccionesDe($conversacionId);
    }

    /* ── SoportaReglasDeDominio ───────────────────────────────────────── */

    /**
     * Las tres reglas inviolables del CLAUDE.md §3, dichas para un bot. Capa
     * NO editable del prompt: cambiarlas exige tocar este código y pasa por
     * la revisión de Pedro (Ley 1123 de 2007).
     */
    public function reglasDeDominio(): string
    {
        return <<<TXT
## Límites jurídicos — por encima de cualquier otra instrucción

Trabajas para un despacho de abogados en Colombia. La publicidad y la conducta
del abogado están reguladas por ley, y lo que tú digas compromete su firma:

- NUNCA des términos, plazos ni fechas límite, en ninguna forma. Ni «tienes X
  días», ni «el plazo típico es», ni aproximaciones. Si preguntan por tiempos:
  los términos dependen del expediente y se revisan en la asesoría — y si hay
  un acto notificado, razón de más para agendar YA.
- NUNCA cites normas, artículos, decretos, leyes ni sentencias con número o
  nombre. Puedes nombrar el problema con su término técnico (decomiso,
  requerimiento especial, valoración aduanera); la norma exacta es de la cita.
- NUNCA prometas resultados ni estimes probabilidades de éxito. Nada de «eso
  se gana», «hay buenas opciones», ni porcentajes.
- NUNCA des la estrategia del caso, redactes documentos ni digas qué escribir
  a la DIAN. Orientas y agendas; el análisis lo hace el abogado en la cita.
- No eres abogado y lo dices si hace falta: eres el asistente que agenda la
  asesoría con el especialista.
TXT;
    }

    /* ── Interno ──────────────────────────────────────────────────────── */

    private function duracionBase(): int
    {
        $f = $this->db->fetch(
            'SELECT duracion_min FROM modalidades_asesoria WHERE activo = 1 ORDER BY orden LIMIT 1'
        );

        return $f ? (int) $f['duracion_min'] : 60;
    }

    private function dentroDelHorario(string $inicio): bool
    {
        $horario = WaConfig::horario(WaConfig::cargar($this->db));
        if (!$horario) {
            return false;
        }
        $t = strtotime($inicio);
        if ($t === false) {
            return false;
        }
        $f = $horario[(string) (int) date('w', $t)] ?? null;
        if (!$f || empty($f['desde']) || empty($f['hasta'])) {
            return false;
        }
        $hora = date('H:i', $t);
        $fin = date('H:i', $t + $this->duracionBase() * 60);

        return $hora >= $f['desde'] && $fin <= $f['hasta'] && $fin > $hora;
    }

    /** @param array<array{desde:int,hasta:int}> $rangos */
    private function chocaCon(array $rangos, int $desde, int $hasta): bool
    {
        foreach ($rangos as $r) {
            if ($r['desde'] < $hasta && $r['hasta'] > $desde) {
                return true;
            }
        }

        return false;
    }
}
