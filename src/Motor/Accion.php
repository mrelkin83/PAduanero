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
     * Extrae la primera acción válida de la respuesta del modelo.
     *
     * Devuelve `null` cuando no hay acción, que es el caso normal: la mayoría
     * de los turnos son conversación.
     */
    public static function extraer(string $respuesta): ?self
    {
        $crudo = self::primerObjetoBalanceado($respuesta);

        return $crudo === null ? null : self::sanear($crudo);
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

    /** @param array<string,mixed> $crudo */
    private static function sanear(array $crudo): ?self
    {
        $nombre = is_string($crudo['accion'] ?? null) ? $crudo['accion'] : '';
        $permitidos = self::ESQUEMAS[$nombre] ?? null;

        if ($permitidos === null) {
            return null;
        }

        $datos = [];

        foreach ($permitidos as $campo) {
            if (array_key_exists($campo, $crudo)) {
                $datos[$campo] = $crudo[$campo];
            }
        }

        return new self($nombre, self::normalizar($nombre, $datos));
    }

    /**
     * @param  array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private static function normalizar(string $nombre, array $datos): array
    {
        if (isset($datos['tipo_caso'])) {
            $datos['tipo_caso'] = Catalogo::normalizarTipo($datos['tipo_caso']);
        }

        if (isset($datos['entidad'])) {
            $entidad = is_string($datos['entidad']) ? mb_strtolower($datos['entidad']) : '';
            $datos['entidad'] = in_array($entidad, Catalogo::ENTIDADES, true) ? $entidad : null;
        }

        if (isset($datos['urgencia'])) {
            $urgencia = is_string($datos['urgencia']) ? mb_strtolower($datos['urgencia']) : '';
            $datos['urgencia'] = in_array($urgencia, Catalogo::URGENCIAS, true) ? $urgencia : 'media';
        }

        if (isset($datos['area'])) {
            $area = is_string($datos['area']) ? mb_strtolower($datos['area']) : '';
            $datos['area'] = in_array($area, Catalogo::AREAS, true) ? $area : null;
        }

        if (isset($datos['tipo_persona'])) {
            $datos['tipo_persona'] = in_array($datos['tipo_persona'], ['natural', 'juridica'], true)
                ? $datos['tipo_persona']
                : null;
        }

        if (array_key_exists('tiene_acto_admin', $datos)) {
            $datos['tiene_acto_admin'] = is_bool($datos['tiene_acto_admin'])
                ? $datos['tiene_acto_admin']
                : null;
        }

        if (isset($datos['valor_estimado_cop'])) {
            $valor = $datos['valor_estimado_cop'];
            $datos['valor_estimado_cop'] = (is_numeric($valor) && $valor >= 0)
                ? (int) round((float) $valor)
                : null;
        }

        // Las fechas del modelo no son de fiar: se acepta el formato exacto o
        // nada. Una fecha inventada en `fecha_acto` acabaría en la ficha del
        // caso como si fuera un dato del expediente.
        foreach (['fecha_acto', 'fecha'] as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = self::fechaValida($datos[$campo]);
            }
        }

        if (isset($datos['horaInicio'])) {
            $datos['horaInicio'] = self::horaValida($datos['horaInicio']);
        }

        if ($nombre === 'ESCALAR_HUMANO') {
            $datos['motivo'] = MotivoEscalamiento::desde(
                is_string($datos['motivo'] ?? null) ? $datos['motivo'] : null,
            )->value;
        }

        // Los textos libres se recortan: el modelo puede devolver una novela y
        // `casos.numero_acto` admite 80 caracteres.
        foreach (['seccional' => 80, 'numero_acto' => 80, 'descripcion' => 1000, 'nombre' => 150] as $campo => $max) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $limpio = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $datos[$campo]) ?? '');
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
