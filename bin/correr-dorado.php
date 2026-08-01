<?php

declare(strict_types=1);

/**
 * Corre el conjunto dorado contra el modelo REAL y registra el resultado.
 *
 *   php bin/correr-dorado.php                    ← prompt activo, modelo primario
 *   php bin/correr-dorado.php --prompt=7         ← una versión INACTIVA
 *   php bin/correr-dorado.php claude-opus-6      ← contra otro candidato
 *   php bin/correr-dorado.php --caso=plazo-01    ← un solo caso, para iterar
 *
 * `--prompt=` es lo que hace posible el ciclo entero: una versión nueva no se
 * puede activar hasta que el dorado salga verde contra ella, y no se puede
 * probar si hay que activarla primero. Se prueba inactiva y se activa después.
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
use App\Motor\Afirmacion;
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
$versionPedida = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--caso=')) {
        $soloCaso = substr($arg, 7);
    } elseif (str_starts_with($arg, '--prompt=')) {
        $versionPedida = (int) substr($arg, 9);
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

// El modelo se resuelve ANTES de correr, no después: las llamadas van por
// `chatParaConjuntoDorado()`, que necesita el id explícito. Y esa es la única
// forma de que la primera corrida sea posible — `chat()` consulta el gate, y
// el gate exige justamente la corrida que se está intentando hacer.
$modeloId = resolverModelo($bd, $modeloPedido);

if ($modeloId === null) {
    fwrite(
        STDERR,
        'No hay ningún modelo de conversación activo y con costo verificado.' . PHP_EOL
        . 'Regístrelo en /panel/ia antes de correr el conjunto dorado.' . PHP_EOL,
    );
    exit(1);
}

// La fila completa, no solo el texto: se le pasa entera a `registrarCorrida()`
// para que sea imposible correr con un contenido y registrar contra otro id.
$prompt = $versionPedida !== null
    ? $prompts->porVersion(GateDorado::CLAVE_PROMPT, $versionPedida)
    : $prompts->activo(GateDorado::CLAVE_PROMPT);

if ($prompt === null) {
    fwrite(
        STDERR,
        $versionPedida !== null
            ? "No existe la versión {$versionPedida} del prompt de conversación." . PHP_EOL
            : 'No hay prompt de conversación activo. Nada que probar.' . PHP_EOL,
    );
    exit(1);
}

printf(
    "Conjunto dorado · prompt v%d%s · %d caso(s)%s%s",
    $prompt['version'],
    $versionPedida !== null ? ' (inactiva)' : '',
    count($casos),
    PHP_EOL,
    PHP_EOL,
);

$fallos = 0;
$porRegla = [];

foreach ($casos as $caso) {
    $id = (string) $caso['id'];
    $regla = (string) ($caso['regla'] ?? '');
    $mensajes = $caso['mensajes'] ?? [];
    $problemas = [];

    $ultimo = (string) end($mensajes);

    // Los casos que no deben tocar el modelo. Se comprueba la decisión
    // determinista, no la respuesta.
    if (($caso['sin_llamada_llm'] ?? false) === true) {
        if (($caso['negacion_esperada'] ?? false) === true) {
            // Corolario de la regla 1: la negativa gana. Que esto viva en el
            // conjunto dorado y no solo en las pruebas unitarias es
            // deliberado — es la lista que se revisa cuando aparece una forma
            // nueva de decir que no.
            if (Afirmacion::de($ultimo) !== false) {
                $problemas[] = 'NO se leyó como negativa: se registraría un consentimiento inexistente';
            }
        } elseif (($caso['ambiguo_esperado'] ?? false) === true) {
            if (Afirmacion::de($ultimo) !== null) {
                $problemas[] = 'se interpretó como respuesta algo que no lo es';
            }
        } elseif (!SenalesCriticas::detecta($ultimo)) {
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
        // `chatParaConjuntoDorado` y no `chat`: esta es la única llamada del
        // sistema que puede saltarse el GateDorado, y tiene que poder hacerlo
        // porque la corrida que el gate exige es justamente esta.
        $respuesta = $llm->chatParaConjuntoDorado($prompt['contenido'], $turnos, $modeloId);
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

$gate->registrarCorrida($modeloId, $prompt, verde: $fallos === 0, casos: count($casos), fallos: $fallos, detalle: $porRegla);

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
