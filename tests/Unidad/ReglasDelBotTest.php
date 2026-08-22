<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El bot de WhatsApp, comprobado contra las mismas reglas que gobiernan lo
 * que este despacho puede decir (CLAUDE.md §3, Ley 1123 de 2007).
 *
 * Las reglas del bot NO viven en la fila editable de `wa_agentes`: viven en
 * `AdaptadorDespacho::reglasDeDominio()`, que el motor inserta en las capas
 * NO editables del prompt. Estas pruebas defienden dos cosas:
 *
 *   1. que las prohibiciones sigan escritas ahí (borrarlas no rompería nada
 *      visible: el bot seguiría conversando, solo que sin frenos), y
 *   2. que el mecanismo del motor que las inserta siga existiendo — un
 *      refactor del paquete vendorizado podría quitar la capa en silencio.
 */
#[Group('critica')]
final class ReglasDelBotTest extends TestCase
{
    private function reglas(): string
    {
        // El adaptador necesita puertos que aquí no importan: las reglas son
        // texto constante y ningún colaborador participa en producirlas.
        $adaptador = (new \ReflectionClass(\App\Wa\AdaptadorDespacho::class))
            ->newInstanceWithoutConstructor();

        return $adaptador->reglasDeDominio();
    }

    #[Test]
    public function lasTresProhibicionesSiguenEscritas(): void
    {
        $reglas = $this->reglas();

        foreach (['términos, plazos ni fechas límite', 'cites normas', 'prometas resultados'] as $nucleo) {
            self::assertStringContainsString(
                $nucleo,
                $reglas,
                "Las reglas de dominio del bot ya no prohíben «{$nucleo}». "
                . 'Sin esa línea el modelo puede decirlo, y lo que diga lleva la firma del abogado.',
            );
        }
    }

    #[Test]
    public function elMotorInsertaLasReglasEnLaCapaNoEditable(): void
    {
        // Si el paquete vendorizado pierde la llamada a SoportaReglasDeDominio,
        // las reglas existen pero no viajan en ningún prompt.
        $fuente = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/whatsapp-engine/src/Core/PromptComposer.php',
        );

        self::assertStringContainsString(
            'SoportaReglasDeDominio',
            $fuente,
            'PromptComposer ya no consulta SoportaReglasDeDominio: las reglas '
            . 'jurídicas del bot dejarían de entrar al prompt sin que nada falle.',
        );
    }

    #[Test]
    public function elAdaptadorImplementaLaInterfazQueElMotorConsulta(): void
    {
        self::assertContains(
            \ElkinLinan\WhatsappAiEngine\Ports\SoportaReglasDeDominio::class,
            class_implements(\App\Wa\AdaptadorDespacho::class),
        );
    }
}
