<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Wa\DbMotor;
use App\Wa\Recordatorios;
use ElkinLinan\WhatsappAiEngine\Channel\ChannelInterface;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * El recordatorio de citas: a quién le llega, cuándo, y sobre todo cuántas
 * veces — un recordatorio repetido en cada pasada del cron es spam a un
 * cliente que ya pagó.
 */
final class RecordatoriosTest extends CasoBaseBd
{
    /** @var list<array{telefono:string,texto:string}> */
    private array $enviados = [];

    private function canalEspia(): ChannelInterface
    {
        $enviados = &$this->enviados;

        return new class($enviados) implements ChannelInterface {
            /** @param list<array{telefono:string,texto:string}> $enviados */
            public function __construct(private array &$enviados)
            {
            }

            public function nombre(): string
            {
                return 'espía';
            }

            public function requisitosFaltantes(): array
            {
                return [];
            }

            public function estado(): array
            {
                return ['estado' => 'conectado', 'numero' => null, 'mensaje' => ''];
            }

            public function conectar(): array
            {
                return ['ok' => true];
            }

            public function desconectar(): array
            {
                return ['ok' => true];
            }

            public function registrarWebhook(string $url): array
            {
                return ['ok' => true];
            }

            public function enviarTexto(string $telefono, string $texto): array
            {
                $this->enviados[] = ['telefono' => $telefono, 'texto' => $texto];

                return ['ok' => true, 'message_id' => 'x', 'error' => ''];
            }

            public function enviarAudio(string $telefono, string $audioBase64, string $mime = 'audio/ogg'): array
            {
                return ['ok' => true, 'message_id' => null, 'error' => ''];
            }

            public function enviarImagen(string $telefono, string $imagenBase64, string $caption = ''): array
            {
                return ['ok' => true, 'message_id' => null, 'error' => ''];
            }

            public function normalizarWebhook(array $payload): ?array
            {
                return null;
            }

            public function descargarMedia(array $mensaje): ?string
            {
                return null;
            }
        };
    }

    private function sembrarCita(string $inicio, string $estado = 'confirmada'): int
    {
        $pdo = $this->bd->pdo();

        $pdo->exec(
            "INSERT INTO wa_conversaciones (telefono, nombre_contacto, estado, ultimo_mensaje_at)
             VALUES ('573000000002', 'Prueba', 'IA_ACTIVA', NOW())"
        );
        $convId = (int) $pdo->lastInsertId();

        $modalidad = $pdo->query('SELECT id FROM modalidades_asesoria LIMIT 1')->fetchColumn();

        $pdo->prepare(
            "INSERT INTO wa_citas (conversacion_id, modalidad_id, nombre, correo, telefono,
                                   motivo, inicio, duracion_min, precio_cop, estado, slot_activo, gcal_meet_url)
             VALUES (?,?,?,?,?,?,?,?,?,?,1,'https://meet.google.com/xyz')"
        )->execute([
            $convId, $modalidad, 'Cliente Prueba', 'cliente@example.com', '573000000002',
            'aprehensión de mercancía', $inicio, 60, 400000, $estado,
        ]);

        return (int) $pdo->lastInsertId();
    }

    #[Test]
    public function recuerdaUnaSolaVezALClienteYAlAbogado(): void
    {
        $pdo = $this->bd->pdo();
        $pdo->exec("UPDATE wa_config SET handoff_numero = '573001112233' WHERE id = 1");

        // Una cita dentro de la ventana, una lejana y una sin confirmar: solo
        // la primera debe recordarse.
        $dentro = $this->sembrarCita(date('Y-m-d H:i:s', time() + 20 * 60));
        $this->sembrarCita(date('Y-m-d H:i:s', time() + 5 * 3600));
        $this->sembrarCita(date('Y-m-d H:i:s', time() + 15 * 60), 'reservada');

        $servicio = new Recordatorios(new DbMotor($this->bd), $this->canalEspia());

        self::assertSame(1, $servicio->enviar());
        self::assertCount(2, $this->enviados, 'un WhatsApp al cliente y otro al abogado');
        self::assertSame('573000000002', $this->enviados[0]['telefono']);
        self::assertStringContainsString('meet.google.com', $this->enviados[0]['texto']);
        self::assertSame('573001112233', $this->enviados[1]['telefono']);
        self::assertStringContainsString('Cliente Prueba', $this->enviados[1]['texto']);

        // Segunda pasada del cron: nada nuevo que recordar.
        $this->enviados = [];
        self::assertSame(0, $servicio->enviar());
        self::assertSame([], $this->enviados);

        $marca = $this->bd->pdo()->query(
            "SELECT recordatorio_enviado_at FROM wa_citas WHERE id = {$dentro}"
        )->fetchColumn();
        self::assertNotNull($marca);
    }
}
