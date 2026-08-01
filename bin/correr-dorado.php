<?php

declare(strict_types=1);

/**
 * Corre el conjunto dorado contra el modelo REAL y registra el resultado.
 *
 *   php bin/correr-dorado.php                    ← contra el modelo primario
 *   php bin/correr-dorado.php claude-opus-6      ← contra otro candidato
 *   php bin/correr-dorado.php --caso=plazo-01    ← un solo caso, para iterar
 *
 * ESTE ES EL CICLO QUE SE VA A REPETIR VARIAS VECES
 *
 * Ajustar el prompt y volver a correr esto es la operación central de la
 * Etapa 4, y va a hacerse hasta que las 20 aserciones pasen y después hasta
 * que las 30 conversaciones de Pedro salgan limpias. Por eso:
 *
 *  · `--caso=` permite iterar sobre el que falla sin pagar los otros
 *    diecinueve. Cuesta centavos, pero cuesta minutos.
 *  · Solo se registra la corrida en `modelos_ia` cuando se corren TODOS los
 *    casos. Una corrida parcial en verde no puede habilitar una promoción.
 *  · El resultado va por `GateDorado::registrarCorrida()`, que ata la corrida
 *    al prompt activo en ese momento. Si el prompt cambia después, el verde
 *    caduca solo y la promoción vuelve a estar bloqueada (ADR-016).
 *
 * QUÉ SE PRUEBA AQUÍ Y QUÉ NO
 *
 * Aquí se prueba **lo que el modelo dice**: que no suelte un plazo, que no
 * cite una norma numerada, que no prometa un resultado. La máquina de estados
 * —el orden de las puertas, el gate de consentimiento, el modo sombra— la
 * cubre `MotorTest` con dobles, y no necesita gastar tokens.
 *
 * Los casos marcados `sin_llamada_llm` son la excepción interesante: se
 * comprueba precisamente que NO hubo llamada. Es la regla 5, y una prueba de
 * que el escalamiento por señal crítica no depende de que el modelo colabore.
 */

use App\Core\Aplicacion;
use App\Motor\Accion;
use App\Motor\SenalesCriticas;
use App\Servicios\GateDorado;
use App\Servicios\Llm;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

$soloCaso = null;
$modeloPedido = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--caso=')) {
        $soloCaso = substr($arg, 7);
    } elseif (!str_starts_with($arg, '--')) {
        $modeloPedido = $arg;
    }
}

$conjunto = json_decode((string) file_get_contents($raiz . '/tests/golden/conversaciones.json'), true);

if (!is_array($conjunto['casos'] ?? null)) {
    fwrite(STDERR, 'No se pudo leer tests/golden/conversaciones.json' . PHP_EOL);
    exit(1);
}

$casos = $conjunto['casos'];

if ($soloCaso !== null) {
    $casos = array_values(array_filter($casos, static fn (array $c): bool => $c['id'] === $soloCaso));

    if ($casos === []) {
        fwrite(STDERR, "No existe el caso «{$soloCaso}»." . PHP_EOL);
        exit(1);
    }
}

$contenedor = (new Aplicacion($raiz))->contenedor;
$llm = $contenedor->obtener(Llm::class);
$gate = $contenedor->obtener(GateDorado::class);
$bd = $contenedor->obtener(App\Core\BD::class);
$prompts = $contenedor->obtener(App\Repositorios\PromptRepo::class);

$activo = $prompts->activo(GateDorado::CLAVE_PROMPT);

if ($activo === null) {
    fwrite(STDERR, 'No hay prompt de conversación activo. Nada que probar.' . PHP_EOL);
    exit(1);
}

printf("Conjunto dorado · prompt v%d · %d caso(s)%s%s", $activo['version'], count($casos), PHP_EOL, PHP_EOL);

$fallos = 0;
$porRegla = [];

foreach ($casos as $caso) {
    $id = (string) $caso['id'];
    $regla = (string) ($caso['regla'] ?? '');
    $mensajes = $caso['mensajes'] ?? [];
    $problemas = [];

    $ultimo = (string) end($mensajes);

    // Los casos de señal crítica no deben tocar el modelo. Se comprueba la
    // detección, no la respuesta.
    if (($caso['sin_llamada_llm'] ?? false) === true) {
        if (!SenalesCriticas::detecta($ultimo)) {
            $problemas[] = 'la señal crítica NO se detectó: habría llegado al modelo';
        }

        informe($id, $regla, $problemas);
        $fallos += $problemas === [] ? 0 : 1;
        $porRegla[$regla] = ($porRegla[$regla] ?? 0) + ($problemas === [] ? 0 : 1);

        continue;
    }

    // Los demás sí llaman al modelo, con el prompt de sistema real.
    $turnos = array_map(
        static fn (string $m): array => ['role' => 'user', 'content' => $m],
        array_map(strval(...), $mensajes),
    );

    try {
        $respuesta = $llm->chat($activo['contenido'], $turnos);
        $texto = Accion::limpiarTexto($respuesta->texto);
        $analisis = Accion::analizar($respuesta->texto);
    } catch (\Throwable $e) {
        informe($id, $regla, ['no se pudo consultar al modelo: ' . $e->getMessage()]);
        $fallos++;

        continue;
    }

    foreach (($caso['prohibido'] ?? []) as $patron) {
        if (preg_match((string) $patron, $texto) === 1) {
            $problemas[] = "dijo algo prohibido — {$patron}";
        }
    }

    foreach (($caso['requerido'] ?? []) as $patron) {
        if (preg_match((string) $patron, $texto) !== 1) {
            $problemas[] = "faltó lo requerido — {$patron}";
        }
    }

    if (array_key_exists('accion_esperada', $caso)) {
        $esperada = $caso['accion_esperada'];

        if ($esperada === null && $analisis->hayAccion()) {
            $problemas[] = 'emitió una acción y no debía: ' . $analisis->accion->nombre;
        } elseif (is_array($esperada)) {
            if (!$analisis->hayAccion()) {
                $problemas[] = 'no emitió acción; se esperaba ' . ($esperada['accion'] ?? '?')
                    . ' (' . $analisis->explicacion() . ')';
            } elseif ($analisis->accion->nombre !== ($esperada['accion'] ?? '')) {
                $problemas[] = 'emitió ' . $analisis->accion->nombre
                    . ' y se esperaba ' . ($esperada['accion'] ?? '?');
            }
        }
    }

    informe($id, $regla, $problemas, $texto);

    if ($problemas !== []) {
        $fallos++;
        $porRegla[$regla] = ($porRegla[$regla] ?? 0) + 1;
    }
}

echo PHP_EOL;
printf("%d de %d caso(s) en rojo.%s", $fallos, count($casos), PHP_EOL);

// Solo una corrida COMPLETA puede habilitar una promoción. Una parcial en
// verde daría una firma sobre evidencia que no existe.
if ($soloCaso !== null) {
    echo 'Corrida parcial: no se registra en modelos_ia.' . PHP_EOL;
    exit($fallos === 0 ? 0 : 1);
}

$modeloId = resolverModelo($bd, $modeloPedido);

if ($modeloId === null) {
    fwrite(STDERR, 'No se encontró el modelo contra el que registrar la corrida.' . PHP_EOL);
    exit(1);
}

$gate->registrarCorrida($modeloId, verde: $fallos === 0, casos: count($casos), fallos: $fallos, detalle: $porRegla);

printf(
    'Registrado en modelos_ia: %s.%s',
    $fallos === 0 ? 'VERDE — el modelo ya puede promoverse' : 'ROJO — la promoción sigue bloqueada',
    PHP_EOL,
);

exit($fallos === 0 ? 0 : 1);

/** @param list<string> $problemas */
function informe(string $id, string $regla, array $problemas, string $texto = ''): void
{
    if ($problemas === []) {
        printf("  ✓ %-16s %s%s", $id, $regla, PHP_EOL);

        return;
    }

    printf("  ✗ %-16s %s%s", $id, $regla, PHP_EOL);

    foreach ($problemas as $problema) {
        printf("      · %s%s", $problema, PHP_EOL);
    }

    if ($texto !== '') {
        // La respuesta completa, porque afinar el prompt sin verla es adivinar.
        printf("      respuesta: %s%s", mb_substr($texto, 0, 400), PHP_EOL);
    }
}

function resolverModelo(App\Core\BD $bd, ?string $identificador): ?string
{
    if ($identificador !== null) {
        $stmt = $bd->pdo()->prepare('SELECT id FROM modelos_ia WHERE identificador = ? LIMIT 1');
        $stmt->execute([$identificador]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    $id = $bd->pdo()->query(
        "SELECT id FROM modelos_ia WHERE proposito = 'conversacion' AND es_primario = 1 LIMIT 1"
    )->fetchColumn();

    if ($id !== false) {
        return (string) $id;
    }

    // Todavía no hay primario —es el caso normal la primera vez— así que se
    // registra contra el candidato: el que está activo y con costo verificado.
    $id = $bd->pdo()->query(
        "SELECT id FROM modelos_ia
          WHERE proposito = 'conversacion' AND activo = 1 AND costos_verificados = 1
          ORDER BY orden_fallback LIMIT 1"
    )->fetchColumn();

    return $id === false ? null : (string) $id;
}
