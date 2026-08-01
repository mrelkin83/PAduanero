<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;
use App\Modelos\Contacto;
use App\Soporte\Cifrado;

/**
 * Todo el SQL de `contactos` vive aquí y solo aquí (docs/CONTRATOS.md).
 *
 * Dos cosas que este repositorio hace y que no son obvias:
 *
 *  · **`telefono_hash` con HMAC, no con SHA-256 a secas** (ADR-012). Un
 *    número de doce dígitos se rompe por fuerza bruta en segundos; con el
 *    pepper, no. Y el pepper es variable propia que nunca rota, porque un
 *    hash no es reversible: el día que rotara, todos los hashes quedarían
 *    huérfanos y la búsqueda dejaría de encontrar en silencio.
 *
 *  · **El NIT no sale en el DTO.** Se cifra al guardar y solo se descifra
 *    llamando a `nit()` a propósito, que además deja huella. Ver `Contacto`.
 */
final class ContactoRepo
{
    private const CAMPOS = 'id, chatwoot_contact_id, telefono, nombre, tipo_persona,
                            razon_social, nit_cifrado, ciudad, canal_origen,
                            utm_source, utm_campaign, bloqueado, creado_en';

    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }

    public function porId(string $id): ?Contacto
    {
        return $this->uno('SELECT ' . self::CAMPOS . ' FROM contactos WHERE id = ?', [$id]);
    }

    /**
     * Búsqueda por teléfono.
     *
     * Va por `telefono_hash` y no por `telefono` aunque ambos estén indexados,
     * porque es el camino que seguirá funcionando el día que el número en
     * claro deje de guardarse. Hoy el número está ahí —hace falta para
     * escribirle— pero la búsqueda no debería depender de ello.
     */
    public function porTelefono(string $telefonoE164): ?Contacto
    {
        return $this->uno(
            'SELECT ' . self::CAMPOS . ' FROM contactos WHERE telefono_hash = ?',
            [$this->cifrado->hashTelefono($telefonoE164)],
        );
    }

    public function porChatwootId(int $chatwootContactId): ?Contacto
    {
        return $this->uno(
            'SELECT ' . self::CAMPOS . ' FROM contactos WHERE chatwoot_contact_id = ?',
            [$chatwootContactId],
        );
    }

    /**
     * Crea el contacto, o devuelve el que ya existía con ese número.
     *
     * Que sea idempotente no es comodidad: en WhatsApp el mismo número escribe
     * meses después y no puede aparecer dos veces, y dos mensajes simultáneos
     * de un contacto nuevo llegarían a crear dos filas. El `UNIQUE` sobre
     * `telefono` lo impide, y aquí se captura el 1062 para devolver el
     * existente en vez de reventar en plena conversación.
     */
    public function crear(
        string $telefonoE164,
        string $canalOrigen,
        ?int $chatwootContactId = null,
        ?string $nombre = null,
        ?string $utmSource = null,
        ?string $utmCampaign = null,
    ): Contacto {
        $pdo = $this->bd->pdo();
        $id = (string) $pdo->query('SELECT UUID()')->fetchColumn();

        try {
            $pdo->prepare(
                'INSERT INTO contactos
                    (id, telefono, telefono_hash, chatwoot_contact_id, nombre,
                     canal_origen, utm_source, utm_campaign)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $telefonoE164,
                $this->cifrado->hashTelefono($telefonoE164),
                $chatwootContactId,
                $nombre,
                $canalOrigen,
                $utmSource,
                $utmCampaign,
            ]);
        } catch (\PDOException $e) {
            // 1062 es el duplicado de MySQL — el 23505 de Postgres (ADR-005).
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            $existente = $this->porTelefono($telefonoE164);

            if ($existente === null) {
                // El duplicado fue por `chatwoot_contact_id`, no por teléfono:
                // el mismo humano escribiendo desde otro canal.
                $existente = $chatwootContactId !== null
                    ? $this->porChatwootId($chatwootContactId)
                    : null;
            }

            if ($existente === null) {
                throw $e;
            }

            return $existente;
        }

        return $this->porId($id) ?? throw new \RuntimeException('El contacto no se pudo releer.');
    }

    public function actualizarNombre(string $id, string $nombre): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE contactos SET nombre = ? WHERE id = ?')
            ->execute([mb_substr(trim($nombre), 0, 150), $id]);
    }

    public function actualizarTipoPersona(
        string $id,
        string $tipoPersona,
        ?string $razonSocial = null,
    ): void {
        if (!in_array($tipoPersona, ['natural', 'juridica'], true)) {
            return;
        }

        $this->bd->pdo()
            ->prepare('UPDATE contactos SET tipo_persona = ?, razon_social = COALESCE(?, razon_social) WHERE id = ?')
            ->execute([$tipoPersona, $razonSocial, $id]);
    }

    /** Vincula el contacto de Chatwoot cuando se conoce después de crearlo. */
    public function vincularChatwoot(string $id, int $chatwootContactId): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE contactos SET chatwoot_contact_id = ? WHERE id = ?')
            ->execute([$chatwootContactId, $id]);
    }

    public function ciudad(string $id, string $ciudad): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE contactos SET ciudad = ? WHERE id = ?')
            ->execute([mb_substr(trim($ciudad), 0, 100), $id]);
    }

    /**
     * Guarda el NIT cifrado (regla 13).
     *
     * Formato del blob: `v1 ‖ nonce(12) ‖ tag(16) ‖ ciphertext` (ADR-011).
     * El NIT identifica a una empresa ante la DIAN; en claro en la base es
     * exactamente lo que no debe estar si alguien se lleva un volcado.
     */
    public function guardarNit(string $id, string $nit): void
    {
        $limpio = preg_replace('/[^0-9\-]/', '', $nit) ?? '';

        if ($limpio === '') {
            return;
        }

        $this->bd->pdo()
            ->prepare('UPDATE contactos SET nit_cifrado = ? WHERE id = ?')
            ->execute([$this->cifrado->cifrar($limpio), $id]);
    }

    /**
     * Descifra el NIT. Deja huella en `auditoria`.
     *
     * El registro no es burocracia: si un día hay que responder «¿quién
     * consultó los NIT de nuestros clientes?», sin esta fila la respuesta es
     * «no se puede saber».
     */
    public function nit(string $id, string $actor): ?string
    {
        $stmt = $this->bd->pdo()->prepare('SELECT nit_cifrado FROM contactos WHERE id = ?');
        $stmt->execute([$id]);
        $blob = $stmt->fetchColumn();

        if ($blob === false || $blob === null) {
            return null;
        }

        $this->auditoria->registrar('contacto', $id, 'leer_nit', $actor);

        return $this->cifrado->descifrar(is_resource($blob) ? (string) stream_get_contents($blob) : (string) $blob);
    }

    public function bloquear(string $id, bool $bloqueado = true): void
    {
        $this->bd->pdo()
            ->prepare('UPDATE contactos SET bloqueado = ? WHERE id = ?')
            ->execute([$bloqueado ? 1 : 0, $id]);
    }

    /** @param list<mixed> $parametros */
    private function uno(string $sql, array $parametros): ?Contacto
    {
        $stmt = $this->bd->pdo()->prepare($sql);
        $stmt->execute($parametros);
        $fila = $stmt->fetch();

        return $fila === false ? null : Contacto::desdeFila($fila);
    }
}
