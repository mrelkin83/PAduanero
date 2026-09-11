<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Wa\AdaptadorDespacho;
use App\Wa\DbMotor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * El contrato entre el dominio del despacho y el cobro del motor.
 *
 * Existe por un defecto real vivido en producción el 2026-08-22: la primera
 * conversación completa llegó hasta «pago en línea» y ahí murió, porque
 * `PaymentManager::generar()` saca el importe de `estadoTransaccion()['total']`
 * y el adaptador devolvía el estado sin esa clave. El síntoma no decía nada de
 * dinero: «Pedido no encontrado», el modelo lo contó como «la franja se acaba
 * de ocupar», y la conversación terminó transferida a un humano.
 */
#[Group('critica')]
final class AdaptadorDespachoTest extends CasoBaseBd
{
    #[Test]
    public function elEstadoDeUnaCitaTraeElTotalDelQueElCobroVive(): void
    {
        $pdo = $this->bd->pdo();

        $pdo->exec(
            "INSERT INTO wa_conversaciones (telefono, nombre_contacto, estado, ultimo_mensaje_at)
             VALUES ('573000000001', 'Prueba', 'IA_ACTIVA', NOW())"
        );
        $convId = (int) $pdo->lastInsertId();

        $modalidad = $pdo->query(
            'SELECT id, precio_cop FROM modalidades_asesoria WHERE activo = 1 LIMIT 1'
        )->fetch();
        self::assertNotFalse($modalidad, 'la semilla de modalidades_asesoria debe existir');

        $pdo->prepare(
            "INSERT INTO wa_citas (conversacion_id, modalidad_id, nombre, correo, telefono,
                                   motivo, inicio, duracion_min, precio_cop, estado, slot_activo)
             VALUES (?,?,?,?,?,?,?,?,?, 'reservada', 1)"
        )->execute([
            $convId, $modalidad['id'], 'Prueba', 'prueba@example.com', '573000000001',
            'aprehensión', '2030-01-15 09:00:00', 60, (int) $modalidad['precio_cop'],
        ]);
        $citaId = (int) $pdo->lastInsertId();

        // Solo el puerto de datos participa en estadoTransaccion; el calendario
        // no, así que se instancia sin constructor y se inyecta lo único usado.
        $adaptador = (new \ReflectionClass(AdaptadorDespacho::class))->newInstanceWithoutConstructor();
        $db = new \ReflectionProperty(AdaptadorDespacho::class, 'db');
        $db->setValue($adaptador, new DbMotor($this->bd));

        $estado = $adaptador->estadoTransaccion((string) $citaId);

        self::assertSame(
            (float) $modalidad['precio_cop'],
            $estado['total'] ?? null,
            'PaymentManager::generar() exige la clave `total`; sin ella ningún pago se genera',
        );
        self::assertSame('Reservada, pendiente de confirmación', $estado['estado']);
    }

    #[Test]
    public function elBotNoOfreceLasModalidadesQueNoLeCorresponden(): void
    {
        // El catálogo del bot es `modalidades_asesoria` filtrada por
        // `ofrece_bot = 1` (migración 0043). Sin ese filtro, dar de alta la
        // revisión técnica de la consultora la habría metido en el catálogo
        // y el bot la ofrecería y la agendaría — que es justo lo que
        // prohíben sus reglas de dominio: la cita es con el abogado.
        //
        // No es hipotético: el bot está encendido en producción, y este
        // fallo no daría ningún error. Se vería como una cita agendada con
        // alguien que no puede atenderla.
        $adaptador = (new \ReflectionClass(AdaptadorDespacho::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AdaptadorDespacho::class, 'db'))
            ->setValue($adaptador, new DbMotor($this->bd));

        $this->bd->pdo()->exec(
            "INSERT INTO modalidades_asesoria
               (nombre, descripcion, duracion_min, precio_cop, modalidad,
                requiere_pago, activo, ofrece_bot, orden)
             VALUES ('Modalidad que el bot no vende', 'x', 60, 400000, 'virtual', 1, 1, 0, 90)"
        );
        // `id` es CHAR(36) con DEFAULT (UUID()), así que `lastInsertId()` no
        // sirve: hay que releerlo.
        $oculta = (string) $this->bd->pdo()->query(
            "SELECT id FROM modalidades_asesoria WHERE nombre = 'Modalidad que el bot no vende'"
        )->fetchColumn();

        $nombres = array_column($adaptador->buscarItems(), 'nombre');

        self::assertNotContains(
            'Modalidad que el bot no vende',
            $nombres,
            'El catálogo del bot incluye una modalidad marcada como no ofrecible.',
        );

        // Y por ningún camino de `detalleItem()`: ni por id, ni por nombre,
        // ni por la red de rescate. Las tres consultas tienen que llevar el
        // filtro; si faltara en una sola, el modelo llegaría a agendarla.
        //
        // Que la respuesta sea el servicio del abogado y no `null` es la
        // red del 2026-08-22 haciendo su trabajo: un id irreconocible
        // resuelve al único servicio del catálogo del bot. Lo que se
        // comprueba aquí no es que devuelva nada, es que **nunca devuelva la
        // modalidad escondida**.
        foreach ([$oculta, 'Modalidad que el bot no vende', 'id-que-el-modelo-mutilo'] as $busqueda) {
            $detalle = $adaptador->detalleItem($busqueda);

            self::assertNotNull(
                $detalle,
                'La red del catálogo de un solo servicio dejó de funcionar para ' . $busqueda,
            );
            self::assertSame(
                'Asesoría jurídica virtual (1 hora)',
                $detalle['nombre'],
                "Buscando «{$busqueda}» el bot resolvió a una modalidad que no le corresponde.",
            );
        }
    }
}
