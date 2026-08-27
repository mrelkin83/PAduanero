<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompradorRepo;
use App\Repositorios\CompradorSesionRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CompradorSesionRepoTest extends CasoBaseBd
{
    private CompradorSesionRepo $sesiones;
    private CompradorRepo $compradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sesiones = new CompradorSesionRepo($this->bd);
        $this->compradores = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
    }

    private function comprador(): string
    {
        return $this->compradores->crear(
            'Ana', 'Gómez', 'CC', '1010101010', '3001234567',
            'sesion' . bin2hex(random_bytes(4)) . '@ejemplo.com', 'clave123',
        );
    }

    #[Test]
    public function crearYConsultarUnaSesionVigente(): void
    {
        $compradorId = $this->comprador();
        $token = $this->sesiones->crear($compradorId, 60, '190.85.1.1', 'PHPUnit');

        $vigente = $this->sesiones->vigente($token);

        self::assertNotNull($vigente);
        self::assertSame($compradorId, $vigente['comprador_id']);
    }

    #[Test]
    public function unTokenQueNoExisteNoEstaVigente(): void
    {
        self::assertNull($this->sesiones->vigente(bin2hex(random_bytes(32))));
    }

    #[Test]
    public function revocarInvalidaElToken(): void
    {
        $compradorId = $this->comprador();
        $token = $this->sesiones->crear($compradorId, 60, null, null);

        $this->sesiones->revocar($token);

        self::assertNull($this->sesiones->vigente($token));
    }

    #[Test]
    public function revocarTodasInvalidaTodasLasSesionesDelComprador(): void
    {
        $compradorId = $this->comprador();
        $t1 = $this->sesiones->crear($compradorId, 60, null, null);
        $t2 = $this->sesiones->crear($compradorId, 60, null, null);

        $revocadas = $this->sesiones->revocarTodas($compradorId);

        self::assertSame(2, $revocadas);
        self::assertNull($this->sesiones->vigente($t1));
        self::assertNull($this->sesiones->vigente($t2));
    }

    #[Test]
    public function elTokenEnClaroNuncaQuedaGuardadoEnLaBase(): void
    {
        $compradorId = $this->comprador();
        $token = $this->sesiones->crear($compradorId, 60, null, null);

        $filas = $this->bd->pdo()->query('SELECT token_hash FROM compradores_sesiones')->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($filas as $hash) {
            self::assertNotSame($token, $hash);
        }
    }
}
