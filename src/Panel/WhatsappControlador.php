<?php

declare(strict_types=1);

namespace App\Panel;

use App\Core\BD;
use App\Core\Respuesta;
use App\Repositorios\AuditoriaRepo;
use App\Soporte\Cifrado;
use App\Soporte\Entorno;
use App\Soporte\Logger;
use App\Wa\GoogleCalendar;
use App\Wa\MotorWa;
use ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;
use ElkinLinan\WhatsappAiEngine\Providers\ModelDiscoveryService;

/**
 * El bot de WhatsApp, administrado desde el panel: conexión, IA, cobro,
 * agente, horario, Google Calendar, citas y conversaciones.
 *
 * Reutiliza los permisos que el motor de 2026-08 dejó sembrados:
 *
 *   ia.proveedores.ver / escribir   ver la pantalla · tocar conexión e IA
 *   motor.killswitch                encender y apagar
 *   ia.prompts.editar               la ruta de conversación (wa_agentes)
 *   config.editar                   cobro (modo) y horario
 *   pagos.credenciales.escribir     credenciales de Wompi
 *   agenda.ver                      citas
 *   casos.ver                       conversaciones
 *
 * Los secretos jamás vuelven al formulario (WaConfig::paraFrontend): un campo
 * de secreto enviado vacío no pisa el guardado. Y el token del webhook se
 * enseña UNA vez, en la respuesta del POST que lo genera — después solo
 * existe su hash.
 */
final class WhatsappControlador extends ControladorBase
{
    public function __construct(
        private readonly BD $bd,
        private readonly Cifrado $cifrado,
        private readonly Logger $log,
        private readonly AuditoriaRepo $auditoria,
    ) {
    }

    private function db(): \App\Wa\DbMotor
    {
        return MotorWa::conectar($this->bd, $this->cifrado, $this->log, dirname(__DIR__, 2));
    }

    /* ── Pantalla principal ───────────────────────────────────────────── */

    public function ver(Contexto $ctx, array $extra = []): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.ver');
        $db = $this->db();

        $cfg = WaConfig::paraFrontend($db);

        // Mientras no se haya guardado nada, la conexión llega diligenciada
        // con los valores del despliegue (Evolution corre en el mismo VPS):
        // quien abre la pantalla solo pega la API Key, que sí es secreta.
        // Prellenar no es guardar — persiste al pulsar «Guardar conexión».
        if (empty($cfg['evolution_url'])) {
            $cfg['evolution_url'] = rtrim((string) (Entorno::obtener('EVOLUTION_URL', 'http://127.0.0.1:8080') ?? ''), '/');
        }
        if (empty($cfg['evolution_instancia'])) {
            $cfg['evolution_instancia'] = (string) (Entorno::obtener('EVOLUTION_INSTANCIA', 'pedro') ?? 'pedro');
        }

        $agente = $db->fetch('SELECT * FROM wa_agentes WHERE id = 1') ?? [];
        $google = new GoogleCalendar($this->bd, $this->cifrado, $this->log);

        // El estado real de la instancia, solo si hay conexión configurada:
        // preguntarle a un Evolution sin URL solo añade segundos a la carga.
        $estado = null;
        if (!empty($cfg['evolution_url']) && !empty($cfg['evolution_instancia'])) {
            try {
                $canal = EvolutionClient::desdeConfig($db);
                $estado = $canal ? $canal->estado() : null;
            } catch (\Throwable) {
                $estado = null;
            }
        }

        $citasProximas = $db->fetchAll(
            "SELECT COUNT(*) n FROM wa_citas WHERE slot_activo = 1 AND inicio >= NOW()"
        )[0]['n'] ?? 0;

        return $this->vista('panel/whatsapp', [
            'ctx' => $ctx,
            'cfg' => $cfg,
            'agente' => $agente,
            // La lista del motor, no una copia: un proveedor que no esté aquí
            // se vería en el desplegable pero estaría muerto al usarlo.
            'proveedores' => \ElkinLinan\WhatsappAiEngine\Providers\LlmProviderManager::PROVEEDORES,
            'sttProveedores' => \ElkinLinan\WhatsappAiEngine\Media\SttManager::PROVEEDORES,
            'estado' => $estado,
            'googleConectado' => $google->conectado(),
            'urlAutorizacion' => $this->urlAutorizacionSiHayCliente($google),
            'horario' => WaConfig::horario(WaConfig::cargar($db, true)),
            'smtpConfigurado' => \App\Soporte\Smtp::desdeEntorno() !== null,
            'citasProximas' => (int) $citasProximas,
            'avisos' => $this->avisos($ctx),
        ] + $extra);
    }

    private function urlAutorizacionSiHayCliente(GoogleCalendar $google): ?string
    {
        try {
            $url = $google->urlAutorizacion($this->redirectGoogle());

            // Sin client_id la URL sale coja: mejor no enseñarla.
            return str_contains($url, 'client_id=&') ? null : $url;
        } catch (\Throwable) {
            return null;
        }
    }

    private function redirectGoogle(): string
    {
        return rtrim((string) (Entorno::obtener('APP_URL', '') ?? ''), '/') . '/oauth/google';
    }

    /* ── Conexión e IA ────────────────────────────────────────────────── */

    public function guardarConexion(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');
        $db = $this->db();

        // La tubería (URL y API Key) la fija el despliegue y el panel no la
        // toca: del formulario solo se acepta el nombre de la instancia,
        // venga lo que venga en el POST. Si la URL aún no está sembrada, se
        // toma la del entorno — el formulario no puede dejarla a medias.
        $datos = ['evolution_instancia' => $ctx->campo('evolution_instancia')];
        $cfg = WaConfig::cargar($db, true) ?? [];
        if (empty($cfg['evolution_url'])) {
            $datos['evolution_url'] = rtrim((string) (Entorno::obtener('EVOLUTION_URL', 'http://127.0.0.1:8080') ?? ''), '/');
        }
        WaConfig::guardar($db, $datos);
        $this->auditar($ctx, 'conexion');

        return $this->redirigirCon('/panel/whatsapp', 'ok', 'Conexión guardada.');
    }

    public function guardarMedia(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        // Un valor fuera del catálogo se guarda como vacío (visión: heredar de
        // la IA principal; STT/TTS: desactivado) — nunca un proveedor inventado.
        $en = static fn (string $v, array $validos): string => in_array($v, $validos, true) ? $v : '';
        $modo = $ctx->campo('tts_modo');

        WaConfig::guardar($this->db(), [
            'vision_proveedor' => $en($ctx->campo('vision_proveedor'), ['anthropic', 'openai', 'gemini', 'local']),
            'vision_modelo' => $ctx->campo('vision_modelo'),
            'vision_url' => rtrim($ctx->campo('vision_url'), '/'),
            'vision_api_key' => $ctx->campo('vision_api_key'),
            'stt_proveedor' => $en($ctx->campo('stt_proveedor'), array_keys(\ElkinLinan\WhatsappAiEngine\Media\SttManager::PROVEEDORES)),
            'stt_modelo' => $ctx->campo('stt_modelo'),
            'stt_url' => rtrim($ctx->campo('stt_url'), '/'),
            'stt_api_key' => $ctx->campo('stt_api_key'),
            // El modo es un enum NOT NULL: lo no reconocido cae al recomendado.
            'tts_modo' => in_array($modo, ['nunca', 'siempre', 'espejo', 'texto_y_audio'], true) ? $modo : 'espejo',
            'tts_proveedor' => $en($ctx->campo('tts_proveedor'), ['elevenlabs', 'openai', 'piper']),
            'tts_voice_id' => $ctx->campo('tts_voice_id'),
            'tts_modelo' => $ctx->campo('tts_modelo'),
            'tts_url' => rtrim($ctx->campo('tts_url'), '/'),
            'tts_api_key' => $ctx->campo('tts_api_key'),
        ]);
        $this->auditar($ctx, 'media');

        return $this->redirigirCon('/panel/whatsapp', 'ok', 'Voz e imágenes guardadas.');
    }

    public function guardarIa(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        WaConfig::guardar($this->db(), [
            'llm_proveedor' => $ctx->campo('llm_proveedor'),
            'llm_modelo' => $ctx->campo('llm_modelo'),
            'llm_api_key' => $ctx->campo('llm_api_key'),
            'llm_fallback_proveedor' => $ctx->campo('llm_fallback_proveedor'),
            'llm_fallback_modelo' => $ctx->campo('llm_fallback_modelo'),
            'llm_fallback_api_key' => $ctx->campo('llm_fallback_api_key'),
        ]);
        $this->auditar($ctx, 'ia');

        return $this->redirigirCon('/panel/whatsapp', 'ok', 'Proveedor de IA guardado.');
    }

    /* ── Modelos (JSON para el desplegable) ───────────────────────────── */

    /** Lo que ya se conoce del proveedor, desde wa_modelos: rápido y sin red. */
    public function modelos(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.ver');

        $mds = new ModelDiscoveryService($this->db());

        return Respuesta::json([
            'ok' => true,
            'modelos' => $mds->listar((string) ($ctx->peticion->consulta['proveedor'] ?? '')),
            'nuevos' => $mds->nuevosSinRevisar(),
        ]);
    }

    /**
     * Pregunta al proveedor qué modelos ofrece HOY y actualiza wa_modelos.
     *
     * La clave RECIÉN ESCRITA en el formulario manda sobre la guardada —
     * lección vivida en el proyecto de origen: usando solo la guardada,
     * «buscar modelos» justo después de pegar la clave fallaba con «sin API
     * Key» y no había forma de adivinar que primero había que guardar.
     */
    public function sincronizarModelos(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');
        $db = $this->db();

        $cfg = WaConfig::cargar($db, true) ?? [];
        $proveedor = $ctx->campo('proveedor', (string) ($cfg['llm_proveedor'] ?? ''));
        if ($proveedor === '') {
            return Respuesta::json(['ok' => false, 'error' => 'Elige primero el proveedor']);
        }

        $clave = $ctx->campo('api_key');
        if ($clave === '') {
            $clave = WaConfig::secreto($cfg, 'llm_api_key');
            if ($proveedor === ($cfg['llm_fallback_proveedor'] ?? null)) {
                $clave = WaConfig::secreto($cfg, 'llm_fallback_api_key') ?: $clave;
            }
        }
        if ($clave === '') {
            return Respuesta::json(['ok' => false,
                'error' => 'Escribe la API Key del proveedor (o guárdala) antes de buscar modelos']);
        }

        $mds = new ModelDiscoveryService($db);
        $r = $mds->sincronizar($proveedor, $clave);
        $r['modelos'] = $r['ok'] ? $mds->listar($proveedor) : [];
        $this->auditar($ctx, 'modelos_sincronizar', ['proveedor' => $proveedor, 'ok' => $r['ok']]);

        return Respuesta::json($r);
    }

    /* ── Encendido ────────────────────────────────────────────────────── */

    public function encender(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'motor.killswitch');
        $db = $this->db();

        $cfg = WaConfig::cargar($db, true) ?? [];
        $faltan = [];
        if (empty($cfg['evolution_url']) || empty($cfg['evolution_instancia'])) {
            $faltan[] = 'la conexión con Evolution';
        }
        if (empty($cfg['llm_proveedor']) || empty($cfg['llm_modelo']) || empty($cfg['llm_api_key'])) {
            $faltan[] = 'el proveedor de IA con su clave';
        }
        if ($faltan) {
            return $this->redirigirCon('/panel/whatsapp', 'error',
                'Para encender falta: ' . implode(' y ', $faltan) . '.');
        }

        WaConfig::guardar($db, ['activo' => 1]);
        $this->auditar($ctx, 'encender');

        return $this->redirigirCon('/panel/whatsapp', 'ok',
            'Motor encendido. Recuerde: el bot trata datos personales — la política de tratamiento debe estar publicada.');
    }

    public function apagar(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'motor.killswitch');

        WaConfig::guardar($this->db(), ['activo' => 0]);
        $this->auditar($ctx, 'apagar');

        return $this->redirigirCon('/panel/whatsapp', 'ok', 'Motor apagado: el bot deja de responder ya.');
    }

    /* ── Token del webhook y QR ───────────────────────────────────────── */

    /**
     * Genera el token, registra el webhook en Evolution con él, y enseña las
     * dos URLs UNA sola vez — en esta respuesta, nunca en una redirección:
     * un token en la query acabaría en el historial y en los logs del
     * servidor.
     */
    public function regenerarToken(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');
        $db = $this->db();

        $token = WaConfig::regenerarWebhookToken($db);
        $base = rtrim($ctx->campo('webhook_base', (string) (Entorno::obtener('APP_URL', '') ?? '')), '/');

        $urlWebhook = $base . '/api/wa/webhook/' . $token;
        $registro = null;
        try {
            $canal = EvolutionClient::desdeConfig($db);
            $registro = $canal ? $canal->registrarWebhook($urlWebhook) : null;
        } catch (\Throwable $e) {
            $registro = ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->auditar($ctx, 'token');

        return $this->ver($ctx, [
            'tokenNuevo' => [
                'webhook' => $urlWebhook,
                'pago' => $base . '/api/wa/pago/' . $token,
                'registrado' => (bool) ($registro['ok'] ?? false),
                'error' => (string) ($registro['error'] ?? ''),
            ],
        ]);
    }

    /** Crea la instancia si hace falta y enseña el QR en línea. */
    public function conectarQr(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');
        $db = $this->db();

        $canal = EvolutionClient::desdeConfig($db);
        if (!$canal) {
            return $this->redirigirCon('/panel/whatsapp', 'error',
                'Primero guarde la conexión con Evolution (URL, instancia y API Key).');
        }

        $r = $canal->conectar();
        if (!$r['ok']) {
            return $this->redirigirCon('/panel/whatsapp', 'error', mb_substr('QR: ' . $r['error'], 0, 290));
        }

        return $this->ver($ctx, ['qr' => (string) $r['qr']]);
    }

    /* ── Cobro y horario ──────────────────────────────────────────────── */

    public function guardarCobro(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'config.editar');

        $modo = $ctx->campo('pago_modo');
        if (!in_array($modo, ['contra_entrega', 'wompi', 'manual', 'mixto', 'todos'], true)) {
            return $this->redirigirCon('/panel/whatsapp', 'error', 'Modo de cobro desconocido.');
        }

        // Los métodos de transferencia llegan por campos separados y aquí se
        // COMPONE el texto que el bot dicta. El motor sigue leyendo solo el
        // texto; el JSON existe para volver a pintar el formulario.
        $t = [
            'nequi' => preg_replace('/\D+/', '', $ctx->campo('trans_nequi')) ?? '',
            'daviplata' => preg_replace('/\D+/', '', $ctx->campo('trans_daviplata')) ?? '',
            'breb' => $ctx->campo('trans_breb'),
            'banco_nombre' => $ctx->campo('trans_banco_nombre'),
            'banco_tipo' => in_array($ctx->campo('trans_banco_tipo'), ['ahorros', 'corriente'], true)
                ? $ctx->campo('trans_banco_tipo') : 'ahorros',
            'banco_numero' => $ctx->campo('trans_banco_numero'),
            'titular' => $ctx->campo('trans_titular'),
        ];

        foreach (['nequi', 'daviplata'] as $celular) {
            if ($t[$celular] !== '' && !preg_match('/^3\d{9}$/', $t[$celular])) {
                return $this->redirigirCon('/panel/whatsapp', 'error',
                    'El número de ' . ucfirst($celular) . ' debe ser un celular colombiano de 10 dígitos (3XXXXXXXXX).');
            }
        }
        if ($t['banco_nombre'] !== '' xor $t['banco_numero'] !== '') {
            return $this->redirigirCon('/panel/whatsapp', 'error',
                'Para la cuenta bancaria hacen falta el banco Y el número de cuenta.');
        }

        $hayMetodo = $t['nequi'] !== '' || $t['daviplata'] !== '' || $t['breb'] !== '' || $t['banco_numero'] !== '';
        if ($hayMetodo && $t['titular'] === '') {
            // Sin titular, el cliente no puede verificar a quién le consigna:
            // es justo el dato que evita el «transferí a un desconocido».
            return $this->redirigirCon('/panel/whatsapp', 'error',
                'Falta el titular: es lo que le permite al cliente verificar a quién transfiere.');
        }

        $lineas = [];
        if ($t['nequi'] !== '') {
            $lineas[] = 'Nequi: ' . $t['nequi'];
        }
        if ($t['daviplata'] !== '') {
            $lineas[] = 'Daviplata: ' . $t['daviplata'];
        }
        if ($t['breb'] !== '') {
            $lineas[] = 'Bre-B (llave): ' . $t['breb'];
        }
        if ($t['banco_numero'] !== '') {
            $lineas[] = $t['banco_nombre'] . ' — cuenta de ' . $t['banco_tipo'] . ' Nº ' . $t['banco_numero'];
        }
        if ($hayMetodo) {
            $lineas[] = 'Titular: ' . $t['titular'];
        }

        WaConfig::guardar($this->db(), [
            'pago_modo' => $modo,
            'pago_datos_transferencia' => mb_substr(implode("\n", $lineas), 0, 400),
            'pago_transferencia_json' => json_encode($t, JSON_UNESCAPED_UNICODE),
        ]);
        $this->auditar($ctx, 'cobro', ['pago_modo' => $modo, 'metodos' => array_keys(array_filter($t))]);

        return $this->redirigirCon('/panel/whatsapp', 'ok',
            $hayMetodo ? 'Cobro guardado. Abajo puede ver el texto exacto que dictará el bot.'
                       : 'Cobro guardado, sin datos de transferencia: si un cliente elige transferir, el bot pasará la conversación a una persona.');
    }

    public function guardarWompi(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'pagos.credenciales.escribir');

        $ambiente = $ctx->campo('wompi_ambiente');
        WaConfig::guardar($this->db(), [
            'wompi_ambiente' => in_array($ambiente, ['sandbox', 'produccion'], true) ? $ambiente : 'sandbox',
            'wompi_public_key' => $ctx->campo('wompi_public_key'),
            'wompi_private_key' => $ctx->campo('wompi_private_key'),
            'wompi_events_secret' => $ctx->campo('wompi_events_secret'),
            'wompi_integrity_secret' => $ctx->campo('wompi_integrity_secret'),
        ]);
        $this->auditar($ctx, 'wompi');

        return $this->redirigirCon('/panel/whatsapp', 'ok', 'Credenciales de Wompi guardadas.');
    }

    public function guardarHorario(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'config.editar');

        $horario = [];
        for ($d = 0; $d <= 6; $d++) {
            $desde = $ctx->campo("desde_{$d}");
            $hasta = $ctx->campo("hasta_{$d}");
            if ($desde === '' || $hasta === '') {
                continue; // día cerrado
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $desde) || !preg_match('/^\d{2}:\d{2}$/', $hasta) || $hasta <= $desde) {
                return $this->redirigirCon('/panel/whatsapp', 'error',
                    'Horario inválido: cada día abierto necesita desde y hasta (HH:MM) y el cierre debe ser después de la apertura.');
            }
            $horario[(string) $d] = ['desde' => $desde, 'hasta' => $hasta];
        }

        // Atención 24/7: el bot responde a cualquier hora. Las franjas de la
        // tabla no se borran — siguen siendo el horario de la AGENDA, que es
        // la disponibilidad real de Pedro para las citas.
        if ($ctx->campo('siempre') === '1') {
            $horario['siempre'] = true;
        }

        // El número de guardia viaja en el mismo formulario: es parte de la
        // atención. E.164 sin «+» — el mismo formato que ALERTA_WHATSAPP.
        $guardia = preg_replace('/[\s+\-().]/', '', $ctx->campo('handoff_numero'));
        if ($guardia !== '' && !preg_match('/^\d{10,15}$/', $guardia)) {
            return $this->redirigirCon('/panel/whatsapp', 'error',
                'Número de guardia inválido: solo dígitos con indicativo de país y sin «+», ej. 573001234567.');
        }

        // El recordatorio de citas viaja en el mismo formulario. Se acota en
        // vez de rechazarse (un 9999 tecleado no debe tumbar el guardado del
        // horario entero), y el campo AUSENTE no toca lo guardado: ausencia
        // no es cero — cero es «sin recordatorio» y solo vale dicho a
        // propósito.
        $campos = [
            'horario_atencion' => json_encode($horario, JSON_UNESCAPED_UNICODE),
            'handoff_numero' => $guardia,
        ];
        $crudoRecordatorio = trim($ctx->campo('recordatorio_minutos'));
        if ($crudoRecordatorio !== '') {
            $campos['recordatorio_minutos'] = (string) max(0, min(720, (int) $crudoRecordatorio));
        }

        WaConfig::guardar($this->db(), $campos);
        $this->auditar($ctx, 'horario', $horario + array_diff_key($campos, ['horario_atencion' => 1]));

        $franjas = array_filter(array_keys($horario), is_numeric(...));
        $aviso = match (true) {
            $horario === [] => 'Horario vacío: el bot atiende y agenda a cualquier hora — ojo.',
            !empty($horario['siempre']) && $franjas === [] => 'Atención 24/7 guardada — pero sin franjas no hay cupos de cita: el bot conversa a toda hora y no puede agendar nada.',
            !empty($horario['siempre']) => 'Guardado: el bot atiende 24/7 y las citas salen de las franjas de la tabla.',
            default => 'Horario guardado.',
        };

        return $this->redirigirCon('/panel/whatsapp', 'ok', $aviso);
    }

    /* ── El agente (la ruta de conversación) ──────────────────────────── */

    public function guardarAgente(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.prompts.editar');
        $db = $this->db();

        $db->query(
            'UPDATE wa_agentes SET nombre = ?, rol = ?, objetivo = ?, personalidad = ?,
                    instrucciones = ?, saludo_inicial = ?, mensaje_fuera_horario = ?, mensaje_error = ?
              WHERE id = 1',
            [
                mb_substr($ctx->campo('nombre', 'Asistente'), 0, 60),
                mb_substr($ctx->campo('rol'), 0, 200),
                $ctx->campo('objetivo'),
                mb_substr($ctx->campo('personalidad'), 0, 200),
                $ctx->campo('instrucciones'),
                $ctx->campo('saludo_inicial'),
                $ctx->campo('mensaje_fuera_horario'),
                $ctx->campo('mensaje_error'),
            ],
        );
        $this->auditar($ctx, 'agente');

        return $this->redirigirCon('/panel/whatsapp', 'ok',
            'Agente guardado. Las reglas jurídicas del bot no viven aquí: están en código y ninguna instrucción las pisa.');
    }

    /* ── Google Calendar ──────────────────────────────────────────────── */

    public function guardarGoogle(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $id = trim($ctx->campo('client_id'));
        $secreto = $ctx->campo('client_secret');
        if ($id === '') {
            return $this->redirigirCon('/panel/whatsapp', 'error', 'Falta el client_id de Google.');
        }
        // Ya pasó: alguien puso aquí un correo y pisó el client_id bueno —
        // Google respondía «invalid_client» al autorizar. Un Client ID real
        // siempre termina en .apps.googleusercontent.com; lo demás no entra.
        if (!str_ends_with($id, '.apps.googleusercontent.com')) {
            return $this->redirigirCon('/panel/whatsapp', 'error',
                'Eso no es un Client ID de Google (termina en .apps.googleusercontent.com). No es el correo de la cuenta: se crea en Google Cloud → Credenciales.');
        }
        $google = new GoogleCalendar($this->bd, $this->cifrado, $this->log);
        if ($secreto !== '') {
            $google->guardarCliente($id, $secreto);
        } else {
            // Solo cambió el id: no pisar el secreto guardado.
            $this->bd->pdo()->prepare('UPDATE wa_google SET client_id = ? WHERE id = 1')->execute([$id]);
        }
        $this->auditar($ctx, 'google_cliente');

        return $this->redirigirCon('/panel/whatsapp', 'ok',
            'Cliente OAuth guardado. Abra el enlace de autorización con la cuenta del abogado.');
    }

    public function canjearGoogle(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'ia.proveedores.escribir');

        $codigo = $ctx->campo('codigo');
        if ($codigo === '') {
            return $this->redirigirCon('/panel/whatsapp', 'error', 'Pegue el código que devuelve Google.');
        }
        $ok = (new GoogleCalendar($this->bd, $this->cifrado, $this->log))
            ->canjearCodigo($codigo, $this->redirectGoogle());
        $this->auditar($ctx, 'google_codigo', ['ok' => $ok]);

        return $ok
            ? $this->redirigirCon('/panel/whatsapp', 'ok', 'Calendario conectado.')
            : $this->redirigirCon('/panel/whatsapp', 'error',
                'Google no aceptó el código (caducan en minutos). Genere otro con el enlace de autorización.');
    }

    /* ── Citas y conversaciones ───────────────────────────────────────── */

    public function citas(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'agenda.ver');
        $db = $this->db();

        $citas = $db->fetchAll(
            "SELECT c.*, m.nombre AS servicio, p.estado_pago
               FROM wa_citas c
               LEFT JOIN modalidades_asesoria m ON m.id = c.modalidad_id
               LEFT JOIN wa_pedidos p ON p.pedido_id = c.id
              ORDER BY c.inicio DESC
              LIMIT 200"
        );

        return $this->vista('panel/whatsapp_citas', [
            'ctx' => $ctx,
            'citas' => $citas,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    public function conversaciones(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'casos.ver');
        $db = $this->db();

        $lista = $db->fetchAll(
            'SELECT id, telefono, nombre_contacto, estado, ultimo_mensaje_at, created_at
               FROM wa_conversaciones ORDER BY ultimo_mensaje_at DESC, id DESC LIMIT 100'
        );

        $abierta = null;
        $mensajes = [];
        $ver = (int) ($ctx->peticion->consulta['ver'] ?? 0);
        if ($ver > 0) {
            $abierta = $db->fetch('SELECT * FROM wa_conversaciones WHERE id = ?', [$ver]);
            if ($abierta) {
                $mensajes = $db->fetchAll(
                    'SELECT * FROM wa_mensajes WHERE conversacion_id = ? ORDER BY id DESC LIMIT 200',
                    [$ver],
                );
                $mensajes = array_reverse($mensajes);
            }
        }

        // Pagos que esperan un ojo humano: la transferencia directa (Nequi,
        // banco) no tiene API que la confirme, así que el comprobante lo
        // aprueba una persona aquí — y esa aprobación es la que confirma la
        // cita y dispara el evento del calendario.
        $pagosRevision = $db->fetchAll(
            "SELECT pg.id AS pago_id, pg.monto, pg.referencia, pg.estado,
                    pg.comprobante_media_ruta, pg.updated_at,
                    w.pedido_id, w.conversacion_id, v.nombre_contacto,
                    ci.inicio AS cita_inicio
               FROM wa_pagos pg
               JOIN wa_pedidos w ON w.id = pg.wa_pedido_id
               JOIN wa_conversaciones v ON v.id = w.conversacion_id
               LEFT JOIN wa_citas ci ON ci.id = w.pedido_id
              WHERE pg.estado IN ('PAYMENT_REVIEW_REQUIRED','PAYMENT_SCREENSHOT_RECEIVED','PAYMENT_VALIDATING')
              ORDER BY pg.updated_at DESC
              LIMIT 50"
        );

        return $this->vista('panel/whatsapp_conversaciones', [
            'ctx' => $ctx,
            'lista' => $lista,
            'abierta' => $abierta,
            'mensajes' => $mensajes,
            'pagosRevision' => $pagosRevision,
            'avisos' => $this->avisos($ctx),
        ]);
    }

    /**
     * Aprueba a mano un pago en revisión (comprobante de transferencia).
     *
     * Es la contraparte humana del webhook de Wompi: `aprobarManual` deja
     * constancia de quién aprobó, y el motor hace el resto — confirma la
     * cita, crea el evento con Meet y avisa al abogado. Aquí solo se añade
     * el mensaje al cliente, que de otro modo no se enteraría.
     */
    public function aprobarPago(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'casos.editar');
        $db = $this->db();

        $pedidoId = (int) $ctx->campo('pedido_id');
        $pm = new \ElkinLinan\WhatsappAiEngine\Payments\PaymentManager(
            $db,
            new \ElkinLinan\WhatsappAiEngine\Core\AuditLogger($db),
            \ElkinLinan\WhatsappAiEngine\Engine::dominio(),
        );
        $r = $pm->aprobarManual($pedidoId, (int) $ctx->usuario->id, mb_substr($ctx->campo('nota'), 0, 200));
        $this->auditar($ctx, 'aprobar_pago', ['pedido' => $pedidoId, 'ok' => !empty($r['ok'])]);

        if (empty($r['ok'])) {
            return $this->redirigirCon('/panel/whatsapp/conversaciones', 'error',
                (string) ($r['error'] ?? 'No se pudo aprobar el pago.'));
        }

        // Avisarle al cliente. Mejor esfuerzo: la aprobación ya quedó hecha.
        try {
            $fila = $db->fetch(
                'SELECT v.telefono FROM wa_pedidos w JOIN wa_conversaciones v ON v.id = w.conversacion_id
                  WHERE w.pedido_id = ?',
                [$pedidoId],
            );
            $canal = \ElkinLinan\WhatsappAiEngine\Channel\EvolutionClient::desdeConfig($db);
            if ($fila && $canal !== null) {
                $canal->enviarTexto((string) $fila['telefono'],
                    '✅ Verificamos su pago. Su cita quedó confirmada: la invitación con el enlace de la videollamada llega a su correo.');
            }
        } catch (\Throwable) {
        }

        return $this->redirigirCon('/panel/whatsapp/conversaciones', 'ok',
            'Pago aprobado: la cita queda confirmada y el cliente ya fue avisado.');
    }

    /** Sirve el comprobante adjunto de un pago, solo a quien puede ver casos. */
    public function comprobante(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'casos.ver');
        $db = $this->db();

        $pago = $db->fetch(
            'SELECT comprobante_media_ruta FROM wa_pagos WHERE id = ?',
            [(int) ($ctx->peticion->consulta['pago'] ?? 0)],
        );
        $rel = (string) ($pago['comprobante_media_ruta'] ?? '');
        $raiz = realpath(\ElkinLinan\WhatsappAiEngine\Engine::archivos()->raiz());
        $ruta = $rel !== '' && $raiz !== false ? realpath($raiz . '/' . $rel) : false;

        // El realpath ancla la ruta dentro del almacén: una ruta con ../ en la
        // base no puede sacar archivos de otra parte del disco.
        if ($ruta === false || !str_starts_with($ruta, $raiz . DIRECTORY_SEPARATOR)) {
            return new Respuesta('No encontrado.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $mime = match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'image/jpeg',
        };

        return new Respuesta((string) file_get_contents($ruta), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Devuelve a la IA una conversación que quedó con una persona. */
    public function reanudarIa(Contexto $ctx): Respuesta
    {
        $ctx->permisos->exigir($ctx->usuario, 'casos.editar');
        $db = $this->db();

        $id = (int) $ctx->campo('conversacion_id');
        $db->query("UPDATE wa_conversaciones SET estado = 'IA_ACTIVA', atendida_por = NULL WHERE id = ?", [$id]);
        $this->auditar($ctx, 'reanudar_ia', ['conversacion' => $id]);

        return $this->redirigirCon('/panel/whatsapp/conversaciones?ver=' . $id, 'ok', 'La IA vuelve a atender esta conversación.');
    }

    /* ── Interno ──────────────────────────────────────────────────────── */

    private function auditar(Contexto $ctx, string $accion, array $datos = []): void
    {
        $this->auditoria->registrar('whatsapp', '1', $accion, $ctx->actor(), $datos, $ctx->ip());
    }
}
