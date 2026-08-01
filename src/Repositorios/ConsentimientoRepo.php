<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;
use App\Modelos\Consentimiento;

/**
 * Habeas data (Ley 1581 de 2012). Todo el SQL de `consentimientos`.
 *
 * Es el repositorio del que depende la **regla 1**: sin consentimiento
 * vigente, el motor no persiste contenido del caso. `vigentePorContacto()` es
 * la consulta que ese gate hace en cada turno, así que tiene que ser barata y
 * tiene que ser exacta — un falso positivo aquí es una infracción.
 */
final class ConsentimientoRepo
{
    private const CAMPOS = 'id, contacto_id, version_politica, texto_mostrado,
                            otorgado, evidencia, otorgado_en, revocado_en';

    public function __construct(private readonly BD $bd)
    {
    }

    /**
     * Registra la respuesta, sea sí o **sea no**.
     *
     * La negativa también deja fila. Sin ella el motor no distingue «dijo que
     * no» de «todavía no le he preguntado», y volvería a preguntarle a alguien
     * que ya se negó — que además de molesto es exactamente lo que la ley no
     * quiere.
     *
     * Se guarda el texto exacto que se mostró, no una referencia a la política
     * vigente: lo que hay que poder demostrar dentro de dos años es qué decía
     * el aviso el día que esta persona respondió.
     *
     * @param array<string,mixed> $evidencia canal, mensaje que lo otorgó,
     *                                       `chatwoot_conv_id`, marca de tiempo
     */
    public function registrar(
        string $contactoId,
        string $versionPolitica,
        string $textoMostrado,
        bool $otorgado,
        array $evidencia = [],
    ): string {
        $pdo = $this->bd->pdo();
        $id = (string) $pdo->query('SELECT UUID()')->fetchColumn();

        $pdo->prepare(
            'INSERT INTO consentimientos
                (id, contacto_id, version_politica, texto_mostrado, otorgado, evidencia)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $contactoId,
            $versionPolitica,
            $textoMostrado,
            $otorgado ? 1 : 0,
            json_encode($evidencia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $id;
    }

    /**
     * El consentimiento que vale hoy, o `null`.
     *
     * «Vigente» son dos condiciones: otorgado y no revocado. Se toma el más
     * reciente porque una persona puede haber revocado y vuelto a aceptar, y
     * lo que gobierna es su última voluntad.
     */
    public function vigentePorContacto(string $contactoId): ?Consentimiento
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM consentimientos
              WHERE contacto_id = ? AND otorgado = 1 AND revocado_en IS NULL
              ORDER BY otorgado_en DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$contactoId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Consentimiento::desdeFila($fila);
    }

    public function tieneVigente(string $contactoId): bool
    {
        return $this->vigentePorContacto($contactoId) !== null;
    }

    /**
     * La última respuesta, diga lo que diga.
     *
     * La usa el motor para no volver a preguntar a quien ya dijo que no.
     */
    public function ultimoPorContacto(string $contactoId): ?Consentimiento
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM consentimientos
              WHERE contacto_id = ? ORDER BY otorgado_en DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$contactoId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Consentimiento::desdeFila($fila);
    }

    /**
     * Revoca todos los consentimientos vigentes del contacto.
     *
     * En plural a propósito: si por lo que sea hay más de uno abierto, revocar
     * solo el último dejaría otro vigente y el gate seguiría dejando pasar.
     *
     * @return int cuántos se revocaron
     */
    public function revocar(string $contactoId): int
    {
        $stmt = $this->bd->pdo()->prepare(
            'UPDATE consentimientos SET revocado_en = NOW()
              WHERE contacto_id = ? AND otorgado = 1 AND revocado_en IS NULL'
        );
        $stmt->execute([$contactoId]);

        return $stmt->rowCount();
    }
}
