<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Wa\PendientesSinResponder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La decisión de qué chat cuenta como «pendiente sin responder» es la que
 * determina a quién le llega (o no) una disculpa hablada del despacho. Se
 * prueba pura, sin Evolution: la función recibe lo que devolvería
 * `listarChats()` y un reloj fijo.
 */
final class PendientesSinResponderTest extends TestCase
{
    private const AHORA = 1_800_000_000;

    /** Un chat con la forma que entrega Evolution (findChats). */
    private static function chat(
        string $jid,
        bool $fromMe,
        int $hace,
        array $message = ['conversation' => 'Hola, necesito asesoría'],
        string $nombre = 'Cliente',
    ): array {
        return [
            'remoteJid' => $jid,
            'pushName' => $nombre,
            'lastMessage' => [
                'key' => ['fromMe' => $fromMe],
                'message' => $message,
                'messageTimestamp' => self::AHORA - $hace,
            ],
        ];
    }

    #[Test]
    public function elUltimoMensajeDelClienteSinRespuestaEsPendiente(): void
    {
        $lista = PendientesSinResponder::filtrar(
            [self::chat('573204993515@s.whatsapp.net', false, 7200)],
            self::AHORA,
        );

        self::assertCount(1, $lista);
        self::assertSame('573204993515', $lista[0]['telefono']);
        self::assertSame('Hola, necesito asesoría', $lista[0]['texto']);
        self::assertSame('texto', $lista[0]['tipo']);
    }

    #[Test]
    public function siLaUltimaPalabraEsDelDespachoNoHayNadaQueResponder(): void
    {
        $lista = PendientesSinResponder::filtrar(
            [self::chat('573204993515@s.whatsapp.net', true, 7200)],
            self::AHORA,
        );

        self::assertSame([], $lista);
    }

    #[Test]
    public function unaConversacionEnCursoNoEsUnPendiente(): void
    {
        // 10 minutos de silencio es alguien escribiendo, no un chat olvidado:
        // mandarle una disculpa por la demora sería absurdo.
        $lista = PendientesSinResponder::filtrar(
            [self::chat('573204993515@s.whatsapp.net', false, 600)],
            self::AHORA,
        );

        self::assertSame([], $lista);
    }

    #[Test]
    public function loMasViejoQueLaVentanaYaNoSeResponde(): void
    {
        $lista = PendientesSinResponder::filtrar(
            [self::chat('573204993515@s.whatsapp.net', false, PendientesSinResponder::VENTANA + 3600)],
            self::AHORA,
        );

        self::assertSame([], $lista);
    }

    #[Test]
    public function gruposDifusionesYCanalesQuedanFuera(): void
    {
        $lista = PendientesSinResponder::filtrar([
            self::chat('120363000000000000@g.us', false, 7200),
            self::chat('status@broadcast', false, 7200),
            self::chat('120363000000000000@newsletter', false, 7200),
        ], self::AHORA);

        self::assertSame([], $lista);
    }

    #[Test]
    public function unJidDeLidViajaCompletoComoDestino(): void
    {
        // A un @lid no se le puede mandar por los dígitos pelados: Evolution
        // le añadiría @s.whatsapp.net a un número que no existe.
        $lista = PendientesSinResponder::filtrar(
            [self::chat('45939054088265@lid', false, 7200)],
            self::AHORA,
        );

        self::assertCount(1, $lista);
        self::assertSame('45939054088265@lid', $lista[0]['telefono']);
    }

    #[Test]
    public function elMasRecienteEncabezaLaLista(): void
    {
        $lista = PendientesSinResponder::filtrar([
            self::chat('573000000001@s.whatsapp.net', false, 86400),
            self::chat('573000000002@s.whatsapp.net', false, 3600),
        ], self::AHORA);

        self::assertSame(['573000000002', '573000000001'], array_column($lista, 'telefono'));
    }

    #[Test]
    public function losTiposSinTextoSeRotulanSinInventarContenido(): void
    {
        self::assertSame(['audio', '[nota de voz]'],
            PendientesSinResponder::contenido(['message' => ['audioMessage' => []]]));
        self::assertSame(['imagen', '[imagen]'],
            PendientesSinResponder::contenido(['message' => ['imageMessage' => []]]));
        self::assertSame(['imagen', 'mi comprobante'],
            PendientesSinResponder::contenido(['message' => ['imageMessage' => ['caption' => 'mi comprobante']]]));
        self::assertSame(['documento', '[documento]'],
            PendientesSinResponder::contenido(['message' => ['documentMessage' => []]]));
        self::assertSame(['otro', '[mensaje sin texto]'],
            PendientesSinResponder::contenido(['message' => []]));
    }

    #[Test]
    public function elTextoExtendidoTambienCuentaComoTexto(): void
    {
        self::assertSame(['texto', 'con enlace'],
            PendientesSinResponder::contenido(['message' => ['extendedTextMessage' => ['text' => 'con enlace']]]));
    }
}
