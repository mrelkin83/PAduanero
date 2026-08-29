<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Core\BD;

final class CertificadoRepo
{
    public function __construct(private readonly BD $bd)
    {
    }

    public function crear(string $compraId, string $codigo): void
    {
        $this->bd->pdo()->prepare(
            'INSERT INTO certificados (compra_id, codigo_verificacion) VALUES (?, ?)'
        )->execute([$compraId, $codigo]);
    }

    /** @return array<string,mixed>|null */
    public function porCompra(string $compraId): ?array
    {
        $stmt = $this->bd->pdo()->prepare('SELECT * FROM certificados WHERE compra_id = ?');
        $stmt->execute([$compraId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /** @return array<string,mixed>|null */
    public function porCodigo(string $codigo): ?array
    {
        $stmt = $this->bd->pdo()->prepare(
            'SELECT cert.codigo_verificacion, cert.emitido_en,
                    comp.nombres, comp.apellidos, c.titulo AS curso_titulo
               FROM certificados cert
               JOIN compras_curso cc ON cc.id = cert.compra_id
               JOIN compradores comp ON comp.id = cc.comprador_id
               JOIN cursos c ON c.id = cc.curso_id
              WHERE cert.codigo_verificacion = ?'
        );
        $stmt->execute([$codigo]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }
}
