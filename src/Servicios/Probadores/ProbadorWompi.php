<?php

declare(strict_types=1);

namespace App\Servicios\Probadores;

use App\Soporte\Http;

/**
 * «Probar conexión» contra Wompi.
 *
 * Los códigos que distingue están verificados empíricamente contra el sandbox
 * público, no deducidos de la documentación:
 *
 *   GET /v1/merchants/{llave_publica}
 *     200 → la llave pública existe y el comercio está activo
 *     422 → la llave no corresponde a ningún comercio
 *     404 → la ruta no existe (cambió la API)
 *
 *   GET /v1/transactions   con  Authorization: Bearer {llave_privada}
 *     200 → la llave privada autoriza
 *     401 → no se envió token
 *     422 → el token no es válido
 *
 * Comprueba en TRES fases, y el orden importa porque cada una descarta una
 * causa distinta: primero los prefijos (sin salir a red), luego la pública,
 * luego la privada. Así el mensaje dice qué arreglar en vez de «falló».
 */
final class ProbadorWompi implements Probador
{
    private const URLS = [
        'produccion' => 'https://production.wompi.co/v1',
        'pruebas' => 'https://sandbox.wompi.co/v1',
    ];

    /** Prefijo que debe llevar cada llave según el entorno. */
    private const PREFIJOS = [
        'produccion' => ['llave_publica' => 'pub_prod_', 'llave_privada' => 'prv_prod_'],
        'pruebas' => ['llave_publica' => 'pub_test_', 'llave_privada' => 'prv_test_'],
    ];

    public function __construct(private readonly Http $http)
    {
    }

    public function servicio(): string
    {
        return 'wompi';
    }

    /** @return list<string> */
    public function clavesRequeridas(): array
    {
        return ['llave_publica', 'llave_privada'];
    }

    /**
     * @param  array<string,string> $credenciales
     * @return array{ok:bool,mensaje:string,detalle?:array<string,mixed>}
     */
    public function probar(array $credenciales, string $entorno): array
    {
        $base = self::URLS[$entorno] ?? null;

        if ($base === null) {
            return ['ok' => false, 'mensaje' => "Entorno desconocido: «{$entorno}»."];
        }

        $publica = trim($credenciales['llave_publica'] ?? '');
        $privada = trim($credenciales['llave_privada'] ?? '');

        // ── Fase 1: prefijos, sin salir a red ────────────────────────────
        //
        // Mezclar llaves de sandbox con entorno de producción es la causa más
        // común de «el webhook no valida la firma» (RUNBOOK §3.3). Detectarlo
        // aquí ahorra horas frente a un 422 genérico de la pasarela.
        foreach (self::PREFIJOS[$entorno] as $clave => $prefijo) {
            $valor = $clave === 'llave_publica' ? $publica : $privada;

            if ($valor === '') {
                return ['ok' => false, 'mensaje' => "Falta la {$this->nombre($clave)}."];
            }

            if (!str_starts_with($valor, $prefijo)) {
                $otro = $entorno === 'produccion' ? 'pruebas' : 'producción';

                return [
                    'ok' => false,
                    'mensaje' => sprintf(
                        'La %s no corresponde al entorno de %s: debería empezar por «%s» y empieza por «%s». '
                        . '¿Se pegó la llave de %s?',
                        $this->nombre($clave),
                        $entorno === 'produccion' ? 'producción' : 'pruebas',
                        $prefijo,
                        mb_substr($valor, 0, 9),
                        $otro,
                    ),
                ];
            }
        }

        // ── Fase 2: la llave pública ─────────────────────────────────────
        $r = $this->http->pedir('GET', "{$base}/merchants/{$publica}");

        if (!$r->huboRespuesta()) {
            return [
                'ok' => false,
                'mensaje' => 'No se pudo contactar con Wompi. Revisar salida a internet y DNS del servidor.',
                'detalle' => ['error_red' => $r->errorRed],
            ];
        }

        if ($r->estado === 422) {
            return ['ok' => false, 'mensaje' => 'Wompi no reconoce la llave pública. Revisar que esté completa.'];
        }

        if ($r->estado === 404) {
            return ['ok' => false, 'mensaje' => 'Wompi respondió 404: cambió la ruta de la API. Revisar su documentación.'];
        }

        if (!$r->ok()) {
            return ['ok' => false, 'mensaje' => "Wompi respondió HTTP {$r->estado} al validar la llave pública."];
        }

        $comercio = $r->json()['data'] ?? [];
        $nombreComercio = is_string($comercio['name'] ?? null) ? $comercio['name'] : null;

        // ── Fase 3: la llave privada ─────────────────────────────────────
        //
        // La pública es, por definición, pública: que valide no dice nada
        // sobre si tenemos permiso de cobrar. Eso lo dice la privada.
        $rp = $this->http->pedir('GET', "{$base}/transactions", [
            'Authorization' => 'Bearer ' . $privada,
        ]);

        if (!$rp->huboRespuesta()) {
            return [
                'ok' => false,
                'mensaje' => 'La llave pública validó, pero se perdió la conexión al comprobar la privada.',
                'detalle' => ['error_red' => $rp->errorRed],
            ];
        }

        if ($rp->estado === 401 || $rp->estado === 422) {
            return [
                'ok' => false,
                'mensaje' => 'La llave pública es correcta, pero Wompi rechaza la privada. '
                    . 'Revisar que se copió completa y del mismo comercio.',
            ];
        }

        if (!$rp->ok()) {
            return ['ok' => false, 'mensaje' => "Wompi respondió HTTP {$rp->estado} al validar la llave privada."];
        }

        return [
            'ok' => true,
            'mensaje' => $nombreComercio !== null
                ? "Conexión correcta con «{$nombreComercio}» en entorno de "
                    . ($entorno === 'produccion' ? 'producción' : 'pruebas') . '.'
                : 'Conexión correcta: ambas llaves validan.',
            'detalle' => [
                'comercio' => $nombreComercio,
                'entorno' => $entorno,
                'latencia_ms' => $r->latenciaMs + $rp->latenciaMs,
                'metodos_aceptados' => $comercio['accepted_payment_methods'] ?? null,
            ],
        ];
    }

    private function nombre(string $clave): string
    {
        return $clave === 'llave_publica' ? 'llave pública' : 'llave privada';
    }
}
