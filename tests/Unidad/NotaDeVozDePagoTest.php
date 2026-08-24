<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Wa\AdaptadorDespacho;
use App\Wa\CanalConVoz;
use App\Wa\WebhookControlador;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El guion de la nota de voz de los medios de pago (regla del PO, 2026-08-24):
 * cuando la cita queda apartada con el pago pendiente, el cliente recibe
 * SIEMPRE una nota de voz que explica los dos medios — Wompi con verificación
 * automática, y transferencia con revisión humana de diez a treinta minutos.
 *
 * Estas pruebas defienden el CONTENIDO del guion y su coherencia con la capa
 * no editable del prompt: si el guion promete una cosa y las reglas del bot
 * dicen otra, el cliente recibe dos versiones del mismo cobro.
 */
#[Group('critica')]
final class NotaDeVozDePagoTest extends TestCase
{
    #[Test]
    public function elGuionExplicaLosDosMediosYSusTiempos(): void
    {
        $guion = WebhookControlador::GUION_MEDIOS_DE_PAGO;

        foreach (['Wompi', 'Bancolombia', 'automática', 'transferencia', 'comprobante',
                  'entre diez y treinta minutos'] as $nucleo) {
            self::assertStringContainsString(
                $nucleo,
                $guion,
                "El guion de los medios de pago ya no menciona «{$nucleo}»: "
                . 'el cliente se queda sin la mitad de la explicación por la que existe la nota.',
            );
        }
    }

    #[Test]
    public function elGuionDiceQueLaCitaSeCancelaYNoQueLaHoraSeLibera(): void
    {
        $guion = WebhookControlador::GUION_MEDIOS_DE_PAGO;

        self::assertStringContainsString('la cita se cancela', $guion);
        self::assertStringNotContainsString(
            'se libera',
            $guion,
            'El PO pidió (2026-08-24) decir «la cita se cancela», no «la hora se libera».',
        );
    }

    #[Test]
    public function elGuionSePuedeDictarEntero(): void
    {
        // La nota es VOZ: cifras, enlaces o montos se dictan mal y CanalConVoz
        // los clasifica como dato duro. Si esto falla, alguien metió un número
        // o una URL al guion — eso va en un mensaje escrito aparte.
        self::assertFalse(
            CanalConVoz::esDatoDuro(WebhookControlador::GUION_MEDIOS_DE_PAGO),
            'El guion de la nota de voz contiene datos duros (cifras, enlaces, montos): '
            . 'dictados no se entienden. Las cantidades van con letras; los enlaces, escritos.',
        );
    }

    #[Test]
    public function lasReglasDelBotConocenLaNotaYLaFraseDeCancelacion(): void
    {
        $adaptador = (new \ReflectionClass(AdaptadorDespacho::class))
            ->newInstanceWithoutConstructor();
        $reglas = $adaptador->reglasDeDominio();

        self::assertStringContainsString(
            'nota de voz',
            $reglas,
            'Las reglas de dominio ya no le cuentan al bot que la explicación de los medios '
            . 'de pago llega en nota de voz: el bot la repetiría por escrito, duplicada.',
        );
        self::assertStringContainsString(
            'la cita se cancela',
            $reglas,
            'Las reglas de dominio ya no fijan la frase «la cita se cancela»: '
            . 'el bot vuelve a improvisar cosas como «la hora se libera».',
        );
    }
}
