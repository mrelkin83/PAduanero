<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Repositorios\CompradorRepo;
use App\Soporte\Cifrado;
use PHPUnit\Framework\Attributes\Test;
use Pruebas\CasoBaseBd;

final class CompradorRepoTest extends CasoBaseBd
{
    private CompradorRepo $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CompradorRepo($this->bd, Cifrado::desdeEntorno());
    }

    #[Test]
    public function crearYBuscarPorCorreo(): void
    {
        $id = $this->repo->crear(
            'Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana@ejemplo.com', 'claveSegura123',
        );

        $comprador = $this->repo->porCorreo('ana@ejemplo.com');

        self::assertNotNull($comprador);
        self::assertSame($id, $comprador->id);
        self::assertSame('Ana Gómez', $comprador->nombreCompleto());
    }

    #[Test]
    public function elDocumentoQuedaCifradoEnLaBaseNuncaEnClaro(): void
    {
        $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana2@ejemplo.com', 'claveSegura123');

        $crudo = (string) $this->bd->pdo()
            ->query("SELECT numero_documento_cifrado FROM compradores WHERE correo = 'ana2@ejemplo.com'")
            ->fetchColumn();

        self::assertStringNotContainsString('1010101010', $crudo);
    }

    #[Test]
    public function verificarPasswordFuncionaYRechazaClaveIncorrecta(): void
    {
        $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana3@ejemplo.com', 'claveSegura123');

        self::assertTrue($this->repo->verificarPassword('ana3@ejemplo.com', 'claveSegura123'));
        self::assertFalse($this->repo->verificarPassword('ana3@ejemplo.com', 'claveMala'));
        self::assertFalse($this->repo->verificarPassword('no-existe@ejemplo.com', 'cualquiera'));
    }

    #[Test]
    public function cambiarPasswordActualizaElHash(): void
    {
        $id = $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana4@ejemplo.com', 'claveVieja');

        $this->repo->cambiarPassword($id, 'claveNueva');

        self::assertFalse($this->repo->verificarPassword('ana4@ejemplo.com', 'claveVieja'));
        self::assertTrue($this->repo->verificarPassword('ana4@ejemplo.com', 'claveNueva'));
    }

    #[Test]
    public function existeCorreoDistingueRegistradoDeNoRegistrado(): void
    {
        $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana5@ejemplo.com', 'clave123');

        self::assertTrue($this->repo->existeCorreo('ana5@ejemplo.com'));
        self::assertFalse($this->repo->existeCorreo('nadie@ejemplo.com'));
    }

    #[Test]
    public function numeroDocumentoDescifraElValorGuardado(): void
    {
        $id = $this->repo->crear('Ana', 'Gómez', 'CC', '1010101010', '3001234567', 'ana-doc@ejemplo.com', 'clave123');

        self::assertSame('1010101010', $this->repo->numeroDocumento($id));
    }

    #[Test]
    public function numeroDocumentoEsNullParaUnCompradorQueNoExiste(): void
    {
        self::assertNull($this->repo->numeroDocumento((string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn()));
    }
}
