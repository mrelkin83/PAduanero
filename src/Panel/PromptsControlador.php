<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;
use App\Repositorios\PromptRepo;
use App\Servicios\GateDorado;

/**
 * Prompts versionados con aprobación (ADR-008).
 *
 * LAS DOS PUERTAS, Y SON DE PERSONAS DISTINTAS
 *
 *  · `ia.prompts.editar` — crear una versión nueva. La tienen el super_admin y
 *    el abogado. Escribir un borrador no compromete a nadie porque nace
 *    inactivo.
 *  · `ia.prompts.aprobar` — activarla. **Solo el abogado.** Es la segunda
 *    asimetría del ADR-007: si el bot dice una barbaridad jurídica, la firma
 *    que la autorizó tiene que ser la suya.
 *
 * Y ENCIMA DE ESAS DOS, EL GATE DORADO
 *
 * Una versión no se activa sin haber pasado el conjunto dorado contra el
 * modelo que está hablando. Sin eso quedaba un hueco por el que se colaba
 * justo lo que ADR-016 impide por el otro lado: no se puede cambiar el modelo
 * sin dorado, pero se conseguía el mismo efecto cambiando el prompt.
 */
final class PromptsControlador extends ControladorBase
{
    public function __construct(
        private readonly PromptRepo $prompts,
        private readonly GateDorado $gate,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }

    public function listar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.prompts.editar');

        $versiones = $this->prompts->versiones(GateDorado::CLAVE_PROMPT);
        $gates = [];

        foreach ($versiones as $version) {
            if ((int) $version['activo'] === 0) {
                $gates[(string) $version['id']] = $this->gate->puedeActivarPrompt((string) $version['id']);
            }
        }

        return $this->vista('panel/prompts', [
            'ctx' => $ctx,
            'versiones' => $versiones,
            'gates' => $gates,
            'activo' => $this->prompts->activo(GateDorado::CLAVE_PROMPT),
            'puedeAprobar' => $ctx->puede('ia.prompts.aprobar'),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /**
     * Crea una versión nueva. Nace **inactiva** (ADR-008).
     *
     * No hay forma de crear una versión y activarla en el mismo gesto, y es
     * deliberado: son dos decisiones, de dos personas distintas, y juntarlas
     * en un botón haría que la segunda se tomara sin pensar.
     */
    public function crear(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.prompts.editar');

        $contenido = trim($ctx->campo('contenido'));

        if (mb_strlen($contenido) < 200) {
            return $this->redirigirCon(
                '/panel/prompts',
                'error',
                'El prompt es demasiado corto. Un prompt de menos de 200 caracteres no '
                . 'puede contener las prohibiciones del §4.',
            );
        }

        $id = $this->prompts->crearVersion(
            GateDorado::CLAVE_PROMPT,
            $contenido,
            $ctx->campo('notas') !== '' ? $ctx->campo('notas') : null,
            $ctx->usuario?->id,
        );

        $version = $this->prompts->porId($id);

        $this->auditoria->registrar(
            'prompt',
            $id,
            'crear_version',
            $ctx->actor(),
            ['clave' => GateDorado::CLAVE_PROMPT, 'version' => $version['version'] ?? null],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/prompts',
            'ok',
            'Versión ' . ($version['version'] ?? '?') . ' creada, inactiva. '
            . 'Corra el conjunto dorado contra ella antes de activarla: '
            . 'php bin/correr-dorado.php --prompt=' . ($version['version'] ?? '?'),
        );
    }

    /**
     * Activa una versión. Solo el abogado, y solo con dorado en verde.
     *
     * Lo que el abogado firma no es la redacción —puede no haberla escrito él,
     * igual que no evalúa un modelo— sino que asume la responsabilidad
     * profesional de lo que el bot diga con esas instrucciones.
     */
    public function activar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.prompts.aprobar');

        $id = $ctx->campo('id');
        $version = $this->prompts->porId($id);

        if ($version === null) {
            return $this->redirigirCon('/panel/prompts', 'error', 'Esa versión no existe.');
        }

        $veredicto = $this->gate->puedeActivarPrompt($id);

        if (!$veredicto['ok']) {
            return $this->redirigirCon('/panel/prompts', 'error', $veredicto['motivo']);
        }

        $anterior = $this->prompts->activo(GateDorado::CLAVE_PROMPT);

        $this->prompts->activar($id, GateDorado::CLAVE_PROMPT, (string) $ctx->usuario?->id);

        $this->auditoria->registrar(
            'prompt',
            $id,
            'activar',
            $ctx->actor(),
            [
                'version' => $version['version'],
                'anterior' => $anterior['version'] ?? null,
            ],
            $ctx->ip(),
        );

        return $this->redirigirCon(
            '/panel/prompts',
            'ok',
            'Versión ' . $version['version'] . ' activa. El bot ya habla con estas instrucciones.',
        );
    }

    /**
     * Diferencias entre dos versiones, línea a línea.
     *
     * Se calcula aquí y no con una librería porque son dos textos de unos
     * pocos miles de caracteres y la comparación exacta importa menos que ver
     * de un vistazo qué frase se añadió o se quitó — que es lo que hay que
     * mirar cuando el bot empieza a comportarse distinto.
     */
    public function diferencias(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.prompts.editar');

        $a = $this->prompts->porId($ctx->campo('a'));
        $b = $this->prompts->porId($ctx->campo('b'));

        if ($a === null || $b === null) {
            return $this->redirigirCon('/panel/prompts', 'error', 'Faltan versiones que comparar.');
        }

        return $this->vista('panel/prompt_diff', [
            'ctx' => $ctx,
            'a' => $a,
            'b' => $b,
            'lineas' => self::diff($a['contenido'], $b['contenido']),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /**
     * @return list<array{signo:string,texto:string}>
     */
    private static function diff(string $antes, string $despues): array
    {
        $a = preg_split('/\R/u', $antes) ?: [];
        $b = preg_split('/\R/u', $despues) ?: [];

        $enB = array_count_values(array_map(strval(...), $b));
        $enA = array_count_values(array_map(strval(...), $a));

        $lineas = [];

        foreach ($a as $linea) {
            if (!isset($enB[$linea])) {
                $lineas[] = ['signo' => '-', 'texto' => (string) $linea];
            }
        }

        foreach ($b as $linea) {
            if (!isset($enA[$linea])) {
                $lineas[] = ['signo' => '+', 'texto' => (string) $linea];
            }
        }

        return $lineas;
    }
}
