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
    public function laConsultoraNuncaApareceComoAbogada(): void
    {
        // Erika Duarte Ruiz es Profesional en Negocios Internacionales con
        // especialización en Derecho Aduanero: un posgrado abierto a no
        // abogados que no habilita para ejercer el derecho. Que el bot la
        // llame «abogada» no es una imprecisión de redacción —es una
        // afirmación sobre la habilitación profesional de una persona,
        // hecha por escrito desde el número del despacho.
        //
        // Esta prueba defiende el texto, no la conducta del modelo: si
        // alguien borra la prohibición, el bot sigue conversando igual y
        // nadie se entera hasta que lo diga.
        $reglas = $this->reglas();

        self::assertStringContainsString(
            'Erika Duarte Ruiz',
            $reglas,
            'Las reglas de dominio ya no presentan a la consultora. Sin esas '
            . 'líneas el modelo la describe como le parezca.',
        );

        self::assertStringContainsString(
            'NO es abogada',
            $reglas,
            'Desapareció la única línea que impide que el bot la presente como abogada.',
        );

        self::assertStringContainsString(
            'consultora aduanera',
            $reglas,
            'Las reglas prohíben el título equivocado pero ya no dan el correcto: '
            . 'sin alternativa el modelo improvisa, y lo que improvisa es «abogada».',
        );

        // La prohibición es de las que se rompen «arreglando» el texto: basta
        // con que alguien escriba «la abogada Erika» en un ejemplo.
        self::assertDoesNotMatchRegularExpression(
            '/(la\s+)?abogad[ao]\s+(erika|duarte)/iu',
            $reglas,
            'Las propias reglas de dominio llaman abogada a la consultora.',
        );
    }

    #[Test]
    public function nadaEnLasReglasPresentaAPedroComoTributarista(): void
    {
        // Decisión del PO del 2026-08-25: el despacho es 100% aduanero
        // (CLAUDE.md §5 y §7). La migración 0024 lo retiró del contenido
        // editable; aquí se defiende la capa que no se edita.
        self::assertDoesNotMatchRegularExpression(
            '/tributari/iu',
            $this->reglas(),
            'Las reglas de dominio vuelven a nombrar el área tributaria, que el '
            . 'PO retiró del despacho.',
        );
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
