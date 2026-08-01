<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Motor\Accion;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El saneador de acciones: la frontera entre lo que dice el modelo y lo que
 * llega a la base.
 */
#[Group('critica')]
final class AccionTest extends TestCase
{
    #[Test]
    public function extraeUnaAccionSimple(): void
    {
        $accion = Accion::extraer('{"accion":"ESCALAR_HUMANO","motivo":"urgencia"}');

        self::assertNotNull($accion);
        self::assertSame('ESCALAR_HUMANO', $accion->nombre);
        self::assertSame('urgencia', $accion->dato('motivo'));
    }

    #[Test]
    public function sobreviveALlavesDentroDeUnaCadena(): void
    {
        // Esto es lo que rompía el regex del motor de referencia: cortaba en
        // la primera «}» y devolvía JSON inválido.
        $accion = Accion::extraer(
            '{"accion":"REGISTRAR_CASO","descripcion":"me dijeron {esto} y {aquello}","tipo_caso":"decomiso"}'
        );

        self::assertNotNull($accion);
        self::assertSame('decomiso', $accion->dato('tipo_caso'));
        self::assertStringContainsString('{esto}', (string) $accion->dato('descripcion'));
    }

    #[Test]
    public function sobreviveAObjetosAnidados(): void
    {
        $accion = Accion::extraer('{"accion":"REGISTRAR_CASO","extra":{"a":{"b":1}},"tipo_caso":"decomiso"}');

        self::assertNotNull($accion);
        self::assertSame('decomiso', $accion->dato('tipo_caso'));
        // `extra` no está en la whitelist: no pasa.
        self::assertNull($accion->dato('extra'));
    }

    #[Test]
    public function sobreviveAComillasEscapadas(): void
    {
        $accion = Accion::extraer('{"accion":"REGISTRAR_CASO","descripcion":"dijo \\"se acabó\\" y colgó"}');

        self::assertNotNull($accion);
        self::assertStringContainsString('se acabó', (string) $accion->dato('descripcion'));
    }

    #[Test]
    public function ignoraObjetosQueNoSonAcciones(): void
    {
        // El modelo puede escribir un ejemplo antes de la acción de verdad.
        $accion = Accion::extraer('Un ejemplo: {"hola":"mundo"}. Y ahora: {"accion":"VER_SLOTS","fecha":"2026-08-04"}');

        self::assertNotNull($accion);
        self::assertSame('VER_SLOTS', $accion->nombre);
    }

    #[Test]
    public function unTextoSinAccionDevuelveNull(): void
    {
        self::assertNull(Accion::extraer('Cuénteme qué pasó con la mercancía.'));
        self::assertNull(Accion::extraer(''));
    }

    #[Test]
    public function unaAccionDesconocidaSeDescarta(): void
    {
        // El modelo no puede inventarse acciones nuevas.
        self::assertNull(Accion::extraer('{"accion":"BORRAR_TODO","tabla":"casos"}'));
        self::assertNull(Accion::extraer('{"accion":"EJECUTAR_SQL","sql":"DROP TABLE casos"}'));
    }

    #[Test]
    public function losCamposFueraDeLaWhitelistNoPasan(): void
    {
        // Sin esto, los campos del JSON llegaban directos a la capa de datos.
        $accion = Accion::extraer(
            '{"accion":"REGISTRAR_CASO","tipo_caso":"decomiso","puntaje_lead":100,"estado":"ganado","id":"1"}'
        );

        self::assertNotNull($accion);
        self::assertNull($accion->dato('puntaje_lead'));
        self::assertNull($accion->dato('estado'));
        self::assertNull($accion->dato('id'));
    }

    #[Test]
    public function unTipoDeCasoInventadoSeFuerzaAOtro(): void
    {
        $accion = Accion::extraer('{"accion":"REGISTRAR_CASO","tipo_caso":"divorcio_express"}');

        self::assertSame('otro', $accion?->dato('tipo_caso'));
    }

    #[Test]
    public function elCatalogoTributarioSeAcepta(): void
    {
        // Con la lista del index.js estos caerían a «otro» y el bot trataría
        // a un cliente tributario como caso indeterminado.
        foreach (['requerimiento_especial', 'fiscalizacion_renta', 'sancion_tributaria'] as $tipo) {
            $accion = Accion::extraer('{"accion":"REGISTRAR_CASO","tipo_caso":"' . $tipo . '"}');
            self::assertSame($tipo, $accion?->dato('tipo_caso'));
        }
    }

    #[Test]
    public function unaFechaInventadaSeDescarta(): void
    {
        // Una fecha mal formada acabaría en la ficha como si fuera un dato
        // del expediente.
        foreach (['4 de agosto', '2026-13-45', '04/08/2026', '2026-02-30'] as $mala) {
            $accion = Accion::extraer('{"accion":"REGISTRAR_CASO","fecha_acto":"' . $mala . '"}');
            self::assertNull($accion?->dato('fecha_acto'), "«{$mala}» no debió pasar");
        }

        $buena = Accion::extraer('{"accion":"REGISTRAR_CASO","fecha_acto":"2026-08-04"}');
        self::assertSame('2026-08-04', $buena?->dato('fecha_acto'));
    }

    #[Test]
    public function elValorNegativoOAbsurdoSeDescarta(): void
    {
        self::assertNull(
            Accion::extraer('{"accion":"REGISTRAR_CASO","valor_estimado_cop":-500}')?->dato('valor_estimado_cop'),
        );

        self::assertSame(
            200_000_000,
            Accion::extraer('{"accion":"REGISTRAR_CASO","valor_estimado_cop":200000000}')?->dato('valor_estimado_cop'),
        );
    }

    #[Test]
    public function laHoraSeNormalizaOSeDescarta(): void
    {
        self::assertSame(
            '14:30:00',
            Accion::extraer('{"accion":"PROPONER_ASESORIA","horaInicio":"14:30"}')?->dato('horaInicio'),
        );

        self::assertNull(
            Accion::extraer('{"accion":"PROPONER_ASESORIA","horaInicio":"25:99"}')?->dato('horaInicio'),
        );
    }

    #[Test]
    public function unMotivoDeEscalamientoInventadoCaeEnSolicitudExpresa(): void
    {
        $accion = Accion::extraer('{"accion":"ESCALAR_HUMANO","motivo":"porque si"}');

        self::assertSame('solicitud_expresa', $accion?->dato('motivo'));
    }

    #[Test]
    public function losTextosLargosSeRecortan(): void
    {
        $largo = str_repeat('a', 5000);
        $accion = Accion::extraer('{"accion":"REGISTRAR_CASO","numero_acto":"' . $largo . '"}');

        self::assertSame(80, mb_strlen((string) $accion?->dato('numero_acto')));
    }

    #[Test]
    public function losCaracteresDeControlSeLimpian(): void
    {
        // Escapados, que es como pueden llegar dentro de un JSON valido.
        // Sueltos no llegan: el propio json_decode rechaza el documento, y
        // eso lo comprueba la prueba de abajo.
        $accion = Accion::extraer('{"accion":"REGISTRAR_CASO","nombre":"Juan\u0000\u0007 Pérez"}');

        self::assertSame('Juan Pérez', $accion?->dato('nombre'));
    }

    #[Test]
    public function unJsonMalFormadoNoProduceAccion(): void
    {
        // Ante un JSON que no parsea, la respuesta correcta es «no hay
        // accion»: se trata como conversacion, no se adivina.
        self::assertNull(Accion::extraer("{\"accion\":\"REGISTRAR_CASO\",\"nombre\":\"Juan\x00\"}"));
        self::assertNull(Accion::extraer('{"accion":"REGISTRAR_CASO",'));
        self::assertNull(Accion::extraer('{"accion": REGISTRAR_CASO}'));
    }

    #[Test]
    public function limpiarTextoQuitaElJsonQueElModeloMezclo(): void
    {
        // El prompt lo prohíbe, pero los modelos lo hacen igual. Al contacto
        // no se le puede enseñar el JSON interno.
        $limpio = Accion::limpiarTexto(
            'Un momento. {"accion":"VER_SLOTS","fecha":"2026-08-04"} Ya le muestro los horarios.'
        );

        self::assertStringNotContainsString('accion', $limpio);
        self::assertStringNotContainsString('{', $limpio);
        self::assertStringContainsString('Ya le muestro', $limpio);
    }
}
