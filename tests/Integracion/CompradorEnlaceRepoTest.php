<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompradorEnlaceRepo;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CompradorEnlaceRepoTest extends CasoBaseBd
{
    private CompradorEnlaceRepo $enlaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enlaces = new CompradorEnlaceRepo($this->bd);
    }

    #[Test]
    public function crearYConsultarUnEnlaceVigente(): void
    {
        $token = $this->enlaces->crear('completar_registro', null, null, 60);

        $fila = $this->enlaces->vigente($token, 'completar_registro');

        self::assertNotNull($fila);
    }

    #[Test]
    public function unEnlaceDelTipoEquivocadoNoSirve(): void
    {
        $token = $this->enlaces->crear('completar_registro', null, null, 60);

        self::assertNull($this->enlaces->vigente($token, 'reset_password'));
    }

    #[Test]
    public function unEnlaceUsadoNoVuelveAServir(): void
    {
        $token = $this->enlaces->crear('reset_password', null, null, 60);
        $fila = $this->enlaces->vigente($token, 'reset_password');

        $this->enlaces->marcarUsado($fila['id']);

        self::assertNull($this->enlaces->vigente($token, 'reset_password'));
    }

    #[Test]
    public function unEnlaceVencidoNoSirve(): void
    {
        $token = $this->enlaces->crear('reset_password', null, null, -1);

        self::assertNull($this->enlaces->vigente($token, 'reset_password'));
    }

    #[Test]
    public function unTokenInventadoNoSirve(): void
    {
        self::assertNull($this->enlaces->vigente(bin2hex(random_bytes(32)), 'completar_registro'));
    }
}
