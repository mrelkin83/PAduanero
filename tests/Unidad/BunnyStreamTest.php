<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Soporte\BunnyStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BunnyStreamTest extends TestCase
{
    #[Test]
    public function sinLibraryIdNiSecurityKeyNoEstaDisponible(): void
    {
        $bunny = new BunnyStream('', '');

        self::assertFalse($bunny->disponible());
    }

    #[Test]
    public function conCredencialesEstaDisponible(): void
    {
        $bunny = new BunnyStream('12345', 'clave-secreta');

        self::assertTrue($bunny->disponible());
    }

    #[Test]
    public function urlEmbedTraeElLibraryIdYElVideoId(): void
    {
        $bunny = new BunnyStream('12345', 'clave-secreta');

        $url = $bunny->urlEmbed('video-abc', 240);

        self::assertStringStartsWith('https://iframe.mediadelivery.net/embed/12345/video-abc?', $url);
        self::assertStringContainsString('token=', $url);
        self::assertStringContainsString('expires=', $url);
    }

    #[Test]
    public function urlEmbedEsEstableParaElMismoVencimiento(): void
    {
        $bunny = new BunnyStream('12345', 'clave-secreta');

        $expira = time() + 240 * 60;
        $url1 = $bunny->urlEmbed('video-abc', 240, $expira);
        $url2 = $bunny->urlEmbed('video-abc', 240, $expira);

        self::assertSame($url1, $url2);
    }

    #[Test]
    public function elTokenCambiaSiCambiaElVideoId(): void
    {
        $bunny = new BunnyStream('12345', 'clave-secreta');
        $expira = time() + 240 * 60;

        $urlA = $bunny->urlEmbed('video-a', 240, $expira);
        $urlB = $bunny->urlEmbed('video-b', 240, $expira);

        self::assertNotSame($urlA, $urlB);
    }
}
