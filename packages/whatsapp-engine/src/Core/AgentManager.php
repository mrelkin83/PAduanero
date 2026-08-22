<?php
/**
 * ============================================================================
 * AgentManager — configuración del agente (§9)
 * ============================================================================
 * Un agente activo por negocio. La tabla admite varios porque el modelo de
 * datos no cuesta nada, pero el motor usa el activo: dos agentes atendiendo el
 * mismo número solo confunden.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

class AgentManager
{
    private $db;

    public function __construct($db) { $this->db = $db; }

    /** Agente activo, creando uno por defecto si el negocio aún no lo configuró. */
    public function activo(): array
    {
        try {
            $a = $this->db->fetch(
                "SELECT * FROM wa_agentes WHERE activo = 1" . Scope::y() . " ORDER BY id ASC LIMIT 1",
                Scope::mas());
            if ($a) return $a;
        } catch (\Throwable $e) {
            return $this->porDefecto();
        }
        $id = $this->crearPorDefecto();
        $a = $this->db->fetch("SELECT * FROM wa_agentes WHERE id = ?", [$id]);
        return $a ?: $this->porDefecto();
    }

    /**
     * Agente de arranque: sirve desde el primer mensaje sin que nadie configure
     * nada. Un motor que exige rellenar seis campos antes de responder al primer
     * cliente es un motor que no se enciende.
     */
    private function porDefecto(): array
    {
        return [
            'id' => 0,
            'nombre'       => 'Asistente',
            'rol'          => 'Atiendes a los clientes del negocio por WhatsApp.',
            'objetivo'     => 'Resolver dudas, mostrar la carta, tomar pedidos y acompañar el pago.',
            'personalidad' => 'Amable, cercano y directo.',
            'idioma'       => 'es',
            'instrucciones' => '',
            'herramientas' => null,          // null = todas las permitidas por el plan
            'saludo_inicial' => '',
            'mensaje_fuera_horario' => '',
            'mensaje_error' => '',
            'activo' => 1,
        ];
    }

    private function crearPorDefecto(): int
    {
        $d = $this->porDefecto();
        $s = Scope::paraInsert();
        return (int)$this->db->insert(
            "INSERT INTO wa_agentes ({$s['columnas']}nombre, rol, objetivo, personalidad, idioma, activo)
             VALUES ({$s['marcadores']}?,?,?,?,?,1)",
            array_merge($s['valores'],
                [$d['nombre'], $d['rol'], $d['objetivo'], $d['personalidad'], $d['idioma']]));
    }

    public function guardar(array $campos): void
    {
        $permitidos = ['nombre', 'rol', 'objetivo', 'personalidad', 'idioma', 'instrucciones',
                       'herramientas', 'saludo_inicial', 'mensaje_fuera_horario', 'mensaje_error', 'activo'];
        $sets = []; $vals = [];
        foreach ($campos as $k => $v) {
            if (!in_array($k, $permitidos, true)) continue;
            $sets[] = "`$k` = ?";
            $vals[] = $v;
        }
        if (!$sets) return;
        $a = $this->activo();
        if ((int)$a['id'] === 0) {
            $id = $this->crearPorDefecto();
        } else {
            $id = (int)$a['id'];
        }
        $vals[] = $id;
        $this->db->query("UPDATE wa_agentes SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
    }

    /** Mensaje de error al cliente (§43): controlado, sin una sola pista técnica. */
    public function mensajeError(?array $agente = null): string
    {
        $a = $agente ?: $this->activo();
        return $a['mensaje_error'] ?: 'En este momento no puedo completar la operación. Voy a pasar tu solicitud a una persona del equipo.';
    }

    public function mensajeFueraHorario(?array $agente = null): string
    {
        $a = $agente ?: $this->activo();
        return $a['mensaje_fuera_horario'] ?: 'En este momento estamos cerrados 😊 Escríbenos dentro de nuestro horario y con gusto te atendemos.';
    }
}
