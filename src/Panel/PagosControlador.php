<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\Respuesta;
use App\Repositorios\CredencialRepo;
use App\Servicios\Config;
use App\Servicios\Credenciales;

/**
 * Pagos: pasarela activa, credenciales y «Probar conexión».
 *
 * Aquí vive la asimetría más importante del ADR-007: **el abogado no ve
 * credenciales**. Puede mirar transacciones y política de reembolso, pero
 * `pagos.credenciales.*` es solo del `super_admin`. No las necesita y no
 * debería poder filtrarlas.
 *
 * Y el invariante que gobierna todo el módulo: el valor descifrado de una
 * credencial **no sale nunca** en una respuesta HTTP. Lo único que viaja al
 * navegador es la máscara.
 */
final class PagosControlador extends ControladorBase
{
    private const CLAVES_POR_PASARELA = [
        'wompi' => ['llave_publica', 'llave_privada', 'clave_eventos', 'clave_integridad'],
        'bold' => ['llave_identidad', 'llave_secreta'],
        'mercadopago' => ['access_token', 'public_key'],
    ];

    public function __construct(
        private readonly Credenciales $credenciales,
        private readonly CredencialRepo $repo,
        private readonly Config $config,
        private readonly string $urlBase,
    ) {
    }

    public function inicio(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'pagos.transacciones.ver');

        $pasarela = (string) $this->config->get('pasarela_activa', 'wompi');
        $verCredenciales = $ctx->puede('pagos.credenciales.ver');

        return $this->vista('panel/pagos', [
            'ctx' => $ctx,
            'pasarela' => $pasarela,
            'claves' => self::CLAVES_POR_PASARELA[$pasarela] ?? [],
            'verCredenciales' => $verCredenciales,
            // Solo máscaras y metadatos. El valor real no se consulta
            // siquiera para pintar esta pantalla.
            'estado' => $verCredenciales ? $this->estado($pasarela) : [],
            'urlWebhook' => $this->urlBase . '/webhooks/pagos',
            'politicaReembolso' => (string) $this->config->get('politica_reembolso', ''),
            'horasCancelacion' => (int) $this->config->get('horas_cancelacion_sin_costo', 24),
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function guardarCredencial(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'pagos.credenciales.escribir');

        $servicio = $ctx->campo('servicio');
        $clave = $ctx->campo('clave');
        $entorno = $ctx->campo('entorno', 'produccion');
        $valor = $ctx->campo('valor');

        if (!isset(self::CLAVES_POR_PASARELA[$servicio])
            || !in_array($clave, self::CLAVES_POR_PASARELA[$servicio], true)) {
            return $this->redirigirCon('/panel/pagos', 'error', 'Credencial no reconocida.');
        }

        if (!in_array($entorno, ['produccion', 'pruebas'], true)) {
            return $this->redirigirCon('/panel/pagos', 'error', 'Entorno no válido.');
        }

        try {
            $resultado = $this->credenciales->guardar($servicio, $clave, $valor, $entorno, $ctx->usuario->id);
        } catch (\InvalidArgumentException $e) {
            return $this->redirigirCon('/panel/pagos', 'error', $e->getMessage());
        }

        return $this->redirigirCon(
            '/panel/pagos',
            'ok',
            "Guardada «{$clave}» ({$entorno}): {$resultado['mascara']}",
        );
    }

    public function probar(Contexto $ctx): Respuesta
    {
        // Probar descifra las credenciales para hablar con la pasarela, así
        // que exige el permiso de LECTURA de credenciales, no el de ver
        // transacciones.
        $ctx->permisos->exigir($ctx->usuario, 'pagos.credenciales.ver');

        $servicio = $ctx->campo('servicio', (string) $this->config->get('pasarela_activa', 'wompi'));
        $entorno = $ctx->campo('entorno', 'produccion');

        $resultado = $this->credenciales->probar($servicio, $entorno);

        return $this->redirigirCon(
            '/panel/pagos',
            $resultado['ok'] ? 'ok' : 'error',
            $resultado['mensaje'],
        );
    }

    /**
     * Máscaras y resultado de la última prueba. Nunca valores.
     *
     * @return array<string,array<string,mixed>>
     */
    private function estado(string $pasarela): array
    {
        $salida = [];

        foreach (['produccion', 'pruebas'] as $entorno) {
            foreach (self::CLAVES_POR_PASARELA[$pasarela] ?? [] as $clave) {
                $salida[$entorno][$clave] = null;
            }
        }

        // Va por CredencialRepo y no por el servicio: su SELECT no incluye
        // `valor_cifrado`, así que por esta ruta no hay nada que descifrar ni
        // que se pueda imprimir por error.
        foreach ($this->repo->resumen($pasarela) as $fila) {
            $salida[$fila['entorno']][$fila['clave']] = $fila;
        }

        return $salida;
    }
}
