<?php
/**
 * ============================================================================
 * EvolutionClient — la ÚNICA clase que sabe qué es Evolution API
 * ============================================================================
 * Todo lo de arriba habla por ChannelInterface. Si mañana se cambia de
 * proveedor, se escribe otra implementación y no se toca nada más.
 *
 * VERIFICAR CONTRA LA VERSIÓN DESPLEGADA antes de dar por buena la conexión:
 * Evolution cambió rutas y forma del cuerpo entre v1 y v2 (sendText pasó de
 * {textMessage:{text}} a {text}, y los eventos del webhook cambiaron de
 * envoltorio). Aquí se acepta LAS DOS FORMAS a propósito, tanto al enviar como
 * al leer, porque no se puede saber desde el código qué versión levantó el
 * operador. Es tolerancia deliberada, no descuido: la alternativa era cablear
 * una versión y romperse con la otra.
 */

namespace ElkinLinan\WhatsappAiEngine\Channel;

use ElkinLinan\WhatsappAiEngine\Core\Http;
use ElkinLinan\WhatsappAiEngine\Core\WaConfig;


class EvolutionClient implements ChannelInterface
{
    private $url;
    private $instancia;
    private $apikey;

    public function __construct(string $url, string $instancia, string $apikey)
    {
        $this->url       = rtrim($url, '/');
        $this->instancia = $instancia;
        $this->apikey    = $apikey;
    }

    /** Construye el cliente desde la configuración del negocio. */
    public static function desdeConfig($db): ?self
    {
        // Sin require_once: lo carga el autoload PSR-4 del paquete. El que había
        // apuntaba a `src/core/WaConfig.php` en minúscula y el directorio es
        // `src/Core/`: en Windows colaba por casualidad y en Linux —donde el
        // sistema de archivos distingue mayúsculas— era un fatal en el primer
        // mensaje que llegara.
        $cfg = WaConfig::cargar($db);
        if (!$cfg || empty($cfg['evolution_instancia'])) return null;
        // URL y apikey: primero las del negocio; si no las tiene, las que la
        // PLATAFORMA da por defecto (un solo servidor Evolution compartido en el
        // SaaS). Así el negocio solo configura su instancia y su número.
        $url    = !empty($cfg['evolution_url']) ? $cfg['evolution_url'] : \ElkinLinan\WhatsappAiEngine\Engine::config()->canalUrlPorDefecto();
        $apikey = WaConfig::secreto($cfg, 'evolution_apikey');
        if ($apikey === '') $apikey = \ElkinLinan\WhatsappAiEngine\Engine::config()->canalApikeyPorDefecto();
        if ($url === '') return null;
        return new self($url, $cfg['evolution_instancia'], $apikey);
    }

    public function nombre(): string { return 'Evolution API'; }

    public function requisitosFaltantes(): array
    {
        $faltan = [];
        if ($this->url === '')       $faltan[] = 'URL de Evolution API';
        if ($this->instancia === '') $faltan[] = 'Nombre de la instancia';
        if ($this->apikey === '')    $faltan[] = 'API Key de Evolution';
        return $faltan;
    }

    private function cabeceras(): array
    {
        return ['apikey: ' . $this->apikey, 'Accept: application/json'];
    }

    // ── Conexión ────────────────────────────────────────────────────────

    public function estado(): array
    {
        $r = Http::json('GET', $this->url . '/instance/connectionState/' . rawurlencode($this->instancia), $this->cabeceras(), null, 20);
        if (!$r['ok']) {
            return ['estado' => 'error', 'numero' => null, 'mensaje' => $r['error'] ?: 'No responde'];
        }
        $s = $r['json']['instance']['state'] ?? ($r['json']['state'] ?? '');
        $mapa = ['open' => 'conectado', 'connecting' => 'qr', 'close' => 'desconectado'];
        return [
            'estado'  => $mapa[$s] ?? 'desconectado',
            'numero'  => $r['json']['instance']['owner'] ?? null,
            'mensaje' => $s,
        ];
    }

    /**
     * Crea la instancia si hace falta y devuelve el QR. Si ya existe, Evolution
     * responde 403/409: no es un error, es "ya estaba" — se pide el QR y ya.
     *
     * EL QR NO ESTÁ LISTO AL INSTANTE. Comprobado contra Evolution v2.2.3: tanto
     * `create` como el primer `connect` responden `{"count":0}` sin imagen,
     * porque Baileys todavía está levantando el socket con WhatsApp. Pedirlo una
     * sola vez —como se hacía— devolvía casi siempre «Evolution no devolvió el
     * QR» aunque todo estuviera bien. Por eso se sondea.
     */
    public function conectar(int $intentos = 8, int $esperaMs = 1500): array
    {
        $crear = Http::json('POST', $this->url . '/instance/create', $this->cabeceras(), [
            'instanceName' => $this->instancia,
            'qrcode'       => true,
            'integration'  => 'WHATSAPP-BAILEYS',
        ], 30);

        // A veces el QR ya viene en la respuesta de creación.
        $qr = $crear['json']['qrcode']['base64'] ?? null;
        $codigo = $crear['json']['qrcode']['code'] ?? null;

        // Si NI SIQUIERA se pudo hablar con Evolution, no tiene sentido sondear:
        // se corta ya. Reintentar ocho veces contra un servidor inalcanzable
        // multiplica el tiempo de espera por ocho y deja la pantalla colgada un
        // par de minutos — que es exactamente lo que pasaba al escribir mal la
        // URL. Un error claro en dos segundos vale más que un sondeo educado.
        if ($crear['status'] === 0) {
            return ['ok' => false, 'qr' => null, 'codigo' => null,
                    'error' => 'No se pudo contactar con Evolution API en ' . $this->url
                             . '. Revisa la URL (desde este servidor suele ser http://localhost:8080) '
                             . 'y que el contenedor esté levantado. Detalle: ' . $crear['error']];
        }

        $ultimoError = '';
        for ($i = 0; $i < max(1, $intentos) && !$qr; $i++) {
            if ($i > 0) usleep($esperaMs * 1000);
            $r = Http::json('GET', $this->url . '/instance/connect/' . rawurlencode($this->instancia),
                $this->cabeceras(), null, 15);
            if ($r['status'] === 0) {          // se cayó la conexión a mitad
                $ultimoError = $r['error'];
                break;
            }
            if (!$r['ok']) { $ultimoError = $r['error']; continue; }
            $j = $r['json'] ?? [];
            $qr = $j['base64'] ?? ($j['qrcode']['base64'] ?? null);
            $codigo = $codigo ?: ($j['code'] ?? ($j['qrcode']['code'] ?? null));
        }

        if ($qr) {
            return ['ok' => true, 'qr' => $qr, 'codigo' => $codigo, 'error' => ''];
        }
        if (!$crear['ok'] && $ultimoError !== '') {
            return ['ok' => false, 'qr' => null, 'codigo' => null, 'error' => $ultimoError];
        }
        // La instancia existe pero WhatsApp no entrega el código. Casi siempre es
        // que la versión de WhatsApp Web que trae la imagen quedó obsoleta y el
        // saludo con los servidores de WhatsApp no llega a completarse.
        return ['ok' => false, 'qr' => null, 'codigo' => null,
                'error' => 'La instancia está en «connecting» pero WhatsApp no entregó el código QR. '
                         . 'Suele ser la versión de WhatsApp Web del contenedor: actualiza la imagen de '
                         . 'Evolution o fija CONFIG_SESSION_PHONE_VERSION. Revisa `docker compose logs evolution`.'];
    }

    public function desconectar(): array
    {
        $r = Http::json('DELETE', $this->url . '/instance/logout/' . rawurlencode($this->instancia), $this->cabeceras(), null, 20);
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    public function registrarWebhook(string $url): array
    {
        // base64: true hace que la media entrante venga ya codificada y evita
        // una segunda llamada por cada audio o foto. Si la versión desplegada
        // no lo soporta, descargarMedia() lo resuelve por el otro camino.
        $cuerpo = ['webhook' => [
            'url'      => $url,
            'enabled'  => true,
            'byEvents' => false,
            'base64'   => true,
            'events'   => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
        ]];
        $r = Http::json('POST', $this->url . '/webhook/set/' . rawurlencode($this->instancia), $this->cabeceras(), $cuerpo, 20);
        if (!$r['ok']) {
            // Forma de v1: los campos van en la raíz, no anidados bajo 'webhook'.
            $r = Http::json('POST', $this->url . '/webhook/set/' . rawurlencode($this->instancia), $this->cabeceras(), $cuerpo['webhook'], 20);
        }
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    // ── Envío ───────────────────────────────────────────────────────────

    /**
     * Destino de un envío.
     *
     * Para un teléfono normal, WhatsApp quiere los dígitos a secas. PERO desde
     * que existen los LID —el identificador de privacidad con el que WhatsApp ya
     * NO revela el número de quien escribe— el destino puede ser un JID como
     * `45939054088265@lid`, y ese hay que mandarlo ENTERO.
     *
     * Comprobado contra Evolution: mandando solo los dígitos de un LID responde
     * 400 con `"exists": false`, porque le añade `@s.whatsapp.net` a un número
     * que no existe. Con el JID completo, 201.
     *
     * Regla: si trae '@', es un JID y se respeta tal cual.
     */
    public static function normalizarNumero(string $destino): string
    {
        $destino = trim($destino);
        if (strpos($destino, '@') !== false) return $destino;
        $n = preg_replace('/\D+/', '', $destino);
        return $n === null ? '' : $n;
    }

    public function enviarTexto(string $telefono, string $texto): array
    {
        $numero = self::normalizarNumero($telefono);
        if ($numero === '' || trim($texto) === '') return ['ok' => false, 'message_id' => null, 'error' => 'Destino o texto vacío'];

        $ruta = $this->url . '/message/sendText/' . rawurlencode($this->instancia);
        $r = Http::json('POST', $ruta, $this->cabeceras(), ['number' => $numero, 'text' => $texto], 30);
        if (!$r['ok']) {
            $r = Http::json('POST', $ruta, $this->cabeceras(),
                ['number' => $numero, 'textMessage' => ['text' => $texto]], 30);
        }
        return ['ok' => $r['ok'], 'message_id' => $r['json']['key']['id'] ?? null, 'error' => $r['error']];
    }

    public function enviarAudio(string $telefono, string $audioBase64, string $mime = 'audio/ogg'): array
    {
        $numero = self::normalizarNumero($telefono);
        $ruta = $this->url . '/message/sendWhatsAppAudio/' . rawurlencode($this->instancia);
        $r = Http::json('POST', $ruta, $this->cabeceras(), ['number' => $numero, 'audio' => $audioBase64], 60);
        if (!$r['ok']) {
            $r = Http::json('POST', $ruta, $this->cabeceras(),
                ['number' => $numero, 'audioMessage' => ['audio' => $audioBase64]], 60);
        }
        return ['ok' => $r['ok'], 'message_id' => $r['json']['key']['id'] ?? null, 'error' => $r['error']];
    }

    public function enviarImagen(string $telefono, string $imagenBase64, string $caption = ''): array
    {
        $numero = self::normalizarNumero($telefono);
        $ruta = $this->url . '/message/sendMedia/' . rawurlencode($this->instancia);
        $r = Http::json('POST', $ruta, $this->cabeceras(), [
            'number' => $numero, 'mediatype' => 'image', 'media' => $imagenBase64, 'caption' => $caption,
        ], 60);
        return ['ok' => $r['ok'], 'message_id' => $r['json']['key']['id'] ?? null, 'error' => $r['error']];
    }

    // ── Entrada ─────────────────────────────────────────────────────────

    /**
     * Normaliza el evento del webhook. Se descarta todo lo que no sea un
     * mensaje entrante de una persona: los eventos propios (fromMe) crearían un
     * bucle infinito — el bot respondiéndose a sí mismo.
     */
    public function normalizarWebhook(array $payload): ?array
    {
        $evento = strtolower((string)($payload['event'] ?? ''));
        if ($evento !== '' && strpos($evento, 'messages.upsert') === false && strpos($evento, 'messages_upsert') === false) {
            return null;
        }
        $data = $payload['data'] ?? $payload;
        // v2 puede mandar un lote: {data:{messages:[…]}}
        if (isset($data['messages'][0])) $data = $data['messages'][0];
        if (!isset($data['key'])) return null;

        $key = $data['key'];
        if (!empty($key['fromMe'])) return null;                    // eco propio: se ignora

        $jid = (string)($key['remoteJid'] ?? '');
        if ($jid === '' || strpos($jid, '@g.us') !== false) return null;   // grupos: fuera de alcance
        if (strpos($jid, '@broadcast') !== false) return null;

        // ── LID: WhatsApp puede NO decirnos el teléfono ────────────────────
        // Con `addressingMode: "lid"` el remitente llega como
        // `45939054088265@lid`, que no es un número: es un identificador de
        // privacidad. Hay que contestarle A ESE JID, entero.
        //
        // Algunas versiones sí adjuntan el teléfono real en otro campo; si está,
        // se prefiere, porque permite reconocer al cliente en `clientes` y
        // acumularle puntos. Si no está, se trabaja con el LID y ya.
        $esLid = (strpos($jid, '@lid') !== false)
              || (($key['addressingMode'] ?? '') === 'lid');

        $alterno = '';
        foreach (['remoteJidAlt', 'senderPn', 'participantPn'] as $campo) {
            $v = (string)($key[$campo] ?? ($data[$campo] ?? ''));
            if ($v !== '' && strpos($v, '@lid') === false) { $alterno = $v; break; }
        }

        if ($esLid && $alterno === '') {
            $destino = $jid;                                   // el LID entero
        } else {
            $destino = self::normalizarNumero(explode('@', $alterno ?: $jid)[0]);
        }
        if ($destino === '') return null;

        $msg  = $data['message'] ?? [];
        $tipo = 'texto'; $texto = ''; $mediaMime = null; $mediaB64 = null;

        if (isset($msg['conversation'])) {
            $texto = (string)$msg['conversation'];
        } elseif (isset($msg['extendedTextMessage']['text'])) {
            $texto = (string)$msg['extendedTextMessage']['text'];
        } elseif (isset($msg['audioMessage'])) {
            $tipo = 'audio';
            $mediaMime = $msg['audioMessage']['mimetype'] ?? 'audio/ogg';
        } elseif (isset($msg['imageMessage'])) {
            $tipo = 'imagen';
            $texto = (string)($msg['imageMessage']['caption'] ?? '');
            $mediaMime = $msg['imageMessage']['mimetype'] ?? 'image/jpeg';
        } elseif (isset($msg['documentMessage'])) {
            $tipo = 'documento';
            $texto = (string)($msg['documentMessage']['caption'] ?? '');
            $mediaMime = $msg['documentMessage']['mimetype'] ?? 'application/octet-stream';
        } else {
            // Sticker, ubicación, contacto, reacción… no se procesan, pero se
            // devuelve el mensaje para poder responder con cortesía.
            $tipo = 'documento';
        }

        // Con base64:true la media llega en el propio evento y no hay que pedirla.
        if (!empty($data['message']['base64']))  $mediaB64 = $data['message']['base64'];
        elseif (!empty($payload['data']['base64'])) $mediaB64 = $payload['data']['base64'];
        elseif (!empty($data['base64']))         $mediaB64 = $data['base64'];

        return [
            'message_id' => (string)($key['id'] ?? ''),
            // `telefono` es la CLAVE DE LA CONVERSACIÓN y el destino de la
            // respuesta. Son dígitos cuando WhatsApp nos da el número, y el JID
            // completo cuando solo nos da un LID.
            'telefono'   => $destino,
            'es_lid'     => $esLid && $alterno === '',
            'nombre'     => (string)($data['pushName'] ?? ''),
            'tipo'       => $tipo,
            'texto'      => trim($texto),
            'media_b64'  => $mediaB64,
            'media_mime' => $mediaMime,
            'timestamp'  => (int)($data['messageTimestamp'] ?? time()),
            'raw_key'    => $key,
        ];
    }

    /**
     * Consigue el binario de la media. Primero lo que ya venía en el evento;
     * si no, se le pide a Evolution con la clave del mensaje.
     */
    public function descargarMedia(array $mensaje): ?string
    {
        if (!empty($mensaje['media_b64'])) {
            $bin = base64_decode($mensaje['media_b64'], true);
            if ($bin !== false && $bin !== '') return $bin;
        }
        if (empty($mensaje['raw_key'])) return null;

        $r = Http::json('POST', $this->url . '/chat/getBase64FromMediaMessage/' . rawurlencode($this->instancia),
            $this->cabeceras(), ['message' => ['key' => $mensaje['raw_key']]], 60);
        if (!$r['ok']) return null;
        $b64 = $r['json']['base64'] ?? ($r['json']['media'] ?? null);
        if (!$b64) return null;
        $bin = base64_decode($b64, true);
        return ($bin === false || $bin === '') ? null : $bin;
    }
}
