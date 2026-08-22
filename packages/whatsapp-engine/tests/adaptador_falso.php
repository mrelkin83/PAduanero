<?php
/**
 * ============================================================================
 * Un negocio de mentira, en memoria
 * ============================================================================
 * Existe para responder a la única pregunta que importa de este paquete:
 * ¿funciona sin el proyecto que lo vio nacer?
 *
 * Aquí no hay MySQL, ni sesiones, ni ControlBarMax. Hay un array con tres
 * productos y siete implementaciones de los puertos que caben en una pantalla.
 * Si el motor arranca contra esto, arranca contra cualquier cosa — y eso es
 * exactamente lo que MayTech POS necesita saber antes de escribir su adaptador.
 *
 * ADEMÁS: este negocio de mentira es una TIENDA, no un bar. Vende cosas con
 * garantía y no tiene cocina. Está hecho así a propósito, para que el contrato
 * se pruebe contra un dominio distinto del que lo inspiró.
 */

namespace ElkinLinan\WhatsappAiEngine\Tests;

use ElkinLinan\WhatsappAiEngine\Ports\ConfigPort;
use ElkinLinan\WhatsappAiEngine\Ports\DbPort;
use ElkinLinan\WhatsappAiEngine\Ports\DomainAdapter;
use ElkinLinan\WhatsappAiEngine\Ports\FormatPort;
use ElkinLinan\WhatsappAiEngine\Ports\SecretPort;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaAvisoInterno;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaArmadoEnPantalla;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaConfigurables;
use ElkinLinan\WhatsappAiEngine\Ports\SoportaGarantias;
use ElkinLinan\WhatsappAiEngine\Ports\StoragePort;

/**
 * Una base de datos que no existe: guarda en un array lo que le insertan y
 * devuelve lo que se le haya preparado con `responder()`.
 */
final class DbFalso implements DbPort
{
    public array $insertados = [];
    public array $consultas  = [];
    private array $respuestas = [];

    /** Prepara lo que devolverá la siguiente consulta que empiece por $prefijo. */
    public function responder(string $prefijo, $valor): void
    {
        $this->respuestas[$prefijo] = $valor;
    }

    private function buscar(string $sql)
    {
        foreach ($this->respuestas as $prefijo => $valor) {
            if (stripos(ltrim($sql), $prefijo) === 0) return $valor;
        }
        return null;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $this->consultas[] = $sql;
        $r = $this->buscar($sql);
        return is_array($r) ? ($r[0] ?? $r) : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->consultas[] = $sql;
        $r = $this->buscar($sql);
        return is_array($r) ? (isset($r[0]) ? $r : [$r]) : [];
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->insertados[] = ['sql' => $sql, 'params' => $params];
        return count($this->insertados);
    }

    public function query(string $sql, array $params = []): int
    {
        $this->consultas[] = $sql;
        return 1;
    }

    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
    public function maestra(): DbPort { return $this; }
    public function conectarA(?string $baseDatos): DbPort { return $this; }
}

/** Cifrado de juguete: al revés y para atrás. Suficiente para probar el viaje. */
final class SecretoFalso implements SecretPort
{
    public function cifrar(string $claro): string { return strrev($claro); }
    public function descifrar(string $cifrado): string { return strrev($cifrado); }
}

/** Archivos en el directorio temporal del sistema. */
final class ArchivoFalso implements StoragePort
{
    public function raiz(): string { return sys_get_temp_dir() . '/wa_engine_test'; }

    public function directorio(): string
    {
        $d = $this->raiz() . '/media';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    public function url(string $rutaRelativa): string { return '/archivos/' . ltrim($rutaRelativa, '/'); }
    public function comprimirImagen(string $bin, int $maxLado = 1024, int $calidad = 78): ?array { return null; }
    public function cabe(int $bytes): bool { return $bytes < 5_000_000; }
}

/** Dólares, para que se note que el formato NO está quemado en el motor. */
final class FormatoFalso implements FormatPort
{
    public function dinero(float $monto): string { return 'US$' . number_format($monto, 2); }
}

final class ConfigFalso implements ConfigPort
{
    public function urlBase(): string { return 'https://tienda.ejemplo'; }
    public function canalUrlPorDefecto(): string { return ''; }
    public function canalApikeyPorDefecto(): string { return ''; }
    public function ttsUrlPorDefecto(): string { return ''; }
    public function ttsModeloPorDefecto(): string { return ''; }
    public function visionUrlPorDefecto(): string { return ''; }
    public function visionModeloPorDefecto(): string { return ''; }
    public function sttUrlPorDefecto(): string { return ''; }
    public function sttModeloPorDefecto(): string { return ''; }
}

/**
 * Un proyecto que guarda a VARIOS negocios en la MISMA base y los separa por
 * una columna. Es la otra forma de ser multi-negocio —la de MayTech POS— y la
 * que el motor no contemplaba: sus consultas no filtraban por nadie, así que
 * `wa_config` devolvía la configuración (y las claves) de quien tuviera la
 * fila 1, y una conversación se leía a través de todos los negocios.
 */
final class NegocioCompartido implements \ElkinLinan\WhatsappAiEngine\Ports\TenantPort
{
    private int $id;
    public function __construct(int $id = 7) { $this->id = $id; }
    public function id(): ?int { return $this->id; }
    public function nombre(): string { return 'Tienda de prueba'; }
    public function baseDatos(): ?string { return null; }
    public function esMultiNegocio(): bool { return true; }
    public function scopeFila(): ?array { return ['columna' => 'empresa_id', 'valor' => $this->id]; }
}

/**
 * LA TIENDA: vende tres cosas, tiene garantía y NO tiene cocina.
 *
 * Es el molde de lo que MayTech POS escribirá: los mismos métodos, con su
 * catálogo real detrás.
 */
final class TiendaFalsa implements DomainAdapter, SoportaGarantias, SoportaAvisoInterno, SoportaConfigurables, SoportaArmadoEnPantalla
{
    public array $confirmadas = [];
    public array $creadas     = [];
    /** Lo que el negocio recibió cuando el motor transfirió a una persona. */
    public array $avisos      = [];

    private array $catalogo = [
        'p1' => ['id' => 'p1', 'nombre' => 'Teléfono X 128 GB', 'descripcion' => 'Sellado, negro', 'precio' => 1200.0, 'stock' => 2],
        'p2' => ['id' => 'p2', 'nombre' => 'Portátil Pro 14',   'descripcion' => 'Reacondicionado', 'precio' => 2300.0, 'stock' => 1],
        'p3' => ['id' => 'p3', 'nombre' => 'Cargador 65 W',     'descripcion' => 'Accesorio',       'precio' => 45.0,   'stock' => 50],
    ];

    public function contextoCliente(array $conversacion): array
    {
        return ['nombre' => $conversacion['nombre_contacto'] ?? 'Cliente', 'compras_previas' => 0];
    }

    public function buscarItems(?string $busqueda = null, array $filtros = [], int $limite = 60): array
    {
        $r = array_values($this->catalogo);
        if ($busqueda) {
            $r = array_values(array_filter($r, fn($p) => stripos($p['nombre'], $busqueda) !== false));
        }
        return array_slice($r, 0, $limite);
    }

    public function detalleItem(string $id): ?array { return $this->catalogo[$id] ?? null; }

    public function disponibilidad(string $id): ?int
    {
        return isset($this->catalogo[$id]) ? (int)$this->catalogo[$id]['stock'] : null;
    }

    /** El precio lo pone el servidor, nunca el modelo. */
    public function calcularTotal(array $items, float $extra = 0.0): array
    {
        $lineas = []; $subtotal = 0.0;
        foreach ($items as $it) {
            $p = $this->catalogo[$it['producto_id']] ?? null;
            if (!$p) throw new \InvalidArgumentException('No tenemos ese producto');
            $cant = max(1, (int)($it['cantidad'] ?? 1));
            $importe = $p['precio'] * $cant;
            $subtotal += $importe;
            $lineas[] = ['producto_id' => $p['id'], 'nombre' => $p['nombre'], 'cantidad' => $cant,
                         'precio_unitario' => $p['precio'], 'importe' => $importe];
        }
        return ['lineas' => $lineas, 'subtotal' => $subtotal, 'total' => $subtotal + $extra];
    }

    public function transaccionesDe(int $conversacionId): array
    {
        $out = [];
        foreach ($this->creadas as $id => $total) {
            $out[] = ['id' => $id, 'total' => $total] + $this->estadoTransaccion($id);
        }
        return $out;
    }

    public function crearTransaccion(array $conversacion, array $items, array $datos = []): array
    {
        $total = 0.0;
        foreach ($items as $it) {
            $p = $this->catalogo[$it['producto_id']] ?? null;
            if (!$p) throw new \InvalidArgumentException('No tenemos ese producto');
            $cant = max(1, (int)($it['cantidad'] ?? 1));
            if ($p['stock'] < $cant) throw new \InvalidArgumentException('Solo queda ' . $p['stock'] . ' de ' . $p['nombre']);
            $this->catalogo[$it['producto_id']]['stock'] -= $cant;
            $total += $p['precio'] * $cant;
        }
        $id = 'V' . (count($this->creadas) + 1);
        $this->creadas[$id] = $total;
        return ['id' => $id, 'total' => $total];
    }

    public function estadoTransaccion(string $id): array
    {
        return isset($this->creadas[$id])
            ? ['estado' => in_array($id, $this->confirmadas, true) ? 'en despacho' : 'esperando pago']
            : [];
    }

    public function cancelarTransaccion(string $id): array
    {
        unset($this->creadas[$id]);
        return ['ok' => true];
    }

    /** En una tienda, «confirmar» es mandar a despacho. No hay cocina. */
    public function confirmarTransaccion(string $id): bool
    {
        $this->confirmadas[] = $id;
        return true;
    }

    public function capacidades(): array { return ['garantias', 'configurables', 'armado_en_pantalla']; }

    /** El motor avisa al negocio DENTRO de su sistema; cada uno sabe cómo. */
    public function avisarEquipo(array $conversacion, string $motivo): void
    {
        $this->avisos[] = ['telefono' => $conversacion['telefono'] ?? '', 'motivo' => $motivo];
    }


    /**
     * El portátil se arma: capacidad y color. No es cosa de restaurantes —un
     * menú del día y un equipo con almacenamiento son el mismo problema— y por
     * eso el puerto vive en el motor.
     */
    public function componentesDe(int $productoId): array
    {
        if ($productoId !== 2) return [];   // solo el Portátil Pro se configura
        return [
            ['id' => 10, 'name' => 'Almacenamiento', 'description' => null,
             'required' => 1, 'min_select' => 1, 'max_select' => 1,
             'opciones' => [
                 ['id' => 101, 'name' => '512 GB', 'description' => null, 'extra_price' => 0.0],
                 ['id' => 102, 'name' => '1 TB',   'description' => null, 'extra_price' => 200.0],
             ]],
            ['id' => 11, 'name' => 'Color', 'description' => null,
             'required' => 0, 'min_select' => 0, 'max_select' => 1,
             'opciones' => [
                 ['id' => 111, 'name' => 'Gris',   'description' => null, 'extra_price' => 0.0],
                 ['id' => 112, 'name' => 'Plata',  'description' => null, 'extra_price' => 0.0],
             ]],
        ];
    }


    /**
     * La tienda manda a su cliente a una pantalla para elegir capacidad y
     * color. El motor no sabe construir URLs del proyecto: solo pregunta.
     */
    public array $enlacesPedidos = [];

    public function enlaceParaArmar(int $productoId, int $cantidad, array $conversacion): ?string
    {
        if ($productoId !== 2) return null;   // solo el Portátil se arma
        $this->enlacesPedidos[] = ['producto' => $productoId, 'cantidad' => $cantidad,
                                   'telefono' => $conversacion['telefono'] ?? ''];
        return 'https://tienda.ejemplo/armar/' . str_repeat('b', 32);
    }

    public function consultarGarantia(string $identificador): array
    {
        return $identificador === '355000000000001'
            ? ['vigente' => true, 'vence' => '2027-01-31', 'meses' => 12]
            : ['vigente' => false];
    }
}
