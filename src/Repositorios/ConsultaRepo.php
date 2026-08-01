<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;
use App\Excepciones\SlotOcupadoException;
use App\Modelos\Consulta;
use App\Soporte\Fechas;

/**
 * Todo el SQL de `consultas`. Contiene el punto más delicado del sistema.
 *
 * Dos clientes en el mismo horario es una crisis con Pedro, y ocurre por una
 * ventana de milisegundos entre comprobar y escribir. De ahí las dos líneas
 * de defensa del ADR-015, en este orden y no en otro.
 */
final class ConsultaRepo
{
    private const CAMPOS = 'id, caso_id, contacto_id, modalidad_id, fecha, hora_inicio,
                            hora_fin, estado, precio_cop, reserva_expira, enlace_reunion,
                            creado_en';

    /** Las que ocupan cupo: cuentan para el solapamiento. */
    private const VIVAS = "('reservada','pagada','realizada')";

    public function __construct(private readonly BD $bd)
    {
    }

    public function porId(string $id): ?Consulta
    {
        $stmt = $this->bd->pdo()->prepare('SELECT ' . self::CAMPOS . ' FROM consultas WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Consulta::desdeFila($fila);
    }

    /** @return list<Consulta> */
    public function activasPorContacto(string $contactoId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM consultas
              WHERE contacto_id = ? AND estado IN ' . self::VIVAS . '
              ORDER BY fecha, hora_inicio'
        );
        $stmt->execute([$contactoId]);

        return array_map(static fn (array $f): Consulta => Consulta::desdeFila($f), $stmt->fetchAll());
    }

    /**
     * Reserva un cupo. **Punto crítico** (ADR-015).
     *
     * Dos defensas, en este orden:
     *
     *  1. **Solapamiento real** bajo `SELECT … FOR UPDATE`. La condición es
     *     `(inicio_a < fin_b) AND (inicio_b < fin_a)`, que es la única que
     *     detecta un choque parcial.
     *  2. **El índice único** sobre `slot_unico`, capturando el 1062.
     *
     * Por qué no basta la segunda: `slot_unico` es
     * `CONCAT(fecha,'T',hora_inicio)`, así que solo bloquea horas de inicio
     * idénticas. Basta con crear desde el panel una modalidad de 30 minutos
     * para que 14:00–15:00 y 14:30–15:30 convivan sin violarlo. El índice es
     * la red debajo, no el suelo.
     *
     * Por qué no basta la primera: sin el índice, un `FOR UPDATE` que no
     * encuentra filas no bloquea nada en modo `REPEATABLE READ` salvo por los
     * gap locks, y confiar en el nivel de aislamiento para una invariante de
     * negocio es confiar en una configuración que alguien puede cambiar.
     *
     * El precio se **congela aquí** (ADR-010, en pesos enteros): subir la
     * tarifa después no toca esta reserva.
     *
     * @throws SlotOcupadoException
     */
    public function reservar(
        string $casoId,
        string $contactoId,
        string $modalidadId,
        string $fecha,
        string $horaInicio,
        int $minutosExpiracion,
    ): Consulta {
        $pdo = $this->bd->pdo();
        $pdo->beginTransaction();

        try {
            $id = $this->insertarReserva(
                $casoId,
                $contactoId,
                $modalidadId,
                $fecha,
                $horaInicio,
                $minutosExpiracion,
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return $this->porId($id) ?? throw new \RuntimeException('La consulta no se pudo releer.');
    }

    /**
     * El cuerpo de la reserva, **asumiendo transacción abierta**.
     *
     * Está separado porque `reagendar()` necesita cancelar y volver a reservar
     * dentro de una sola transacción, y PDO no anida: un `beginTransaction()`
     * dentro de otro lanza «There is already an active transaction». Quien
     * abre la transacción es siempre el método público, y este solo se
     * preocupa de la invariante.
     *
     * @return string id de la consulta creada
     *
     * @throws SlotOcupadoException
     */
    private function insertarReserva(
        string $casoId,
        string $contactoId,
        string $modalidadId,
        string $fecha,
        string $horaInicio,
        int $minutosExpiracion,
    ): string {
        $pdo = $this->bd->pdo();
        $modalidad = $this->modalidad($modalidadId);

        if ($modalidad === null) {
            throw new \RuntimeException('La modalidad de asesoría no existe o está inactiva.');
        }

        $horaFin = $this->sumarMinutos($horaInicio, (int) $modalidad['duracion_min']);

        // 1ª línea. El FOR UPDATE va sobre la misma fecha que se va a
        // insertar: bloquea las filas vivas de ese día para que otra
        // transacción no pueda colar una entre la comprobación y el INSERT.
        $vivas = $pdo->prepare(
            'SELECT hora_inicio, hora_fin FROM consultas
              WHERE fecha = ? AND estado IN ' . self::VIVAS . ' FOR UPDATE'
        );
        $vivas->execute([$fecha]);

        foreach ($vivas->fetchAll() as $v) {
            if ($horaInicio < $v['hora_fin'] && $v['hora_inicio'] < $horaFin) {
                throw new SlotOcupadoException($fecha, $horaInicio);
            }
        }

        $id = (string) $pdo->query('SELECT UUID()')->fetchColumn();

        try {
            // 2ª línea: si algo se saltó la primera, el UNIQUE sobre
            // `slot_unico` lo para aquí con el 1062 de MySQL — que es el
            // equivalente del 23505 de Postgres (ADR-005).
            $pdo->prepare(
                'INSERT INTO consultas
                    (id, caso_id, contacto_id, modalidad_id, fecha, hora_inicio, hora_fin,
                     estado, precio_cop, reserva_expira)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'reservada\', ?,
                         DATE_ADD(NOW(), INTERVAL ? MINUTE))'
            )->execute([
                $id,
                $casoId,
                $contactoId,
                $modalidadId,
                $fecha,
                $horaInicio,
                $horaFin,
                (int) $modalidad['precio_cop'],
                max(1, $minutosExpiracion),
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new SlotOcupadoException($fecha, $horaInicio);
            }

            throw $e;
        }

        return $id;
    }

    /**
     * Cambia el estado.
     *
     * `pagada` merece mención aparte: solo llega aquí desde el webhook
     * verificado por firma de la pasarela (regla 6). Ni la palabra del
     * contacto ni la del modelo pueden producirla, y por eso este método no
     * lo llama nunca el motor conversacional.
     */
    public function cambiarEstado(string $id, string $estado): void
    {
        $permitidos = ['reservada', 'pagada', 'realizada', 'cancelada', 'no_asistio', 'expirada'];

        if (!in_array($estado, $permitidos, true)) {
            throw new \InvalidArgumentException("Estado de consulta desconocido: {$estado}");
        }

        // Al pagar se limpia la expiración: ya no hay reserva que caducar, y
        // dejarla puesta haría que el cron de expiración cancelara una
        // consulta cobrada.
        $this->bd->pdo()->prepare(
            'UPDATE consultas
                SET estado = ?,
                    reserva_expira = IF(? IN (\'pagada\',\'realizada\'), NULL, reserva_expira)
              WHERE id = ?'
        )->execute([$estado, $estado, $id]);
    }

    /**
     * Reagenda: cancela la vieja y reserva la nueva, en una sola transacción.
     *
     * Cancelar primero libera el cupo original, de modo que reagendar de
     * 14:00 a 14:30 con una modalidad de una hora no choque consigo mismo. Si
     * la nueva hora está ocupada, el rollback devuelve la vieja intacta: el
     * cliente conserva su cita en vez de quedarse sin ninguna.
     *
     * @throws SlotOcupadoException
     */
    public function reagendar(string $id, string $fecha, string $horaInicio): Consulta
    {
        $actual = $this->porId($id);

        if ($actual === null) {
            throw new \RuntimeException('La consulta no existe.');
        }

        if (!$actual->viva()) {
            throw new \RuntimeException('Solo se reagenda una consulta viva.');
        }

        $pdo = $this->bd->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare("UPDATE consultas SET estado = 'cancelada' WHERE id = ?")->execute([$id]);

            // La ventana restante, no una nueva. Reagendar no puede regalar
            // 45 minutos más para pagar.
            //
            // Va por `Fechas` y no por `strtotime(...) - time()`: la columna
            // está en UTC y el reloj de PHP en Bogotá, así que la resta cruda
            // sale negativa, el `max(1, …)` la convierte en un minuto y la
            // reserva reagendada caduca casi al instante. Es el mismo defecto
            // que `puedeResponderIa()` (CONTRATOS.md §Errores 17).
            $minutos = $actual->reservaExpira !== null
                ? max(1, Fechas::minutosHastaUtc($actual->reservaExpira))
                : 45;

            $nuevaId = $this->insertarReserva(
                $actual->casoId,
                $actual->contactoId,
                $actual->modalidadId,
                $fecha,
                $horaInicio,
                $minutos,
            );

            // La reserva ya pagada o realizada conserva su condición:
            // reagendar no puede convertir en «pendiente de pago» algo que ya
            // se cobró.
            if ($actual->pagada()) {
                $pdo->prepare("UPDATE consultas SET estado = 'pagada', reserva_expira = NULL WHERE id = ?")
                    ->execute([$nuevaId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return $this->porId($nuevaId) ?? throw new \RuntimeException('La consulta no se pudo releer.');
    }

    /**
     * Expira las reservas vencidas sin pago (regla 7).
     *
     * Lo llama `bin/cron-expirar-reservas.php`. Es lo que devuelve el cupo a
     * la agenda cuando alguien reservó y no pagó: sin esto, un contacto que
     * abandona bloquea un horario para siempre.
     *
     * @return list<string> ids de las que expiraron, para notificar
     */
    public function expirarVencidas(): array
    {
        $pdo = $this->bd->pdo();

        $stmt = $pdo->query(
            "SELECT id FROM consultas
              WHERE estado = 'reservada'
                AND reserva_expira IS NOT NULL AND reserva_expira <= NOW()"
        );
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if ($ids === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($ids), '?'));

        $pdo->prepare("UPDATE consultas SET estado = 'expirada' WHERE id IN ({$huecos})")
            ->execute($ids);

        return array_map(strval(...), $ids);
    }

    /**
     * Cupos libres de un día.
     *
     * Cruza `horarios` (la agenda semanal), `bloqueos` (vacaciones, audiencias)
     * y las consultas vivas. Se calcula en PHP y no en SQL porque la
     * granularidad depende de la duración de la modalidad, que es un parámetro
     * del panel y no una constante.
     *
     * @return list<string> horas de inicio en formato HH:MM:SS
     */
    public function slotsLibres(string $fecha, string $modalidadId): array
    {
        $modalidad = $this->modalidad($modalidadId);

        if ($modalidad === null) {
            return [];
        }

        $duracion = (int) $modalidad['duracion_min'];
        $pdo = $this->bd->pdo();

        $diaSemana = Fechas::diaSemana($fecha);

        $franjas = $pdo->prepare(
            'SELECT hora_inicio, hora_fin FROM horarios
              WHERE dia_semana = ? AND activo = 1 ORDER BY hora_inicio'
        );
        $franjas->execute([$diaSemana]);

        $ocupados = $pdo->prepare(
            'SELECT hora_inicio, hora_fin FROM consultas
              WHERE fecha = ? AND estado IN ' . self::VIVAS
        );
        $ocupados->execute([$fecha]);

        $bloqueos = $pdo->prepare('SELECT hora_inicio, hora_fin FROM bloqueos WHERE fecha = ?');
        $bloqueos->execute([$fecha]);

        $tomados = [...$ocupados->fetchAll(), ...$bloqueos->fetchAll()];

        $libres = [];

        foreach ($franjas->fetchAll() as $franja) {
            $cursor = (string) $franja['hora_inicio'];

            while (true) {
                $fin = $this->sumarMinutos($cursor, $duracion);

                if ($fin > (string) $franja['hora_fin']) {
                    break;
                }

                $choca = false;

                foreach ($tomados as $t) {
                    if ($cursor < $t['hora_fin'] && $t['hora_inicio'] < $fin) {
                        $choca = true;
                        break;
                    }
                }

                if (!$choca) {
                    $libres[] = $cursor;
                }

                $cursor = $this->sumarMinutos($cursor, $duracion);
            }
        }

        return $libres;
    }

    /** @return array<string,mixed>|null */
    public function modalidad(string $id): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, nombre, duracion_min, precio_cop
               FROM modalidades_asesoria WHERE id = ? AND activo = 1'
        );
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * La modalidad que se ofrece cuando el modelo no nombró ninguna.
     *
     * El negocio de hoy tiene UNA (virtual, 60 min); si mañana hay varias, el
     * `orden` del panel decide cuál es la propuesta por defecto. El precio
     * sale SIEMPRE de aquí — jamás del texto del LLM.
     *
     * @return array<string,mixed>|null
     */
    public function modalidadPorDefecto(): ?array
    {
        $fila = $this->bd->pdo()->query(
            'SELECT id, nombre, duracion_min, precio_cop
               FROM modalidades_asesoria WHERE activo = 1 ORDER BY orden, nombre LIMIT 1'
        )->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Suma minutos a una hora de reloj.
     *
     * Delega en `Fechas` en vez de reimplementarlo: el formato de salida tiene
     * que ser exactamente `HH:MM:SS` porque todas las comparaciones de este
     * repositorio son entre cadenas, y `9:00:00` ordenaría antes que
     * `10:00:00` al revés de lo que debe.
     */
    private function sumarMinutos(string $hora, int $minutos): string
    {
        return Fechas::sumarMinutos($hora, $minutos);
    }
}
