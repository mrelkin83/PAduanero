<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Core\Peticion;
use App\Soporte\Cifrado;
use App\Soporte\Logger;
use App\Wa\WebhookControlador;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class WebhookPagoCursoTest extends CasoBaseBd
{
    private Cifrado $cifrado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cifrado = Cifrado::desdeEntorno();
    }

    /** Configura wa_config con credenciales de Wompi utilizables y un token de webhook conocido. */
    private function configurarWompi(string $eventsSecret): string
    {
        $tokenClaro = bin2hex(random_bytes(32));

        $this->bd->pdo()->prepare(
            "UPDATE wa_config SET
                wompi_public_key = 'pub_test_ejemplo',
                wompi_private_key = ?,
                wompi_events_secret = ?,
                wompi_ambiente = 'sandbox',
                webhook_token_hash = ?
              WHERE id = 1"
        )->execute([
            base64_encode($this->cifrado->cifrar('prv_test_ejemplo')),
            base64_encode($this->cifrado->cifrar($eventsSecret)),
            hash('sha256', $tokenClaro),
        ]);

        return $tokenClaro;
    }

    private function curso(): string
    {
        $catId = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare('INSERT INTO categorias_curso (id, nombre, slug) VALUES (?, ?, ?)')
            ->execute([$catId, 'Aduanero', 'aduanero']);
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();
        $this->bd->pdo()->prepare(
            'INSERT INTO cursos (id, categoria_id, titulo, slug, resumen, descripcion, lo_que_aprendera, precio_cop, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $catId, 'Curso webhook', 'curso-webhook', 'r', 'd', '[]', 250000, 'publicado']);

        return $id;
    }

    /**
     * Construye un cuerpo de webhook de Wompi válido, firmado exactamente
     * como `WompiAdapter::verificarWebhook()` lo verifica: SHA-256 de los
     * valores de `signature.properties` en orden + timestamp + secreto,
     * en mayúsculas.
     */
    private function payloadFirmado(string $eventsSecret, string $referencia, string $paymentLinkId, string $estadoWompi): string
    {
        $timestamp = (string) time();
        $transaction = [
            'id' => 'txn-' . bin2hex(random_bytes(4)),
            'status' => $estadoWompi,
            'amount_in_cents' => 25_000_000,
            'reference' => $referencia,
            'payment_link_id' => $paymentLinkId,
            'payment_method_type' => 'CARD',
        ];
        $props = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];

        $concat = $transaction['id'] . $transaction['status'] . $transaction['amount_in_cents'];
        $checksum = strtoupper(hash('sha256', $concat . $timestamp . $eventsSecret));

        return json_encode([
            'event' => 'transaction.updated',
            'data' => ['transaction' => $transaction],
            'signature' => ['properties' => $props, 'checksum' => $checksum],
            'timestamp' => $timestamp,
        ], JSON_UNESCAPED_UNICODE) ?: '';
    }

    #[Test]
    public function unPagoDeCursoAprobadoConfirmaLaCompraSinTocarElCaminoDeCitas(): void
    {
        $eventsSecret = 'secreto-de-prueba';
        $token = $this->configurarWompi($eventsSecret);

        $cursoId = $this->curso();
        $repoCompras = new \App\Repositorios\CompraCursoRepo($this->bd);
        $compraId = $repoCompras->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
        $repoCompras->guardarReferencia($compraId, 'ref-checkout-original', 'link-estable-123');

        $cuerpo = $this->payloadFirmado($eventsSecret, 'ref-que-rota-9999', 'link-estable-123', 'APPROVED');

        $controlador = new WebhookControlador($this->bd, $this->cifrado, new Logger(sys_get_temp_dir() . '/pa-webhook.log', 'error'), dirname(__DIR__, 2));

        $peticion = new Peticion(
            metodo: 'POST',
            ruta: "/api/wa/pago/{$token}",
            cuerpoCrudo: $cuerpo,
            parametros: ['token' => $token],
        );

        $r = $controlador->pago($peticion);

        self::assertSame(200, $r->estado);
        self::assertSame('pagada', $repoCompras->porId($compraId)['estado']);
    }

    #[Test]
    public function unPagoRechazadoDeCursoMarcaLaCompraComoFallida(): void
    {
        $eventsSecret = 'secreto-de-prueba-2';
        $token = $this->configurarWompi($eventsSecret);

        $cursoId = $this->curso();
        $repoCompras = new \App\Repositorios\CompraCursoRepo($this->bd);
        $compraId = $repoCompras->crear($cursoId, 'Ana Gómez', 'ana@ejemplo.com', 250000);
        $repoCompras->guardarReferencia($compraId, 'ref-2', 'link-456');

        $cuerpo = $this->payloadFirmado($eventsSecret, 'ref-2', 'link-456', 'DECLINED');

        $controlador = new WebhookControlador($this->bd, $this->cifrado, new Logger(sys_get_temp_dir() . '/pa-webhook.log', 'error'), dirname(__DIR__, 2));

        $controlador->pago(new Peticion(
            metodo: 'POST',
            ruta: "/api/wa/pago/{$token}",
            cuerpoCrudo: $cuerpo,
            parametros: ['token' => $token],
        ));

        self::assertSame('fallida', $repoCompras->porId($compraId)['estado']);
    }

    #[Test]
    public function unaReferenciaQueNoEsDeNingunaCompraDeCursoSigueElCaminoDeCitasSinTronar(): void
    {
        $eventsSecret = 'secreto-de-prueba-3';
        $token = $this->configurarWompi($eventsSecret);

        // Sin ninguna compras_curso pendiente que coincida — el evento debe
        // caer en el camino de citas existente (que aquí no encuentra
        // pedido y responde 200 sin más, comportamiento ya existente y sin
        // tocar).
        $cuerpo = $this->payloadFirmado($eventsSecret, 'referencia-de-una-cita', 'link-de-una-cita', 'APPROVED');

        $controlador = new WebhookControlador($this->bd, $this->cifrado, new Logger(sys_get_temp_dir() . '/pa-webhook.log', 'error'), dirname(__DIR__, 2));

        $r = $controlador->pago(new Peticion(
            metodo: 'POST',
            ruta: "/api/wa/pago/{$token}",
            cuerpoCrudo: $cuerpo,
            parametros: ['token' => $token],
        ));

        self::assertSame(200, $r->estado);
    }

    protected function tearDown(): void
    {
        // wa_config no está en las semillas restauradas por CasoBaseBd (ver
        // TABLAS_SEMILLA y limpiar()): sin este reset, las credenciales de
        // Wompi que configurarWompi() deja aquí se filtrarían a la siguiente
        // clase de la misma corrida — mismo hazard que documenta
        // ConexionCompartidaTest::tearDown().
        $this->bd->pdo()->exec(
            "UPDATE wa_config SET wompi_public_key = NULL, wompi_private_key = NULL,
                wompi_events_secret = NULL, wompi_ambiente = 'sandbox',
                webhook_token_hash = NULL WHERE id = 1"
        );

        parent::tearDown();
    }
}
