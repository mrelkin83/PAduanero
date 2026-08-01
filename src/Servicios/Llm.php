<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Excepciones\CredencialNoEncontradaException;
use App\Excepciones\LlmException;
use App\Servicios\ClientesLlm\ClienteLlm;
use App\Servicios\ClientesLlm\FalloProveedor;
use App\Soporte\Fechas;
use App\Soporte\Logger;

/**
 * La única puerta hacia un modelo de lenguaje.
 *
 * Tres invariantes, y las tres fallan en silencio si se relajan:
 *
 *  1. **`consumo_ia` se escribe SIEMPRE**, también cuando el proveedor falla.
 *     Un timeout que no deja huella hace que el gasto y la tasa de error
 *     mientan: el panel enseña un sistema barato y sano que en realidad está
 *     quemando reintentos contra un proveedor caído.
 *
 *  2. **El presupuesto se verifica ANTES de llamar**, no después. Después es
 *     un informe, no un tope. Y un modelo sin costo **corta**: es el mismo
 *     patrón del error 15 —un guardia que deja de guardar sin avisar—,
 *     porque a costo cero el presupuesto no se agota jamás.
 *
 *  3. **La cascada no puede caer en un modelo sin firma.** Ni de otro
 *     propósito, ni sin costo verificado, ni sin conjunto dorado en verde. Si
 *     el primario muere y el suplente no está autorizado, la conducta
 *     correcta es escalar a humano, no responder. Un fallback que se salta el
 *     `GateDorado` convierte todo el mecanismo de firma en decorativo justo
 *     el día que importa (ADR-016).
 */
final class Llm
{
    /** @var array<string,ClienteLlm> por formato_api */
    private array $clientes = [];

    /** @param iterable<ClienteLlm> $clientes */
    public function __construct(
        private readonly BD $bd,
        private readonly Credenciales $credenciales,
        private readonly Config $config,
        private readonly GateDorado $gate,
        private readonly Logger $log,
        iterable $clientes,
    ) {
        foreach ($clientes as $cliente) {
            $this->clientes[$cliente->formato()] = $cliente;
        }
    }

    /**
     * @param  array<int,array{role:string,content:string}> $mensajes
     * @throws LlmException si no hay modelo autorizado, se agotó el
     *                      presupuesto, o fallaron todos los de la cascada
     */
    public function chat(
        string $systemPrompt,
        array $mensajes,
        ?int $maxTokens = null,
        string $proposito = 'conversacion',
        ?string $casoId = null,
    ): RespuestaLlm {
        $candidatos = $this->cascada($proposito);

        if ($candidatos === []) {
            throw LlmException::sinModeloAutorizado($proposito);
        }

        // ANTES de llamar. Después sería un informe del daño, no un tope.
        $this->exigirPresupuesto();

        $ultimoFallo = null;
        $intentos = 0;

        foreach ($candidatos as $indice => $modelo) {
            $intentos++;

            try {
                $respuesta = $this->intentar($modelo, $systemPrompt, $mensajes, $maxTokens);

                $this->registrarConsumo(
                    $modelo,
                    $casoId,
                    $respuesta->tokensEntrada,
                    $respuesta->tokensSalida,
                    $respuesta->latenciaMs,
                    exito: true,
                );

                if ($indice > 0) {
                    // Que responda el suplente no es un error, pero tampoco es
                    // normal: si nadie lo mira, el primario puede llevar una
                    // semana caído sin que se note en la conversación.
                    $this->log->warn('llm.respondio_fallback', [
                        'primario' => $candidatos[0]['identificador'],
                        'uso' => $modelo['identificador'],
                    ]);

                    return new RespuestaLlm(
                        texto: $respuesta->texto,
                        tokensEntrada: $respuesta->tokensEntrada,
                        tokensSalida: $respuesta->tokensSalida,
                        modeloId: $respuesta->modeloId,
                        modeloIdentificador: $respuesta->modeloIdentificador,
                        latenciaMs: $respuesta->latenciaMs,
                        huboFallback: true,
                    );
                }

                return $respuesta;
            } catch (FalloProveedor $e) {
                // El registro va también en el fallo. Invariante 1.
                $this->registrarConsumo(
                    $modelo,
                    $casoId,
                    0,
                    0,
                    $e->latenciaMs,
                    exito: false,
                    error: $e->getMessage(),
                );

                $this->log->warn('llm.fallo_modelo', [
                    'modelo' => $modelo['identificador'],
                    'motivo' => $e->getMessage(),
                    'reintentable' => $e->reintentable,
                ]);

                $ultimoFallo = $e;

                // Un 400 o un 401 es problema nuestro: el suplente va a fallar
                // igual, y bajar la cascada solo multiplica latencia y gasto
                // antes de dar el mismo error.
                if (!$e->reintentable) {
                    break;
                }
            } catch (CredencialNoEncontradaException $e) {
                $this->registrarConsumo(
                    $modelo,
                    $casoId,
                    0,
                    0,
                    0,
                    exito: false,
                    error: $e->getMessage(),
                );

                $ultimoFallo = $e;
            }
        }

        $this->log->error('llm.cascada_agotada', [
            'proposito' => $proposito,
            'intentos' => $intentos,
            'ultimo' => $ultimoFallo?->getMessage(),
        ]);

        throw LlmException::proveedoresCaidos($intentos);
    }

    /**
     * Modelos que pueden responder, en orden.
     *
     * Cada condición del WHERE es una invariante y ninguna es redundante:
     *
     *  · `proposito = ?` — un modelo de embeddings no conversa. Sin esto, la
     *    cascada podría bajar de un modelo de chat a uno de vectores.
     *  · `activo = 1` y `retirado_en IS NULL` — lo apagado y lo retirado no
     *    entran ni como suplentes.
     *  · `costos_verificados = 1` y los costos no nulos — invariante 2: si
     *    entrara uno sin precio, su gasto contaría como cero y el presupuesto
     *    dejaría de agotarse.
     *  · el proveedor activo — desactivar un proveedor tiene que apagar sus
     *    modelos, o desactivarlo no sirve de nada.
     *
     * Y por encima del SQL, el `GateDorado`, que es lo que el CHECK no puede
     * ver: que la corrida dorada siga vigente frente al prompt activo.
     *
     * @return list<array<string,mixed>>
     */
    private function cascada(string $proposito): array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT m.*, p.clave AS proveedor_clave, p.base_url, p.formato_api
               FROM modelos_ia m
               JOIN proveedores_ia p ON p.id = m.proveedor_id
              WHERE m.proposito = ?
                AND m.activo = 1
                AND m.retirado_en IS NULL
                AND m.costos_verificados = 1
                AND m.costo_entrada_usd_1m IS NOT NULL
                AND m.costo_salida_usd_1m IS NOT NULL
                AND p.activo = 1
              ORDER BY m.es_primario DESC, m.orden_fallback, m.identificador'
        );
        $stmt->execute([$proposito]);

        $autorizados = [];

        foreach ($stmt->fetchAll() as $modelo) {
            $veredicto = $this->gate->puedeResponder($modelo);

            if ($veredicto['ok']) {
                $autorizados[] = $modelo;
                continue;
            }

            // Un suplente que no pasa el gate no se usa en silencio: se dice.
            // Si el primario cae y esto no queda registrado, alguien va a
            // preguntarse por qué el bot escaló en vez de responder.
            $this->log->warn('llm.suplente_sin_firma', [
                'modelo' => $modelo['identificador'],
                'motivo' => $veredicto['motivo'],
            ]);
        }

        return $autorizados;
    }

    /**
     * @param  array<string,mixed>                          $modelo
     * @param  array<int,array{role:string,content:string}> $mensajes
     * @throws FalloProveedor
     */
    private function intentar(
        array $modelo,
        string $systemPrompt,
        array $mensajes,
        ?int $maxTokens,
    ): RespuestaLlm {
        $formato = (string) $modelo['formato_api'];
        $cliente = $this->clientes[$formato]
            ?? throw new FalloProveedor("No hay cliente para el formato «{$formato}»", reintentable: false);

        // Ollama en la propia máquina no pide credencial.
        $secreto = $formato === 'ollama'
            ? null
            : $this->credenciales->obtener((string) $modelo['proveedor_clave'], 'api_key');

        return $cliente->chat(
            (string) $modelo['base_url'],
            $secreto,
            $modelo,
            $systemPrompt,
            $mensajes,
            $maxTokens ?? (int) ($modelo['max_tokens_default'] ?? 600),
        );
    }

    /**
     * Corta si el gasto del mes ya superó el tope.
     *
     * El mes es el de **Bogotá**, no el de UTC: es un presupuesto de negocio y
     * el negocio vive aquí. El límite se calcula en PHP como inicio de mes
     * local y se convierte a UTC para comparar contra `creado_en`, que la base
     * guarda en UTC. Comparar la columna contra `DATE_FORMAT(NOW(),'%Y-%m-01')`
     * a secas desplazaría el corte cinco horas — que en la madrugada del día 1
     * significa gastar contra el presupuesto del mes que acaba de cerrar.
     *
     * @throws LlmException
     */
    private function exigirPresupuesto(): void
    {
        $tope = (float) $this->config->get('presupuesto_ia_mensual_usd', 0);

        if ($tope <= 0) {
            return;
        }

        $gastado = $this->gastoDelMes();

        if ($gastado >= $tope) {
            $this->log->error('llm.presupuesto_agotado', ['gastado' => $gastado, 'tope' => $tope]);

            throw LlmException::presupuestoAgotado($gastado, $tope);
        }
    }

    /**
     * Una fila por intento, salga bien o mal.
     *
     * El costo se calcula aquí y no se recibe: dejar que lo pase quien llama
     * abriría la puerta a que un fallo lo reporte como cero, y con eso el
     * presupuesto volvería a no agotarse nunca.
     *
     * @param array<string,mixed> $modelo
     */
    private function registrarConsumo(
        array $modelo,
        ?string $casoId,
        int $tokensEntrada,
        int $tokensSalida,
        int $latenciaMs,
        bool $exito,
        ?string $error = null,
    ): void {
        $costo = ($tokensEntrada / 1_000_000) * (float) $modelo['costo_entrada_usd_1m']
               + ($tokensSalida / 1_000_000) * (float) $modelo['costo_salida_usd_1m'];

        $this->bd->pdo()->prepare(
            'INSERT INTO consumo_ia
                (modelo_id, caso_id, tokens_entrada, tokens_salida, costo_usd,
                 latencia_ms, exito, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (string) $modelo['id'],
            $casoId,
            $tokensEntrada,
            $tokensSalida,
            round($costo, 6),
            $latenciaMs,
            $exito ? 1 : 0,
            $error === null ? null : mb_substr($error, 0, 1000),
        ]);
    }

    /**
     * Gasto del mes en curso. Lo usan el tope y el tablero.
     *
     * El mes es el de **Bogotá**, no el de UTC: es un presupuesto de negocio y
     * el negocio vive aquí. El límite se calcula como inicio de mes local y se
     * convierte a UTC para comparar contra `creado_en`, que la base guarda en
     * UTC. Usar `DATE_FORMAT(NOW(),'%Y-%m-01')` a secas desplazaría el corte
     * cinco horas: en la madrugada del día 1 se estaría gastando contra el
     * presupuesto del mes que acaba de cerrar (CONTRATOS.md §Errores 17).
     */
    public function gastoDelMes(): float
    {
        $ahora = Fechas::ahora();

        $inicioMes = Fechas::paraBd(
            $ahora->setDate((int) $ahora->format('Y'), (int) $ahora->format('n'), 1)
                  ->setTime(0, 0),
        );

        $stmt = $this->bd->pdo()->prepare(
            'SELECT COALESCE(SUM(costo_usd), 0) FROM consumo_ia WHERE creado_en >= ?'
        );
        $stmt->execute([$inicioMes]);

        return (float) $stmt->fetchColumn();
    }
}
