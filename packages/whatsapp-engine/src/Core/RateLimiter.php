<?php
/**
 * ============================================================================
 * RateLimiter — techo de mensajes por teléfono y cupo de conversaciones (§2 y §3)
 * ============================================================================
 * Las dos protecciones que impiden que un solo número, o un mes muy bueno,
 * agoten la cuota del proveedor de IA en minutos.
 *
 * LA DIFERENCIA ENTRE LAS DOS, que no es de matiz:
 *
 *   - El techo por teléfono protege TU DINERO de un número concreto. Es
 *     temporal: pasada la ventana, ese cliente vuelve a ser atendido solo.
 *   - El cupo del plan protege el MODELO DE NEGOCIO. Es del mes, y no se
 *     levanta esperando: se levanta cambiando de plan.
 *
 * Por eso el primero calla y el segundo explica. Un cliente al que se corta por
 * ráfaga volverá a escribir en cinco minutos y le contestarán; a uno al que se
 * corta por cupo hay que decirle que llame por teléfono, o lo pierdes.
 *
 * NINGUNA de las dos toca al humano: si un operador está atendiendo la
 * conversación, esto ni se consulta (el webhook corta antes). Un límite que
 * amordazara a una persona real sería un fallo, no una protección.
 */

namespace ElkinLinan\WhatsappAiEngine\Core;

class RateLimiter
{
    /**
     * Valores por defecto: 15 mensajes en 5 minutos.
     *
     * Están medidos contra una conversación real de compra, no elegidos a ojo:
     * pedir, elegir tres productos, dar la dirección y confirmar son ~10
     * mensajes en un par de minutos. 15 deja margen para el que escribe
     * entrecortado —«hola» / «buenas» / «quiero pedir»—, que es lo normal en
     * WhatsApp, y sigue cortando una ráfaga automática de golpe.
     */
    const MENSAJES_DEF = 15;
    const VENTANA_DEF  = 5;

    private $db;
    private $log;

    public function __construct($db, $log = null)
    {
        $this->db  = $db;
        $this->log = $log;
    }

    /**
     * ¿Se puede atender este mensaje?
     *
     * @return array{permitido:bool, avisar:bool, mensaje:string, motivo:string, recibidos:int, techo:int}
     *   `avisar` es la clave: cuando se corta, solo el PRIMER mensaje de la
     *   ráfaga lleva respuesta. Contestar «espera un momento» cien veces sería
     *   la misma inundación al revés — y, del lado de WhatsApp, la mejor forma
     *   de que le cierren el número al negocio por comportamiento automático.
     */
    public function comprobar(array $conv, ?array $cfg): array
    {
        $ok = ['permitido' => true, 'avisar' => false, 'mensaje' => '', 'motivo' => '',
               'recibidos' => 0, 'techo' => 0];

        $techo   = (int)($cfg['limite_mensajes'] ?? self::MENSAJES_DEF);
        $ventana = (int)($cfg['limite_ventana_minutos'] ?? self::VENTANA_DEF);
        if ($techo <= 0) return $ok;                      // 0 = sin límite, a propósito
        $ventana = max(1, $ventana);

        $convId = (int)$conv['id'];
        $n = (int)($this->db->fetch(
            "SELECT COUNT(*) c FROM wa_mensajes
             WHERE conversacion_id = ? AND direccion = 'entrante'
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$convId, $ventana])['c'] ?? 0);

        $ok['recibidos'] = $n;
        $ok['techo']     = $techo;
        if ($n <= $techo) return $ok;

        // Se avisa UNA VEZ POR VENTANA. La marca va en su columna y no en
        // `contexto`, que es del agente: mezclar ahí el control de abuso haría
        // que un `guardarContexto` del orquestador se llevara la marca por
        // delante y el aviso volviera a salir en cada mensaje.
        $avisar = empty($conv['limite_avisado_at'])
               || strtotime((string)$conv['limite_avisado_at']) < time() - $ventana * 60;
        if ($avisar) {
            $this->db->query("UPDATE wa_conversaciones SET limite_avisado_at = NOW() WHERE id = ?", [$convId]);
        }
        if ($this->log) {
            $this->log->log('mensaje', 'Techo de mensajes alcanzado; no se consulta a la IA',
                ['recibidos' => $n, 'techo' => $techo, 'ventana_min' => $ventana, 'avisado' => $avisar], $convId);
        }

        return ['permitido' => false, 'avisar' => $avisar, 'motivo' => 'rafaga',
                'mensaje'   => 'Recibí tus mensajes 🙌 Dame un momento y te respondo con calma.',
                'recibidos' => $n, 'techo' => $techo];
    }

    /**
     * Cupo de conversaciones del mes que da el plan (§3).
     *
     * Se pregunta ANTES de abrir una conversación nueva, nunca sobre una ya
     * abierta: cortar a mitad de un pedido dejaría el carrito montado, el stock
     * descontado y al cliente esperando. Quien ya entró, termina.
     *
     * @param int|null $techo Solo para pruebas: fuerza el cupo en vez de
     *   preguntárselo al plan. El motor llama SIEMPRE sin argumento; sin esta
     *   costura, el corte real solo se podría probar levantando una master de
     *   mentira, y en la práctica se quedaría sin probar.
     * @return array{agotado:bool, usadas:int, techo:int, mensaje:string}
     */
    public function cupoConversaciones(?int $techo = null): array
    {
        $out = ['agotado' => false, 'usadas' => 0, 'techo' => 0, 'mensaje' => ''];

        if ($techo === null) $techo = self::techoDelPlan();
        if ($techo === null) return $out;                 // sin plan, sin cupo o ilimitado

        $usadas = (new ConversationManager($this->db))->conversacionesDelMes();
        $out['usadas'] = $usadas;
        $out['techo']  = $techo;
        if ($usadas < $techo) return $out;

        $out['agotado'] = true;
        $out['mensaje'] = 'En este momento no puedo atenderte por aquí 🙏 '
                        . 'Por favor llámanos o escríbenos más tarde.';
        if ($this->log) {
            $this->log->log('mensaje', 'Cupo de conversaciones del mes agotado',
                ['usadas' => $usadas, 'techo' => $techo]);
        }
        return $out;
    }

    /**
     * Techo del plan, o NULL si no aplica.
     *
     * Falla ABIERTO a propósito: sin SaaS, sin tenant, sin plan o con la master
     * por detrás del código, devuelve null y no corta nada. Un fallo de lectura
     * del plan no puede dejar a un negocio que sí paga sin atender a sus
     * clientes — el riesgo de equivocarse por exceso es mucho peor que el de
     * regalar unas conversaciones.
     */
    public static function techoDelPlan(): ?int
    {
        if (!\ElkinLinan\WhatsappAiEngine\Engine::negocio()->esMultiNegocio()) return null;
        if (!\ElkinLinan\WhatsappAiEngine\Engine::negocio()->id()) return null;

        // El PROYECTO sabe dónde vive su plan. Aquí estaba escrito
        // `FROM planes JOIN tenants` —el esquema de suscripciones de
        // ControlBarMax— dentro de un paquete que también sirve a MayTech POS,
        // que no tiene tabla `tenants`: allí la consulta reventaba, el
        // try/catch se la tragaba y el cupo no cortaba nunca, en silencio.
        $techo = \ElkinLinan\WhatsappAiEngine\Engine::funciones()->limite('max_conversaciones_whatsapp');
        if ($techo === null) return null;

        // 0 = el plan no define cupo · 9999 = ilimitado (convenio del proyecto)
        return ($techo <= 0 || $techo >= 9999) ? null : $techo;
    }
}
