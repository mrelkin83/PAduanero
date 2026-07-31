<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Core\BD;
use App\Excepciones\CredencialNoEncontradaException;
use App\Soporte\Cifrado;
use App\Soporte\Logger;

/**
 * Credenciales cifradas con AES-256-GCM (ADR-011).
 *
 * El valor en claro solo existe en memoria, entre `descifrar()` y quien lo
 * usa. No se registra, no se devuelve por HTTP y no se guarda en caché: una
 * credencial cacheada es una credencial que sobrevive a su propia revocación.
 */
final class CredencialesAes implements Credenciales
{
    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
        private readonly Logger $log,
    ) {
    }

    public function obtener(string $servicio, string $clave, string $entorno = 'produccion'): string
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT id, valor_cifrado FROM credenciales
              WHERE servicio = ? AND clave = ? AND entorno = ? AND activo = 1'
        );
        $stmt->execute([$servicio, $clave, $entorno]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            throw CredencialNoEncontradaException::para($servicio, $clave, $entorno);
        }

        // Auditar ANTES de descifrar: si el descifrado revienta, igual queda
        // constancia de que alguien intentó leer el secreto.
        $this->auditar('credencial', (string) $fila['id'], 'leer', [
            'servicio' => $servicio,
            'entorno' => $entorno,
            // `clave` aquí es el NOMBRE del campo, no el secreto. Se renombra
            // en el detalle para que el redactor del logger no lo confunda.
            'campo' => $clave,
        ]);

        return $this->cifrado->descifrar($this->comoBinario($fila['valor_cifrado']));
    }

    /** @return array{mascara:string} */
    public function guardar(
        string $servicio,
        string $clave,
        string $valor,
        string $entorno,
        string $usuarioId,
    ): array {
        if (trim($valor) === '') {
            throw new \InvalidArgumentException('El valor de la credencial no puede estar vacío.');
        }

        $mascara = Cifrado::mascara($valor);
        $blob = $this->cifrado->cifrar($valor);

        $stmt = $this->bd->pdo()->prepare(
            'INSERT INTO credenciales (servicio, entorno, clave, valor_cifrado, mascara, actualizado_por)
             VALUES (:servicio, :entorno, :clave, :blob, :mascara, :usuario)
             ON DUPLICATE KEY UPDATE
                valor_cifrado    = VALUES(valor_cifrado),
                mascara          = VALUES(mascara),
                actualizado_por  = VALUES(actualizado_por),
                activo           = 1,
                ultima_prueba_en = NULL,
                ultima_prueba_ok = NULL'
        );

        $stmt->bindValue(':servicio', $servicio);
        $stmt->bindValue(':entorno', $entorno);
        $stmt->bindValue(':clave', $clave);
        $stmt->bindValue(':blob', $blob, \PDO::PARAM_LOB);
        $stmt->bindValue(':mascara', $mascara);
        $stmt->bindValue(':usuario', $usuarioId);
        $stmt->execute();

        // Al reemplazar una credencial se limpia el resultado de la última
        // prueba: el verde de la clave vieja no dice nada de la nueva.
        $this->auditar('credencial', null, 'guardar', [
            'servicio' => $servicio,
            'entorno' => $entorno,
            'campo' => $clave,
            'mascara' => $mascara,
        ], $usuarioId);

        return ['mascara' => $mascara];
    }

    /**
     * Conectividad real contra el proveedor.
     *
     * Etapa 0: la infraestructura de auditoría y registro está lista, pero los
     * probadores concretos llegan con cada integración — Wompi en la Etapa 3,
     * Chatwoot y Evolution en la 2, el LLM en la 4. Registrar un `ok: true`
     * inventado sería peor que no tener el botón.
     *
     * @return array{ok:bool,mensaje:string}
     */
    public function probar(string $servicio, string $entorno): array
    {
        $resultado = ['ok' => false, 'mensaje' => "Todavía no hay probador para «{$servicio}»."];

        $stmt = $this->bd->pdo()->prepare(
            'UPDATE credenciales SET ultima_prueba_en = UTC_TIMESTAMP(), ultima_prueba_ok = ?
              WHERE servicio = ? AND entorno = ?'
        );
        $stmt->execute([(int) $resultado['ok'], $servicio, $entorno]);

        $this->auditar('credencial', null, 'probar', [
            'servicio' => $servicio,
            'entorno' => $entorno,
            'ok' => $resultado['ok'],
        ]);

        return $resultado;
    }

    /**
     * Re-cifra todas las credenciales con una clave maestra nueva y sube
     * `key_version`.
     *
     * Todo en una transacción: quedarse a medias dejaría media tabla cifrada
     * con una clave y media con otra, y ninguna de las dos podría leerla
     * entera.
     *
     * Ojo: NO toca `telefono_hash`. El pepper no rota, por eso mismo (ADR-012).
     */
    public function rotarClaveMaestra(string $nuevaClaveB64): void
    {
        $nuevo = new Cifrado($nuevaClaveB64, base64_encode(random_bytes(32)));

        $this->bd->enTransaccion(function (\PDO $pdo) use ($nuevo): void {
            $filas = $pdo->query(
                'SELECT id, valor_cifrado, key_version FROM credenciales FOR UPDATE'
            )->fetchAll();

            $actualizar = $pdo->prepare(
                'UPDATE credenciales SET valor_cifrado = :blob, key_version = :version WHERE id = :id'
            );

            foreach ($filas as $fila) {
                $claro = $this->cifrado->descifrar($this->comoBinario($fila['valor_cifrado']));

                $actualizar->bindValue(':blob', $nuevo->cifrar($claro), \PDO::PARAM_LOB);
                $actualizar->bindValue(':version', ((int) $fila['key_version']) + 1, \PDO::PARAM_INT);
                $actualizar->bindValue(':id', $fila['id']);
                $actualizar->execute();
            }

            $this->log->warn('credenciales.rotacion_clave_maestra', ['filas' => count($filas)]);
        });

        $this->auditar('credencial', null, 'rotar_clave_maestra', []);
    }

    /** @param array<string,mixed> $detalle */
    private function auditar(
        string $entidad,
        ?string $entidadId,
        string $accion,
        array $detalle,
        ?string $actor = null,
    ): void {
        $detalle['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'cli';

        $codificado = json_encode($detalle, JSON_UNESCAPED_UNICODE);

        $this->bd->pdo()->prepare(
            'INSERT INTO auditoria (entidad, entidad_id, accion, actor, detalle) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $entidad,
            $entidadId,
            $accion,
            $actor ?? ($_SESSION['usuario_id'] ?? 'sistema'),
            $codificado === false ? null : $codificado,
        ]);
    }

    /** PDO devuelve los VARBINARY como string o como stream según el driver. */
    private function comoBinario(mixed $valor): string
    {
        if (is_resource($valor)) {
            return (string) stream_get_contents($valor);
        }

        return (string) $valor;
    }
}
