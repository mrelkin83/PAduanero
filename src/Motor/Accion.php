<?php

declare(strict_types=1);

namespace App\Motor;

/**
 * Acción emitida por el modelo, extraída y saneada.
 *
 * Corrige dos defectos del motor de referencia (`CLAUDE.md` §7):
 *
 *  · **Regex frágil.** `/\{[\s\S]*?"accion"[\s\S]*?\}/` corta en la primera
 *    llave de cierre, así que falla con objetos anidados y con llaves dentro
 *    de una cadena. Sustituida por un recorrido de llaves balanceadas que
 *    respeta cadenas y escapes.
 *  · **Sin validación de esquema.** Los campos del JSON llegaban directos a
 *    la capa de datos. Ahora hay whitelist por acción: lo que no está
 *    declarado no pasa, y lo que pasa se normaliza a su tipo.
 *
 * El principio de fondo es la regla 12: **el contenido del usuario es dato,
 * no orden**. Lo que devuelve el modelo tampoco es orden — es una propuesta
 * que este objeto acepta o recorta.
 */
final readonly class Accion
{
    /** Campos permitidos por acción. Lo que no esté aquí se descarta. */
    private const ESQUEMAS = [
        'REGISTRAR_CASO' => [
            'tipo_caso', 'area', 'entidad', 'seccional', 'tiene_acto_admin',
            'fecha_acto', 'numero_acto', 'valor_estimado_cop', 'tipo_persona',
            'descripcion', 'urgencia', 'nombre',
        ],
        'VER_SLOTS' => ['modalidadId', 'fecha'],
        'PROPONER_ASESORIA' => ['modalidadId', 'fecha', 'horaInicio'],
        'CANCELAR_CONSULTA' => ['consultaId'],
        'REAGENDAR_CONSULTA' => ['consultaId', 'fecha', 'horaInicio'],
        'ESCALAR_HUMANO' => ['motivo'],
        'FUERA_DE_ALCANCE' => ['area'],
    ];

    /** @param array<string,mixed> $datos */
    private function __construct(
        public string $nombre,
        public array $datos,
    ) {
    }

    public function dato(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->datos[$clave] ?? $porDefecto;
    }

    public function texto(string $clave): ?string
    {
        $valor = $this->datos[$clave] ?? null;

        return (is_string($valor) && trim($valor) !== '') ? trim($valor) : null;
    }

    /**
     * Extrae la primera acción válida, o `null`.
     *
     * Atajo para quien de verdad no necesita saber por qué no hubo acción.
     * **El motor no usa esto**: usa `analizar()`, porque un `null` no permite
     * distinguir «era un turno conversacional» de «el modelo inventó una
     * acción» y esas dos cosas piden respuestas distintas
     * (`docs/CONTRATOS.md` §Errores 15).
     */
    public static function extraer(string $respuesta): ?self
    {
        return self::analizar($respuesta)->accion;
    }

    /**
     * Mira la respuesta del modelo y dice qué encontró **y por qué**.
     *
     * Este es el método bueno. El motor deja `explicacion()` en la nota de
     * diagnóstico, y eso es lo que convierte «el bot respondió raro» en «el
     * modelo pidió REGISTRAR_CASO con una fecha en prosa y se descartó».
     */
    public static function analizar(string $respuesta): AnalisisAccion
    {
        $crudo = self::primerObjetoBalanceado($respuesta);

        if ($crudo === null) {
            // Se distingue «no había ningún objeto» de «había uno y no
            // parseaba»: lo primero es un turno de conversación normal y lo
            // segundo es un prompt que hay que revisar.
            return new AnalisisAccion(
                null,
                str_contains($respuesta, '{') ? AnalisisAccion::JSON_INVALIDO : AnalisisAccion::SIN_JSON,
            );
        }

        $nombre = is_string($crudo['accion'] ?? null) ? $crudo['accion'] : '';
        $permitidos = self::ESQUEMAS[$nombre] ?? null;

        if ($permitidos === null) {
            return new AnalisisAccion(
                null,
                AnalisisAccion::ACCION_DESCONOCIDA,
                nombreCrudo: $nombre === '' ? null : $nombre,
            );
        }

        $datos = [];

        foreach ($permitidos as $campo) {
            if (array_key_exists($campo, $crudo)) {
                $datos[$campo] = $crudo[$campo];
            }
        }

        $descartados = [];
        $saneados = self::normalizar($nombre, $datos, $descartados);

        return new AnalisisAccion(
            new self($nombre, $saneados),
            AnalisisAccion::OK,
            $descartados,
            $nombre,
        );
    }

    /**
     * Quita de un texto los bloques JSON, para no enseñárselos al contacto.
     *
     * Se usa cuando el modelo mezcla texto y JSON pese a tenerlo prohibido.
     * Recorre con el mismo balanceo de llaves en vez de un regex, por lo
     * mismo de antes.
     */
    public static function limpiarTexto(string $respuesta): string
    {
        $salida = $respuesta;

        // Hasta cinco pasadas: si el modelo emitió varios bloques, se quitan
        // todos, pero sin arriesgar un bucle infinito.
        for ($i = 0; $i < 5; $i++) {
            $posicion = strpos($salida, '{');

            if ($posicion === false) {
                break;
            }

            $fin = self::finDelObjeto($salida, $posicion);

            if ($fin === null) {
                break;
            }

            $salida = substr($salida, 0, $posicion) . substr($salida, $fin + 1);
        }

        return trim($salida);
    }

    /** @return array<string,mixed>|null */
    private static function primerObjetoBalanceado(string $texto): ?array
    {
        $desde = 0;

        // Puede haber objetos que no son acciones (un ejemplo, una cita). Se
        // sigue buscando hasta encontrar uno con `accion`.
        while (($inicio = strpos($texto, '{', $desde)) !== false) {
            $fin = self::finDelObjeto($texto, $inicio);

            if ($fin === null) {
                return null;
            }

            $datos = json_decode(substr($texto, $inicio, $fin - $inicio + 1), true);

            if (is_array($datos) && is_string($datos['accion'] ?? null)) {
                return $datos;
            }

            $desde = $inicio + 1;
        }

        return null;
    }

    /** Índice de la llave que cierra el objeto abierto en `$inicio`. */
    private static function finDelObjeto(string $texto, int $inicio): ?int
    {
        $profundidad = 0;
        $enCadena = false;
        $escape = false;
        $largo = strlen($texto);

        for ($i = $inicio; $i < $largo; $i++) {
            $c = $texto[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($c === '\\') {
                $escape = true;
                continue;
            }

            if ($c === '"') {
                $enCadena = !$enCadena;
                continue;
            }

            // Una llave dentro de una cadena no cuenta. Es exactamente lo que
            // rompía el regex del motor de referencia.
            if ($enCadena) {
                continue;
            }

            if ($c === '{') {
                $profundidad++;
            } elseif ($c === '}') {
                $profundidad--;

                if ($profundidad === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $datos
     * @param  array<string,string> $descartados  se llena por referencia: qué
     *                                            campo se cayó y por qué
     * @return array<string,mixed>
     */
    private static function normalizar(string $nombre, array $datos, array &$descartados = []): array
    {
        // Anota el campo cuando el valor que traía se cae o se sustituye. No
        // guarda el valor original, solo el motivo: un `numero_acto` o una
        // `descripcion` en los registros es justo lo que la regla 13 quiere
        // fuera de ahí.
        $anotar = static function (string $campo, string $porQue) use (&$descartados): void {
            $descartados[$campo] = $porQue;
        };

        if (isset($datos['tipo_caso'])) {
            $original = $datos['tipo_caso'];
            $datos['tipo_caso'] = Catalogo::normalizarTipo($original);

            if ($datos['tipo_caso'] !== $original) {
                $anotar('tipo_caso', 'no está en el catálogo, forzado a «otro»');
            }
        }

        if (isset($datos['entidad'])) {
            $entidad = is_string($datos['entidad']) ? mb_strtolower($datos['entidad']) : '';
            $datos['entidad'] = in_array($entidad, Catalogo::ENTIDADES, true) ? $entidad : null;

            if ($datos['entidad'] === null) {
                $anotar('entidad', 'no está en el catálogo de entidades');
            }
        }

        if (isset($datos['urgencia'])) {
            $urgencia = is_string($datos['urgencia']) ? mb_strtolower($datos['urgencia']) : '';

            if (!in_array($urgencia, Catalogo::URGENCIAS, true)) {
                $anotar('urgencia', 'valor desconocido, asumida «media»');
            }

            $datos['urgencia'] = in_array($urgencia, Catalogo::URGENCIAS, true) ? $urgencia : 'media';
        }

        if (isset($datos['area'])) {
            $area = is_string($datos['area']) ? mb_strtolower($datos['area']) : '';
            $datos['area'] = in_array($area, Catalogo::AREAS, true) ? $area : null;

            if ($datos['area'] === null) {
                $anotar('area', 'no es aduanero, tributario ni mixto');
            }
        }

        if (isset($datos['tipo_persona'])) {
            $datos['tipo_persona'] = in_array($datos['tipo_persona'], ['natural', 'juridica'], true)
                ? $datos['tipo_persona']
                : null;

            if ($datos['tipo_persona'] === null) {
                $anotar('tipo_persona', 'no es natural ni jurídica');
            }
        }

        if (array_key_exists('tiene_acto_admin', $datos) && $datos['tiene_acto_admin'] !== null) {
            if (!is_bool($datos['tiene_acto_admin'])) {
                $anotar('tiene_acto_admin', 'no es booleano');
            }

            $datos['tiene_acto_admin'] = is_bool($datos['tiene_acto_admin'])
                ? $datos['tiene_acto_admin']
                : null;
        }

        if (isset($datos['valor_estimado_cop'])) {
            $valor = $datos['valor_estimado_cop'];
            $valido = is_numeric($valor) && $valor >= 0;

            if (!$valido) {
                $anotar('valor_estimado_cop', 'no es un número no negativo');
            }

            $datos['valor_estimado_cop'] = $valido ? (int) round((float) $valor) : null;
        }

        // Las fechas del modelo no son de fiar: se acepta el formato exacto o
        // nada. Una fecha inventada en `fecha_acto` acabaría en la ficha del
        // caso como si fuera un dato del expediente.
        foreach (['fecha_acto', 'fecha'] as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = self::fechaValida($datos[$campo]);

                if ($datos[$campo] === null) {
                    $anotar($campo, 'no es una fecha AAAA-MM-DD real');
                }
            }
        }

        if (isset($datos['horaInicio'])) {
            $datos['horaInicio'] = self::horaValida($datos['horaInicio']);

            if ($datos['horaInicio'] === null) {
                $anotar('horaInicio', 'no es una hora válida');
            }
        }

        if ($nombre === 'ESCALAR_HUMANO') {
            $crudo = is_string($datos['motivo'] ?? null) ? $datos['motivo'] : null;
            $motivo = MotivoEscalamiento::desde($crudo);

            if ($crudo !== null && $crudo !== $motivo->value) {
                $anotar('motivo', 'no está en los seis motivos, asumido «solicitud_expresa»');
            }

            $datos['motivo'] = $motivo->value;
        }

        // Los textos libres se recortan: el modelo puede devolver una novela y
        // `casos.numero_acto` admite 80 caracteres.
        foreach (['seccional' => 80, 'numero_acto' => 80, 'descripcion' => 1000, 'nombre' => 150] as $campo => $max) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $limpio = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $datos[$campo]) ?? '');

                if (mb_strlen($limpio) > $max) {
                    $anotar($campo, "recortado a {$max} caracteres");
                }

                $datos[$campo] = $limpio === '' ? null : mb_substr($limpio, 0, $max);
            }
        }

        return $datos;
    }

    private static function fechaValida(mixed $valor): ?string
    {
        if (!is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return ($fecha !== false && $fecha->format('Y-m-d') === $valor) ? $valor : null;
    }

    private static function horaValida(mixed $valor): ?string
    {
        if (!is_string($valor)) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $valor, $m) !== 1) {
            return null;
        }

        if ((int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }
}
