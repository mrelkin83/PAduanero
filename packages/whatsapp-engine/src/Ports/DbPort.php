<?php

namespace ElkinLinan\WhatsappAiEngine\Ports;

/**
 * La base de datos, en lo mínimo que el motor usa de verdad.
 *
 * No es un ORM ni pretende serlo: son consultas con parámetros. En
 * ControlBarMax lo cumple su clase `Database`; en otro proyecto, PDO pelado.
 */
interface DbPort
{
    /** Una fila o null. */
    public function fetch(string $sql, array $params = []): ?array;

    /** Todas las filas (array vacío si no hay). */
    public function fetchAll(string $sql, array $params = []): array;

    /** INSERT que devuelve el id nuevo. */
    public function insert(string $sql, array $params = []): int;

    /** UPDATE/DELETE/DDL: devuelve filas afectadas. */
    public function query(string $sql, array $params = []): int;

    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;

    /**
     * La base COMPARTIDA entre negocios, si el proyecto es multi-negocio.
     * Aquí vive el índice que permite a un webhook sin cookies ni sesión saber
     * a qué negocio pertenece. Un proyecto de un solo negocio devuelve $this.
     */
    public function maestra(): DbPort;

    /**
     * Conexión a la base de OTRO negocio, por nombre.
     *
     * Lo necesita el webhook: llega un mensaje sin cookies, sin sesión y sin
     * subdominio garantizado, y hay que decidir EN QUÉ BASE se escribe antes
     * de descifrar nada — con la clave equivocada los secretos se descifran
     * mal y en silencio. Un proyecto de un solo negocio devuelve $this.
     */
    public function conectarA(?string $baseDatos): DbPort;
}
