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
}
