<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\AuditoriaRepo;
use App\Repositorios\CasoRepo;
use App\Repositorios\ConsultaRepo;
use App\Repositorios\ContactoRepo;
use App\Servicios\Metricas;
use App\Soporte\Cifrado;
use App\Soporte\Fechas;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

/**
 * El embudo por canal (Etapa 8): costo por lead y conversión.
 *
 * El detalle que más importa no es la suma sino el NULL: un canal sin
 * inversión anotada no tiene costo por lead, y pintarlo como $0 diría que
 * los leads salieron gratis. Cero creíble y falso — la clase de defecto más
 * cara del proyecto.
 */
#[Group('critica')]
final class MetricasTest extends CasoBaseBd
{
    private Metricas $metricas;
    private ContactoRepo $contactos;
    private CasoRepo $casos;
    private ConsultaRepo $consultas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metricas = new Metricas($this->bd);
        $this->contactos = new ContactoRepo($this->bd, Cifrado::desdeEntorno(), new AuditoriaRepo($this->bd));
        $this->casos = new CasoRepo($this->bd);
        $this->consultas = new ConsultaRepo($this->bd);
    }

    /** Un lead completo: contacto (con canal), caso, consulta pagada. */
    private function leadPagado(string $telefono, string $utmSource): void
    {
        $contacto = $this->contactos->crear($telefono, 'whatsapp');

        $this->bd->pdo()->prepare('UPDATE contactos SET utm_source = ? WHERE id = ?')
            ->execute([$utmSource, $contacto->id]);

        $caso = $this->casos->crear($contacto->id, ['tipo_caso' => 'decomiso']);

        $modalidad = (string) $this->bd->pdo()
            ->query('SELECT id FROM modalidades_asesoria WHERE activo = 1 LIMIT 1')->fetchColumn();

        // Horas distintas por teléfono para no chocar con el slot único.
        $hora = sprintf('%02d:00:00', 8 + (int) substr($telefono, -2) % 10);
        $fecha = Fechas::ahora()->modify('+5 days')->format('Y-m-d');

        $consulta = $this->consultas->reservar($caso->id, $contacto->id, $modalidad, $fecha, $hora, 45);
        $this->consultas->cambiarEstado($consulta->id, 'pagada');

        $this->bd->pdo()->prepare(
            "INSERT INTO pagos (consulta_id, pasarela, referencia, monto_centavos, estado, firma_verificada, confirmado_en)
             VALUES (?, 'wompi', ?, 40000000, 'aprobado', 1, NOW())"
        )->execute([$consulta->id, 'PA-' . bin2hex(random_bytes(5))]);
    }

    private function leadFrio(string $telefono, string $utmSource): void
    {
        $contacto = $this->contactos->crear($telefono, 'whatsapp');
        $this->bd->pdo()->prepare('UPDATE contactos SET utm_source = ? WHERE id = ?')
            ->execute([$utmSource, $contacto->id]);
    }

    /** @return array<string,array<string,mixed>> por canal */
    private function embudo(): array
    {
        $desde = Fechas::ahora()->modify('-1 day')->format('Y-m-d');
        $hasta = Fechas::hoy();

        $porCanal = [];

        foreach ($this->metricas->porCanal($desde, $hasta) as $fila) {
            $porCanal[$fila['canal']] = $fila;
        }

        return $porCanal;
    }

    #[Test]
    public function elEmbudoCuentaLeadsPagadasEIngresosPorCanal(): void
    {
        $this->leadPagado('573001110001', 'meta');
        $this->leadFrio('573001110002', 'meta');
        $this->leadFrio('573001110003', 'google');

        $embudo = $this->embudo();

        self::assertSame(2, $embudo['meta']['leads']);
        self::assertSame(1, $embudo['meta']['pagadas']);
        self::assertSame(400000, $embudo['meta']['ingresos_cop'], 'centavos → pesos solo al presentar');
        self::assertSame(0.5, $embudo['meta']['conversion']);

        self::assertSame(1, $embudo['google']['leads']);
        self::assertSame(0, $embudo['google']['pagadas']);
    }

    #[Test]
    public function sinInversionElCostoPorLeadEsNuloNoCero(): void
    {
        $this->leadFrio('573001110001', 'meta');

        $embudo = $this->embudo();

        self::assertNull($embudo['meta']['costo_por_lead_cop']);
        self::assertSame(0, $embudo['meta']['inversion_cop']);
    }

    #[Test]
    public function conInversionAnotadaSaleElCostoPorLead(): void
    {
        $this->leadFrio('573001110001', 'meta');
        $this->leadFrio('573001110002', 'meta');

        $this->metricas->anotarInversion(Fechas::ahora()->format('Y-m'), 'meta', 1_000_000, null);

        $embudo = $this->embudo();

        self::assertSame(1_000_000, $embudo['meta']['inversion_cop']);
        self::assertSame(500_000.0, $embudo['meta']['costo_por_lead_cop']);
    }

    #[Test]
    public function anotarDosVecesElMismoMesCorrigeEnVezDeSumar(): void
    {
        // Quien anota una cifra equivocada la corrige volviendo a anotar.
        // Si se sumara, corregir sería imposible sin tocar la base a mano.
        $mes = Fechas::ahora()->format('Y-m');

        $this->metricas->anotarInversion($mes, 'meta', 1_000_000, null);
        $this->metricas->anotarInversion($mes, 'meta', 750_000, null);

        $monto = (int) $this->bd->pdo()->query(
            "SELECT monto_cop FROM inversion_canales WHERE canal = 'meta'"
        )->fetchColumn();

        self::assertSame(750_000, $monto);
    }

    #[Test]
    public function elContactoSinUtmCaeASuCanalDeOrigen(): void
    {
        $this->contactos->crear('573001110009', 'whatsapp');

        $embudo = $this->embudo();

        self::assertArrayHasKey('whatsapp', $embudo);
        self::assertSame(1, $embudo['whatsapp']['leads']);
    }

    #[Test]
    public function unaInversionNegativaSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->metricas->anotarInversion('2026-08', 'meta', -100, null);
    }
}
