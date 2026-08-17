<?php

declare(strict_types=1);

namespace Pruebas\Unidad;

use App\Motor\Catalogo;
use App\Motor\Cuestionario;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El diagnóstico público, comprobado contra las reglas que gobiernan lo que
 * este despacho puede decir.
 *
 * Estas pruebas son del mismo tipo que `ArquitecturaTest`: defienden cosas
 * que, al romperse, **no producen un fallo visible**. Una opción con un tipo
 * de caso inventado no rompe la página —el formulario sigue funcionando— y
 * un plazo colado en el copy tampoco: simplemente queda una página de un
 * abogado diciendo algo que un abogado no puede decir.
 */
#[Group('critica')]
final class CuestionarioTest extends TestCase
{
    #[Test]
    public function ningunaOpcionEmiteUnTipoQueElMotorRechazaria(): void
    {
        // El catálogo es cerrado y `Catalogo::normalizarTipo()` fuerza `otro`
        // ante cualquier valor no listado. Una opción del diagnóstico con un
        // tipo fuera del catálogo no rompería nada: el caso llegaría a la
        // bandeja clasificado como «otro» y nadie sabría por qué.
        foreach (Cuestionario::pasos() as $paso) {
            foreach ($paso['opciones'] as $opcion) {
                if ($opcion['tipo'] === null) {
                    continue;
                }

                self::assertTrue(
                    Catalogo::esTipoValido($opcion['tipo']),
                    "El paso «{$paso['id']}», opción «{$opcion['valor']}», emite el tipo "
                    . "«{$opcion['tipo']}», que no está en Catalogo. El motor lo convertiría "
                    . 'en «otro» sin avisar.',
                );
            }
        }
    }

    #[Test]
    public function todoCasoCriticoCortaElCuestionario(): void
    {
        // Regla 5, y la razón de que `Cuestionario::definicion()` deduzca la
        // salida en vez de que esté escrita a mano en cada opción: si
        // alguien añade un tipo a `Catalogo::CRITICOS`, el diagnóstico tiene
        // que dejar de preguntarle la cuantía a quien tiene la POLFA en la
        // puerta sin que haya que acordarse de tocar este archivo.
        $encontrados = 0;

        foreach (Cuestionario::definicion() as $paso) {
            foreach ($paso['opciones'] as $opcion) {
                if (!is_string($opcion['tipo']) || !Catalogo::esCritico($opcion['tipo'])) {
                    continue;
                }

                $encontrados++;

                self::assertSame(
                    Cuestionario::SALIDA_URGENTE,
                    $opcion['salida'],
                    "«{$opcion['valor']}» es un caso crítico y sigue el cuestionario.",
                );
            }
        }

        self::assertGreaterThan(
            0,
            $encontrados,
            'Ninguna opción lleva a un caso crítico. O el catálogo cambió, o el '
            . 'cuestionario dejó de ofrecer la salida de la regla 5.',
        );
    }

    #[Test]
    public function elCopyNoNombraPlazosNiNormas(): void
    {
        // Reglas 2 y 3. El sitio donde más tienta romperlas es justo este:
        // un diagnóstico que dijera «le quedan diez días para responder el
        // requerimiento» sería mucho más útil, y por eso hay que impedirlo
        // por prueba y no por buena voluntad.
        $prohibidos = [
            'plazo en días' => '/\b\d+\s*d[íi]as?\b/iu',
            'plazo en meses' => '/\b\d+\s*(meses|mes)\b/iu',
            'norma numerada' => '/\b(art[íi]culo|art\.|decreto|ley|resoluci[óo]n|concepto)\s*\.?\s*\d/iu',
        ];

        foreach (Cuestionario::pasos() as $paso) {
            $textos = [$paso['pregunta'], (string) $paso['ayuda'], $paso['rotulo']];

            foreach ($paso['opciones'] as $opcion) {
                $textos[] = $opcion['etiqueta'];
                $textos[] = $opcion['detalle'];
                $textos[] = $opcion['mensaje'];
                $textos[] = (string) $opcion['tecnico'];
            }

            foreach ($textos as $texto) {
                foreach ($prohibidos as $que => $patron) {
                    self::assertDoesNotMatchRegularExpression(
                        $patron,
                        $texto,
                        "El paso «{$paso['id']}» contiene un {$que}: «{$texto}».",
                    );
                }
            }
        }
    }

    #[Test]
    public function cadaOpcionPuedeComponerSuLineaDelMensaje(): void
    {
        // El mensaje de WhatsApp se arma con `resumen` + `mensaje`. Un
        // `mensaje` vacío no da error: deja una línea en blanco con dos
        // puntos colgando en el texto que la persona está a punto de enviar.
        foreach (Cuestionario::pasos() as $paso) {
            self::assertNotSame('', trim($paso['resumen']), "El paso «{$paso['id']}» no tiene resumen.");

            foreach ($paso['opciones'] as $opcion) {
                self::assertNotSame(
                    '',
                    trim($opcion['mensaje']),
                    "«{$paso['id']}/{$opcion['valor']}» no tiene texto para el mensaje.",
                );
            }
        }
    }

    #[Test]
    public function lasRamasSonNavegablesDePrincipioAFin(): void
    {
        $ids = [];
        $porRama = [];

        foreach (Cuestionario::pasos() as $paso) {
            self::assertArrayNotHasKey($paso['id'], $ids, "Paso duplicado: «{$paso['id']}».");
            $ids[$paso['id']] = true;

            $valores = [];
            foreach ($paso['opciones'] as $opcion) {
                self::assertArrayNotHasKey(
                    $opcion['valor'],
                    $valores,
                    "El paso «{$paso['id']}» repite el valor «{$opcion['valor']}»; "
                    . 'dos radios con el mismo `value` en el mismo `name` son uno solo.',
                );
                $valores[$opcion['valor']] = true;
            }

            if (is_string($paso['rama'])) {
                $porRama[$paso['rama']] = ($porRama[$paso['rama']] ?? 0) + 1;
            }
        }

        foreach (Cuestionario::ramas() as $rama) {
            self::assertArrayHasKey($rama, $porRama, "La rama «{$rama}» no tiene ningún paso propio.");

            // Si las dos ramas tuvieran distinto número de pasos, el «paso N
            // de M» cambiaría de total al elegir rama, y un formulario que
            // crece mientras se llena es un formulario que se abandona.
            self::assertSame(
                Cuestionario::largoDeRama(Cuestionario::ramas()[0]),
                Cuestionario::largoDeRama($rama),
                "La rama «{$rama}» tiene otro número de pasos que las demás.",
            );
        }
    }

    #[Test]
    public function elPrimerPasoOfreceLaSalidaDeFueraDeAlcance(): void
    {
        // El despacho solo atiende procesos correctivos. La salida tiene que
        // estar en el PRIMER paso: negar en el sexto, después de que alguien
        // contestó cinco preguntas, es peor que no preguntar.
        $primero = Cuestionario::pasos()[0];

        $salidas = array_column($primero['opciones'], 'salida');

        self::assertContains(
            Cuestionario::SALIDA_FUERA_ALCANCE,
            $salidas,
            'El primer paso no deja salir a quien no tiene un proceso abierto.',
        );
    }

    #[Test]
    public function cadaRamaSeAlcanzaDesdeElPrimerPaso(): void
    {
        $ofrecidas = array_filter(array_column(Cuestionario::pasos()[0]['opciones'], 'rama'));

        foreach (Cuestionario::ramas() as $rama) {
            self::assertContains(
                $rama,
                $ofrecidas,
                "Ninguna opción del primer paso lleva a la rama «{$rama}»: sus pasos "
                . 'están escritos y son inalcanzables.',
            );
        }
    }
}
