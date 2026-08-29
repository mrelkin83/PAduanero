<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

final class CompraCursoRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    public function crear(string $cursoId, string $nombre, string $correo, int $precioCop): string
    {
        $id = (string) $this->bd->pdo()->query('SELECT UUID()')->fetchColumn();

        $this->bd->pdo()->prepare(
            'INSERT INTO compras_curso (id, curso_id, nombre, correo, precio_cop) VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $cursoId, $nombre, $correo, $precioCop]);

        return $id;
    }

    public function guardarReferencia(string $id, string $referenciaWompi, ?string $externoId): void
    {
        $this->bd->pdo()->prepare(
            'UPDATE compras_curso SET referencia_wompi = ?, externo_id = ? WHERE id = ?'
        )->execute([$referenciaWompi, $externoId, $id]);
    }

    public function marcarFallida(string $id): void
    {
        $this->bd->pdo()->prepare("UPDATE compras_curso SET estado = 'fallida' WHERE id = ?")->execute([$id]);
    }

    public function marcarPagada(string $id): void
    {
        $this->bd->pdo()->prepare(
            "UPDATE compras_curso SET estado = 'pagada', pagado_en = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$id]);
    }

    /** @return array<string,mixed>|null */
    public function porId(string $id): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM compras_curso WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Busca una compra pendiente por referencia exacta, y si no aparece —
     * la referencia rota en cada sesión de checkout, trampa documentada en
     * `WompiAdapter` — por `payment_link_id` (que `verificarWebhook()` ya
     * entrega directo del payload) contra `externo_id`, que el checkout
     * (Task 8) guardó con ese mismo valor.
     *
     * @return array<string,mixed>|null
     */
    public function pendientePorReferencia(string $referencia, string $paymentLinkId = ''): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT * FROM compras_curso WHERE referencia_wompi = ? AND estado = 'pendiente'"
        );
        $stmt->execute([$referencia]);
        $fila = $stmt->fetch();

        if ($fila !== false) {
            return $fila;
        }

        if ($paymentLinkId === '') {
            return null;
        }

        $stmt = $this->bd->pdo()->prepare(
            "SELECT * FROM compras_curso WHERE externo_id = ? AND estado = 'pendiente'"
        );
        $stmt->execute([$paymentLinkId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    public function vincularComprador(string $id, string $compradorId): void
    {
        $this->bd->pdo()->prepare('UPDATE compras_curso SET comprador_id = ? WHERE id = ?')
            ->execute([$compradorId, $id]);
    }

    public function tienePagada(string $compradorId, string $cursoId): bool
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM compras_curso
                 WHERE comprador_id = ? AND curso_id = ? AND estado = 'pagada'
             )"
        );
        $stmt->execute([$compradorId, $cursoId]);

        return (bool) $stmt->fetchColumn();
    }

    public function idDePagadaPorComprador(string $compradorId, string $cursoId): ?string
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT id FROM compras_curso
              WHERE comprador_id = ? AND curso_id = ? AND estado = 'pagada'"
        );
        $stmt->execute([$compradorId, $cursoId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    /** @return list<array<string,mixed>> compras pagadas de un comprador, con datos del curso */
    public function pagadasDeComprador(string $compradorId): array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT cc.*, c.titulo, c.slug FROM compras_curso cc
               JOIN cursos c ON c.id = cc.curso_id
              WHERE cc.comprador_id = ? AND cc.estado = 'pagada'
              ORDER BY cc.pagado_en DESC"
        );
        $stmt->execute([$compradorId]);

        return $stmt->fetchAll();
    }

    /** @return list<string> ids de compras pagadas de ese correo, sin comprador vinculado todavía */
    public function pagadasSinVincularPorCorreo(string $correo): array
    {
        $stmt = $this->bd->pdo()->prepare(
            "SELECT id FROM compras_curso WHERE LOWER(correo) = LOWER(?) AND estado = 'pagada' AND comprador_id IS NULL"
        );
        $stmt->execute([$correo]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
