<?php

declare(strict_types=1);

use App\Soporte\Vista;

/**
 * Configuración del bot de WhatsApp.
 *
 * @var \App\Panel\Contexto $ctx
 * @var array<string,mixed> $cfg      WaConfig::paraFrontend — secretos como booleanos *_configurado
 * @var array<string,mixed> $agente
 * @var array|null          $estado   estado real de la instancia, si se pudo preguntar
 * @var bool                $googleConectado
 * @var string|null         $urlAutorizacion
 * @var array<string,array{desde:string,hasta:string}> $horario
 * @var int                 $citasProximas
 * @var array{ok:string,error:string} $avisos
 * @var array|null          $tokenNuevo  solo en la respuesta del POST que lo genera
 * @var string|null         $qr          data-uri del QR, solo tras pedir conexión
 */

$e = Vista::e(...);
$titulo = 'WhatsApp';

$tokenNuevo = $tokenNuevo ?? null;
$qr = $qr ?? null;

$dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
         5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo'];

/** @var array<string,string> $proveedores La lista del motor (LlmProviderManager::PROVEEDORES). */

$modosPago = [
    'mixto' => 'Exigir pago — enlace Wompi o transferencia con comprobante',
    'wompi' => 'Exigir pago — solo enlace Wompi',
    'manual' => 'Exigir pago — solo transferencia con comprobante',
    'todos' => 'El cliente elige — incluso agendar y pagar después',
    'contra_entrega' => 'Agendar sin cobrar — el pago se maneja por fuera',
];

$contenido = static function () use ($e, $ctx, $cfg, $agente, $estado, $googleConectado, $urlAutorizacion, $horario, $citasProximas, $tokenNuevo, $qr, $dias, $proveedores, $sttProveedores, $modosPago, $smtpConfigurado): void {
    $puedeConexion = $ctx->puede('ia.proveedores.escribir');
    $puedeSwitch = $ctx->puede('motor.killswitch');
    $puedePrompt = $ctx->puede('ia.prompts.editar');
    $puedeConfig = $ctx->puede('config.editar');
    $puedeWompi = $ctx->puede('pagos.credenciales.escribir');
    $activo = (int) ($cfg['activo'] ?? 0) === 1;
    ?>

    <!-- ── Estado ─────────────────────────────────────────────────── -->
    <section class="tarjeta p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-lg font-semibold">
                    Motor: <?= $activo ? 'ENCENDIDO' : 'apagado' ?>
                    <?php if ($estado !== null): ?>
                        · Instancia: <?= $e($estado['estado']) ?>
                        <?php if (!empty($estado['numero'])): ?>
                            (<?= $e((string) $estado['numero']) ?>)
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
                <p class="mt-1 text-sm text-acero">
                    <?= $e((string) $citasProximas) ?> cita(s) próximas ·
                    <a class="underline" href="/panel/whatsapp/citas">ver citas</a> ·
                    <a class="underline" href="/panel/whatsapp/conversaciones">ver conversaciones</a>
                </p>
            </div>

            <?php if ($puedeSwitch): ?>
            <form method="post" action="<?= $activo ? '/panel/whatsapp/apagar' : '/panel/whatsapp/encender' ?>">
                <?= $ctx->csrf->campoOculto() ?>
                <button type="submit" class="boton"><?= $activo ? 'Apagar el bot' : 'Encender el bot' ?></button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!$activo): ?>
        <p class="aviso aviso-error mt-3">
            Antes de encender: el bot guarda teléfono, nombre, correo y motivo de consulta.
            La política de tratamiento de datos personales debe estar publicada en el sitio.
        </p>
        <?php endif; ?>
    </section>

    <?php if ($tokenNuevo !== null): ?>
    <!-- ── Token recién generado: se enseña UNA vez ───────────────── -->
    <section class="tarjeta mt-6 p-4">
        <h2 class="rotulo">Token generado — copie estas URLs ahora</h2>
        <p class="mt-2 text-sm text-acero">
            Solo se muestran esta vez; después únicamente existe su huella.
            <?= $tokenNuevo['registrado']
                ? 'El webhook de mensajes ya quedó registrado en Evolution automáticamente.'
                : 'No se pudo registrar en Evolution automáticamente' . ($tokenNuevo['error'] !== '' ? ': ' . $e($tokenNuevo['error']) : '.') ?>
        </p>
        <dl class="mt-3 text-sm">
            <dt class="rotulo">Webhook de mensajes (Evolution)</dt>
            <dd class="mt-1 break-all font-mono"><?= $e($tokenNuevo['webhook']) ?></dd>
            <dt class="rotulo mt-3">Webhook de pagos (Wompi)</dt>
            <dd class="mt-1 break-all font-mono"><?= $e($tokenNuevo['pago']) ?></dd>
        </dl>
    </section>
    <?php endif; ?>

    <?php if ($qr !== null): ?>
    <!-- ── QR para vincular ───────────────────────────────────────── -->
    <section class="tarjeta mt-6 p-4">
        <h2 class="rotulo">Vincular WhatsApp</h2>
        <p class="mt-2 text-sm text-acero">
            Escanee desde WhatsApp → Dispositivos vinculados. El código caduca en
            menos de un minuto; si expira, vuelva a pedir el QR.
        </p>
        <img src="<?= $e(str_starts_with($qr, 'data:') ? $qr : 'data:image/png;base64,' . $qr) ?>"
             alt="Código QR de WhatsApp" width="264" height="264" class="mt-3">
    </section>
    <?php endif; ?>

    <!-- ── Conexión ───────────────────────────────────────────────── -->
    <section class="mt-8">
        <h2 class="rotulo">Conexión con Evolution API</h2>
        <form method="post" action="/panel/whatsapp/conexion" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="rotulo">URL de Evolution · fija del despliegue</label>
                    <!-- Sin name: no viaja en el POST. La tubería la pone el
                         despliegue (EVOLUTION_URL); desde aquí solo se mira. -->
                    <input value="<?= $e((string) ($cfg['evolution_url'] ?? '')) ?>"
                           class="campo mt-1 font-mono" readonly>
                </div>
                <div>
                    <label class="rotulo">Nombre de la instancia</label>
                    <input name="evolution_instancia" value="<?= $e((string) ($cfg['evolution_instancia'] ?? '')) ?>"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
                <div class="sm:col-span-2">
                    <span class="rotulo">API Key de Evolution</span>
                    <p class="mt-1 text-sm text-acero">
                        <?= !empty($cfg['evolution_apikey_configurado'])
                            ? 'Configurada · generada en el despliegue y guardada cifrada. Se cambia por consola: <code class="font-mono">php bin/wa-configurar.php --poner evolution_apikey=…</code>'
                            : 'SIN configurar · se siembra en el despliegue o por consola: <code class="font-mono">php bin/wa-configurar.php --poner evolution_apikey=…</code>' ?>
                    </p>
                </div>
            </div>
            <?php if ($puedeConexion): ?>
                <button type="submit" class="boton mt-4">Guardar conexión</button>
            <?php endif; ?>
        </form>

        <?php if ($puedeConexion): ?>
        <div class="mt-3 flex flex-wrap gap-3">
            <form method="post" action="/panel/whatsapp/qr">
                <?= $ctx->csrf->campoOculto() ?>
                <button type="submit" class="boton">Pedir QR para vincular</button>
            </form>

            <form method="post" action="/panel/whatsapp/token" class="flex flex-wrap items-end gap-2">
                <?= $ctx->csrf->campoOculto() ?>
                <div>
                    <label class="rotulo">Base pública para los webhooks</label>
                    <input name="webhook_base" value="<?= $e(rtrim((string) (\App\Soporte\Entorno::obtener('APP_URL', '') ?? ''), '/')) ?>"
                           class="campo mt-1 font-mono" size="34">
                </div>
                <button type="submit" class="boton"
                        onclick="return confirm('Genera un token NUEVO: cualquier webhook registrado con el anterior deja de funcionar. ¿Continuar?')">
                    Regenerar token y registrar webhook
                </button>
            </form>
        </div>
        <p class="mt-2 text-sm text-acero">
            En desarrollo local con Evolution en Docker, la base debe ser
            <code class="font-mono">http://host.docker.internal:8123</code> para que el contenedor alcance esta máquina.
        </p>
        <?php endif; ?>
    </section>

    <!-- ── IA ─────────────────────────────────────────────────────── -->
    <section class="mt-8">
        <h2 class="rotulo">Proveedor de IA</h2>
        <form method="post" action="/panel/whatsapp/ia" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="rotulo">Proveedor</label>
                    <select name="llm_proveedor" id="prov-llm" class="campo mt-1" <?= $puedeConexion ? '' : 'disabled' ?>>
                        <option value="">—</option>
                        <?php foreach ($proveedores as $valor => $nombre): ?>
                            <option value="<?= $e($valor) ?>" <?= ($cfg['llm_proveedor'] ?? '') === $valor ? 'selected' : '' ?>>
                                <?= $e($nombre) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="rotulo">Modelo</label>
                    <input name="llm_modelo" id="modelo-llm" list="lista-llm"
                           value="<?= $e((string) ($cfg['llm_modelo'] ?? '')) ?>"
                           placeholder="elige el proveedor…" class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                    <datalist id="lista-llm"></datalist>
                    <p class="mt-1 text-xs text-acero" id="estado-llm">
                        Los modelos se cargan solos al elegir el proveedor.
                    </p>
                </div>
                <div>
                    <label class="rotulo">
                        API Key <?= !empty($cfg['llm_api_key_configurado']) ? '· hay una guardada' : '' ?>
                    </label>
                    <input name="llm_api_key" id="clave-llm" type="password" autocomplete="off"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                    <?php if ($puedeConexion): ?>
                        <button type="button" class="boton mt-2" data-buscar="llm">Buscar modelos</button>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="rotulo">Respaldo — proveedor (opcional)</label>
                    <select name="llm_fallback_proveedor" id="prov-llm2" class="campo mt-1" <?= $puedeConexion ? '' : 'disabled' ?>>
                        <option value="">—</option>
                        <?php foreach ($proveedores as $valor => $nombre): ?>
                            <option value="<?= $e($valor) ?>" <?= ($cfg['llm_fallback_proveedor'] ?? '') === $valor ? 'selected' : '' ?>>
                                <?= $e($nombre) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="rotulo">Respaldo — modelo</label>
                    <input name="llm_fallback_modelo" id="modelo-llm2" list="lista-llm2"
                           value="<?= $e((string) ($cfg['llm_fallback_modelo'] ?? '')) ?>"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                    <datalist id="lista-llm2"></datalist>
                    <p class="mt-1 text-xs text-acero" id="estado-llm2"></p>
                </div>
                <div>
                    <label class="rotulo">
                        Respaldo — API Key <?= !empty($cfg['llm_fallback_api_key_configurado']) ? '· hay una guardada' : '' ?>
                    </label>
                    <input name="llm_fallback_api_key" id="clave-llm2" type="password" autocomplete="off"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                    <?php if ($puedeConexion): ?>
                        <button type="button" class="boton mt-2" data-buscar="llm2">Buscar modelos</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($puedeConexion): ?>
                <button type="submit" class="boton mt-4">Guardar IA</button>
            <?php endif; ?>
        </form>
    </section>

    <!-- ── Voz e imágenes ─────────────────────────────────────────── -->
    <section class="mt-8">
        <h2 class="rotulo">Voz e imágenes</h2>
        <p class="mt-2 text-sm text-acero">
            Sin configurar nada: las fotos las lee el proveedor de IA principal
            (si es uno que ve imágenes), a las notas de voz el bot responde
            pidiendo que escriban, y las respuestas son siempre de texto.
        </p>
        <form method="post" action="/panel/whatsapp/media" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>

            <h3 class="rotulo">Lector de imágenes (visión)</h3>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="rotulo">Proveedor</label>
                    <select name="vision_proveedor" class="campo mt-1" <?= $puedeConexion ? '' : 'disabled' ?>>
                        <option value="">El de la IA principal (recomendado)</option>
                        <?php foreach (['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini', 'local' => 'Servidor propio (URL, sin API key)'] as $v => $n): ?>
                            <option value="<?= $e($v) ?>" <?= ($cfg['vision_proveedor'] ?? '') === $v ? 'selected' : '' ?>><?= $e($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="rotulo">Modelo <span class="text-acero">· vacío = el de la IA principal</span></label>
                    <input name="vision_modelo" value="<?= $e((string) ($cfg['vision_modelo'] ?? '')) ?>"
                           placeholder="moondream" class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
                <div>
                    <label class="rotulo">
                        API Key <?= !empty($cfg['vision_api_key_configurado']) ? '· hay una guardada; escriba solo para reemplazarla' : '· vacía = la de la IA principal' ?>
                    </label>
                    <input name="vision_api_key" type="password" autocomplete="off"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
                <div>
                    <label class="rotulo">URL <span class="text-acero">· solo servidor propio</span></label>
                    <input name="vision_url" value="<?= $e((string) ($cfg['vision_url'] ?? '')) ?>"
                           placeholder="http://127.0.0.1:11434/v1" class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
            </div>

            <h3 class="rotulo mt-6">Escuchar notas de voz (transcripción)</h3>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="rotulo">Proveedor</label>
                    <select name="stt_proveedor" class="campo mt-1" <?= $puedeConexion ? '' : 'disabled' ?>>
                        <option value="">Desactivado — el bot pide que escriban</option>
                        <?php foreach ($sttProveedores as $v => $n): ?>
                            <option value="<?= $e($v) ?>" <?= ($cfg['stt_proveedor'] ?? '') === $v ? 'selected' : '' ?>><?= $e($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="rotulo">Modelo</label>
                    <input name="stt_modelo" value="<?= $e((string) ($cfg['stt_modelo'] ?? '')) ?>"
                           placeholder="whisper-large-v3" class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
                <div>
                    <label class="rotulo">
                        API Key <?= !empty($cfg['stt_api_key_configurado']) ? '· hay una guardada; escriba solo para reemplazarla' : '' ?>
                    </label>
                    <input name="stt_api_key" type="password" autocomplete="off"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
                <div>
                    <label class="rotulo">URL <span class="text-acero">· solo servidor propio</span></label>
                    <input name="stt_url" value="<?= $e((string) ($cfg['stt_url'] ?? '')) ?>"
                           placeholder="http://127.0.0.1:8000/v1" class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
            </div>

            <h3 class="rotulo mt-6">Responder con voz</h3>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="rotulo">Cuándo</label>
                    <select name="tts_modo" class="campo mt-1" <?= $puedeConexion ? '' : 'disabled' ?>>
                        <?php foreach (['espejo' => 'Espejo — voz si el cliente mandó voz (recomendado)', 'nunca' => 'Nunca — siempre texto', 'siempre' => 'Siempre — todo con voz', 'texto_y_audio' => 'Texto y audio, las dos cosas'] as $v => $n): ?>
                            <option value="<?= $e($v) ?>" <?= ($cfg['tts_modo'] ?? 'espejo') === $v ? 'selected' : '' ?>><?= $e($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="rotulo">Proveedor de voz</label>
                    <select name="tts_proveedor" class="campo mt-1" <?= $puedeConexion ? '' : 'disabled' ?>>
                        <option value="">Sin voz — solo texto</option>
                        <?php foreach (['elevenlabs' => 'ElevenLabs (la mejor calidad)', 'openai' => 'OpenAI', 'piper' => 'Piper — servidor propio, sin API key'] as $v => $n): ?>
                            <option value="<?= $e($v) ?>" <?= ($cfg['tts_proveedor'] ?? '') === $v ? 'selected' : '' ?>><?= $e($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="rotulo">Voz (voice id)</label>
                    <input name="tts_voice_id" value="<?= $e((string) ($cfg['tts_voice_id'] ?? '')) ?>"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
                <div>
                    <label class="rotulo">Modelo</label>
                    <input name="tts_modelo" value="<?= $e((string) ($cfg['tts_modelo'] ?? '')) ?>"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
                <div>
                    <label class="rotulo">
                        API Key <?= !empty($cfg['tts_api_key_configurado']) ? '· hay una guardada; escriba solo para reemplazarla' : '' ?>
                    </label>
                    <input name="tts_api_key" type="password" autocomplete="off"
                           class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
                <div>
                    <label class="rotulo">URL <span class="text-acero">· solo Piper</span></label>
                    <input name="tts_url" value="<?= $e((string) ($cfg['tts_url'] ?? '')) ?>"
                           placeholder="http://127.0.0.1:5000" class="campo mt-1 font-mono" <?= $puedeConexion ? '' : 'disabled' ?>>
                </div>
            </div>

            <?php if ($puedeConexion): ?>
                <button type="submit" class="boton mt-4">Guardar voz e imágenes</button>
            <?php endif; ?>
        </form>
    </section>

    <!-- ── Cobro ──────────────────────────────────────────────────── -->
    <section class="mt-8">
        <h2 class="rotulo">Cobro de la asesoría</h2>
        <form method="post" action="/panel/whatsapp/cobro" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <div class="grid gap-3">
                <div>
                    <label class="rotulo">¿La cita exige pago para confirmarse?</label>
                    <select name="pago_modo" class="campo mt-1" <?= $puedeConfig ? '' : 'disabled' ?>>
                        <?php foreach ($modosPago as $valor => $nombre): ?>
                            <option value="<?= $e($valor) ?>" <?= ($cfg['pago_modo'] ?? 'mixto') === $valor ? 'selected' : '' ?>>
                                <?= $e($nombre) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <fieldset>
                    <legend class="rotulo">Cuentas para recibir transferencias</legend>
                    <p class="mt-1 text-sm text-acero">
                        Llene solo las que use — puede ser una o las cuatro. El bot les
                        entrega a los clientes únicamente lo que esté aquí, y solo al
                        cerrar el cobro, nunca de memoria.
                    </p>
                    <?php $t = json_decode((string) ($cfg['pago_transferencia_json'] ?? ''), true) ?: []; ?>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="rotulo">Nequi (celular)</label>
                            <input name="trans_nequi" value="<?= $e((string) ($t['nequi'] ?? '')) ?>"
                                   placeholder="3XXXXXXXXX" inputmode="numeric"
                                   class="campo mt-1 font-mono" <?= $puedeConfig ? '' : 'disabled' ?>>
                        </div>
                        <div>
                            <label class="rotulo">Daviplata (celular)</label>
                            <input name="trans_daviplata" value="<?= $e((string) ($t['daviplata'] ?? '')) ?>"
                                   placeholder="3XXXXXXXXX" inputmode="numeric"
                                   class="campo mt-1 font-mono" <?= $puedeConfig ? '' : 'disabled' ?>>
                        </div>
                        <div>
                            <label class="rotulo">Bre-B (llave)</label>
                            <input name="trans_breb" value="<?= $e((string) ($t['breb'] ?? '')) ?>"
                                   placeholder="@usuario, celular o cédula registrada como llave"
                                   class="campo mt-1 font-mono" <?= $puedeConfig ? '' : 'disabled' ?>>
                        </div>
                        <div>
                            <label class="rotulo">Banco</label>
                            <input name="trans_banco_nombre" value="<?= $e((string) ($t['banco_nombre'] ?? '')) ?>"
                                   placeholder="Bancolombia, Davivienda…"
                                   class="campo mt-1" <?= $puedeConfig ? '' : 'disabled' ?>>
                        </div>
                        <div>
                            <label class="rotulo">Tipo de cuenta</label>
                            <select name="trans_banco_tipo" class="campo mt-1" <?= $puedeConfig ? '' : 'disabled' ?>>
                                <option value="ahorros" <?= ($t['banco_tipo'] ?? 'ahorros') === 'ahorros' ? 'selected' : '' ?>>Ahorros</option>
                                <option value="corriente" <?= ($t['banco_tipo'] ?? '') === 'corriente' ? 'selected' : '' ?>>Corriente</option>
                            </select>
                        </div>
                        <div>
                            <label class="rotulo">Número de cuenta</label>
                            <input name="trans_banco_numero" value="<?= $e((string) ($t['banco_numero'] ?? '')) ?>"
                                   class="campo mt-1 font-mono" <?= $puedeConfig ? '' : 'disabled' ?>>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="rotulo">Titular (obligatorio si hay alguna cuenta)</label>
                            <input name="trans_titular" value="<?= $e((string) ($t['titular'] ?? '')) ?>"
                                   placeholder="Nombre como aparece en la cuenta"
                                   class="campo mt-1" <?= $puedeConfig ? '' : 'disabled' ?>>
                            <p class="mt-1 text-sm text-acero">
                                Es lo que le permite al cliente verificar a quién le está transfiriendo.
                            </p>
                        </div>
                    </div>

                    <?php if (trim((string) ($cfg['pago_datos_transferencia'] ?? '')) !== ''): ?>
                    <div class="mt-3">
                        <p class="rotulo">Así se lo dictará el bot al cliente:</p>
                        <pre class="tarjeta mt-1 whitespace-pre-wrap p-3 font-mono text-sm"><?= $e((string) $cfg['pago_datos_transferencia']) ?></pre>
                    </div>
                    <?php endif; ?>
                </fieldset>
            </div>
            <?php if ($puedeConfig): ?>
                <button type="submit" class="boton mt-4">Guardar cobro</button>
            <?php endif; ?>
        </form>

        <?php if ($puedeWompi): ?>
        <form method="post" action="/panel/whatsapp/wompi" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <h3 class="rotulo">Credenciales de Wompi</h3>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="rotulo">Ambiente</label>
                    <select name="wompi_ambiente" class="campo mt-1">
                        <option value="sandbox" <?= ($cfg['wompi_ambiente'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox (pruebas)</option>
                        <option value="produccion" <?= ($cfg['wompi_ambiente'] ?? '') === 'produccion' ? 'selected' : '' ?>>Producción</option>
                    </select>
                </div>
                <div>
                    <label class="rotulo">Llave pública</label>
                    <input name="wompi_public_key" value="<?= $e((string) ($cfg['wompi_public_key'] ?? '')) ?>" class="campo mt-1 font-mono">
                </div>
                <div>
                    <label class="rotulo">Llave privada <?= !empty($cfg['wompi_private_key_configurado']) ? '· hay una guardada' : '' ?></label>
                    <input name="wompi_private_key" type="password" autocomplete="off" class="campo mt-1 font-mono">
                </div>
                <div>
                    <label class="rotulo">Secreto de eventos <?= !empty($cfg['wompi_events_secret_configurado']) ? '· hay uno guardado' : '' ?></label>
                    <input name="wompi_events_secret" type="password" autocomplete="off" class="campo mt-1 font-mono">
                </div>
                <div>
                    <label class="rotulo">Secreto de integridad <?= !empty($cfg['wompi_integrity_secret_configurado']) ? '· hay uno guardado' : '' ?></label>
                    <input name="wompi_integrity_secret" type="password" autocomplete="off" class="campo mt-1 font-mono">
                </div>
            </div>
            <button type="submit" class="boton mt-4">Guardar Wompi</button>
        </form>
        <?php endif; ?>
    </section>

    <!-- ── Horario ────────────────────────────────────────────────── -->
    <section class="mt-8">
        <h2 class="rotulo">Horario de atención y agenda</h2>
        <form method="post" action="/panel/whatsapp/horario" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <p class="text-sm text-acero">Un día sin horas queda cerrado. De este horario salen los cupos que el bot ofrece.</p>

            <label class="mt-3 flex items-center gap-2 text-sm">
                <input type="checkbox" name="siempre" value="1"
                       <?= !empty($horario['siempre']) ? 'checked' : '' ?>
                       <?= $puedeConfig ? '' : 'disabled' ?>>
                <span><strong>Atención 24/7</strong> — el bot responde a cualquier hora.
                Las franjas de abajo siguen mandando sobre la agenda: las citas
                solo se ofrecen dentro de ellas.</span>
            </label>

            <table class="tabla mt-3">
                <thead><tr><th>Día</th><th>Desde</th><th>Hasta</th></tr></thead>
                <tbody>
                <?php foreach ($dias as $d => $nombre): $f = $horario[(string) $d] ?? null; ?>
                    <tr>
                        <td><?= $e($nombre) ?></td>
                        <td><input name="desde_<?= $d ?>" type="time" value="<?= $e((string) ($f['desde'] ?? '')) ?>"
                                   class="campo font-mono" <?= $puedeConfig ? '' : 'disabled' ?>></td>
                        <td><input name="hasta_<?= $d ?>" type="time" value="<?= $e((string) ($f['hasta'] ?? '')) ?>"
                                   class="campo font-mono" <?= $puedeConfig ? '' : 'disabled' ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mt-4">
                <label class="rotulo">Recordatorio de citas (minutos antes)</label>
                <p class="mt-1 text-sm text-acero">
                    Un cron revisa cada 5 minutos: cuando a una cita confirmada le
                    falte este tiempo o menos, se recuerda una sola vez al cliente
                    (WhatsApp<?= $smtpConfigurado ? ' y correo' : '; el correo se activará al configurar el SMTP del servidor' ?>)
                    y al número de guardia. 0 = sin recordatorio.
                </p>
                <input name="recordatorio_minutos" type="number" min="0" max="720"
                       value="<?= (int) ($cfg['recordatorio_minutos'] ?? 30) ?>"
                       class="campo mt-1 w-32" <?= $puedeConfig ? '' : 'disabled' ?>>
            </div>

            <div class="mt-4">
                <label class="rotulo">Número de guardia — atención humana</label>
                <p class="mt-1 text-sm text-acero">
                    Cuando un cliente pida hablar con una persona, el bot deja la
                    conversación esperando en «Conversaciones» y avisa por WhatsApp
                    a este número con el nombre, el teléfono y el motivo. Vacío:
                    queda solo el aviso del panel. E.164 sin «+», ej. 573001234567.
                </p>
                <input name="handoff_numero" value="<?= $e((string) ($cfg['handoff_numero'] ?? '')) ?>"
                       placeholder="573001234567" class="campo mt-1 font-mono" <?= $puedeConfig ? '' : 'disabled' ?>>
            </div>
            <?php if ($puedeConfig): ?>
                <button type="submit" class="boton mt-4">Guardar horario</button>
            <?php endif; ?>
        </form>
    </section>

    <!-- ── Agente ─────────────────────────────────────────────────── -->
    <section class="mt-8">
        <h2 class="rotulo">El agente — la ruta de conversación</h2>
        <p class="mt-2 text-sm text-acero">
            Lo que se escribe aquí ajusta CÓMO atiende el bot. Las reglas jurídicas
            (no citar normas, no dar plazos, no prometer resultados) no viven aquí:
            están en código y ninguna instrucción de esta pantalla puede pisarlas.
        </p>
        <form method="post" action="/panel/whatsapp/agente" class="tarjeta mt-3 p-4">
            <?= $ctx->csrf->campoOculto() ?>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="rotulo">Nombre del asistente</label>
                    <input name="nombre" value="<?= $e((string) ($agente['nombre'] ?? '')) ?>" class="campo mt-1" <?= $puedePrompt ? '' : 'disabled' ?>>
                </div>
                <div>
                    <label class="rotulo">Personalidad</label>
                    <input name="personalidad" value="<?= $e((string) ($agente['personalidad'] ?? '')) ?>" class="campo mt-1" <?= $puedePrompt ? '' : 'disabled' ?>>
                </div>
                <div class="sm:col-span-2">
                    <label class="rotulo">Rol</label>
                    <input name="rol" value="<?= $e((string) ($agente['rol'] ?? '')) ?>" class="campo mt-1" <?= $puedePrompt ? '' : 'disabled' ?>>
                </div>
                <div class="sm:col-span-2">
                    <label class="rotulo">Objetivo</label>
                    <textarea name="objetivo" rows="2" class="campo mt-1" <?= $puedePrompt ? '' : 'disabled' ?>><?= $e((string) ($agente['objetivo'] ?? '')) ?></textarea>
                </div>
                <div class="sm:col-span-2">
                    <label class="rotulo">Ruta de atención (instrucciones)</label>
                    <textarea name="instrucciones" rows="14" class="campo mt-1 font-mono text-sm" <?= $puedePrompt ? '' : 'disabled' ?>><?= $e((string) ($agente['instrucciones'] ?? '')) ?></textarea>
                </div>
                <div class="sm:col-span-2">
                    <label class="rotulo">Saludo inicial</label>
                    <textarea name="saludo_inicial" rows="3" class="campo mt-1" <?= $puedePrompt ? '' : 'disabled' ?>><?= $e((string) ($agente['saludo_inicial'] ?? '')) ?></textarea>
                </div>
                <div>
                    <label class="rotulo">Mensaje fuera de horario</label>
                    <textarea name="mensaje_fuera_horario" rows="3" class="campo mt-1" <?= $puedePrompt ? '' : 'disabled' ?>><?= $e((string) ($agente['mensaje_fuera_horario'] ?? '')) ?></textarea>
                </div>
                <div>
                    <label class="rotulo">Mensaje de error</label>
                    <textarea name="mensaje_error" rows="3" class="campo mt-1" <?= $puedePrompt ? '' : 'disabled' ?>><?= $e((string) ($agente['mensaje_error'] ?? '')) ?></textarea>
                </div>
            </div>
            <?php if ($puedePrompt): ?>
                <button type="submit" class="boton mt-4">Guardar agente</button>
            <?php endif; ?>
        </form>
    </section>

    <!-- ── Google Calendar ────────────────────────────────────────── -->
    <section class="mt-8">
        <h2 class="rotulo">Google Calendar del abogado</h2>
        <div class="tarjeta mt-3 p-4">
            <p class="text-sm">
                Estado: <strong><?= $googleConectado ? 'conectado' : 'sin conectar' ?></strong>.
                Con el calendario conectado, el bot no ofrece horas que el abogado tenga
                ocupadas y cada cita confirmada crea el evento con enlace de Meet e
                invitación al correo del cliente.
            </p>

            <?php if ($puedeConexion): ?>
            <form method="post" action="/panel/whatsapp/google" class="mt-4 grid gap-3 sm:grid-cols-2">
                <?= $ctx->csrf->campoOculto() ?>
                <div>
                    <label class="rotulo">Client ID (Google Cloud, OAuth)</label>
                    <input name="client_id" class="campo mt-1 font-mono" autocomplete="off">
                </div>
                <div>
                    <label class="rotulo">Client Secret</label>
                    <input name="client_secret" type="password" class="campo mt-1 font-mono" autocomplete="off">
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="boton">Guardar cliente OAuth</button>
                </div>
            </form>

            <?php if ($urlAutorizacion !== null && !$googleConectado): ?>
            <p class="mt-4 text-sm">
                1. <a class="underline" href="<?= $e($urlAutorizacion) ?>" target="_blank" rel="noopener">
                    Autorizar el calendario con la cuenta del abogado</a>
                (al terminar, Google redirige y el código va en la URL, parámetro <code class="font-mono">code</code>).
            </p>
            <form method="post" action="/panel/whatsapp/google/codigo" class="mt-2 flex flex-wrap items-end gap-2">
                <?= $ctx->csrf->campoOculto() ?>
                <div>
                    <label class="rotulo">2. Pegue el código</label>
                    <input name="codigo" class="campo mt-1 font-mono" size="40" autocomplete="off">
                </div>
                <button type="submit" class="boton">Conectar</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

<?php };

/*
 * Carga de modelos del desplegable de IA.
 *
 * Dos caminos, en este orden: lo YA CONOCIDO (wa_modelos, sin salir a la red)
 * y, si el proveedor no tiene nada guardado, la sincronización contra su API
 * — que usa la clave recién escrita en el formulario o, en su defecto, la
 * guardada. El botón «Buscar modelos» fuerza siempre la sincronización.
 */
$scripts = static function (): void { ?>
(function () {
    'use strict';

    function csrf() {
        var m = document.cookie.match(/(?:^|; )pa_csrf=([a-f0-9]{64})/);
        return m ? m[1] : '';
    }

    function pintar(lista, modelos) {
        lista.textContent = '';
        modelos.forEach(function (m) {
            var o = document.createElement('option');
            o.value = m.modelo_id;
            o.label = (m.nombre && m.nombre !== m.modelo_id ? m.nombre : m.modelo_id)
                    + (m.estado === 'nuevo' ? ' · NUEVO' : '');
            lista.appendChild(o);
        });
    }

    function armar(sufijo) {
        var prov = document.getElementById('prov-' + sufijo);
        var clave = document.getElementById('clave-' + sufijo);
        var lista = document.getElementById('lista-' + sufijo);
        var estado = document.getElementById('estado-' + sufijo);
        var boton = document.querySelector('[data-buscar="' + sufijo + '"]');
        if (!prov || !lista || !estado) return;

        function decir(texto) { estado.textContent = texto; }

        function conocidos() {
            if (!prov.value) { pintar(lista, []); decir(''); return Promise.resolve(0); }
            return fetch('/panel/whatsapp/modelos?proveedor=' + encodeURIComponent(prov.value))
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    var ms = j.modelos || [];
                    pintar(lista, ms);
                    if (ms.length) decir(ms.length + ' modelos — escribe para filtrar o abre la lista');
                    return ms.length;
                })
                .catch(function () { decir('No se pudo consultar la lista.'); return -1; });
        }

        function sincronizar() {
            if (!prov.value) { decir('Elige primero el proveedor.'); return; }
            decir('Consultando el catálogo del proveedor…');
            var cuerpo = new URLSearchParams();
            cuerpo.set('proveedor', prov.value);
            cuerpo.set('api_key', clave ? clave.value : '');
            fetch('/panel/whatsapp/modelos/sincronizar', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf() },
                body: cuerpo
            })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { decir(j.error || 'El proveedor no respondió.'); return; }
                    pintar(lista, j.modelos || []);
                    decir(j.total + ' modelos del proveedor'
                        + (j.nuevos ? ' (' + j.nuevos + ' nuevos)' : '')
                        + ' — escribe para filtrar o abre la lista');
                })
                .catch(function () { decir('No se pudo sincronizar.'); });
        }

        prov.addEventListener('change', function () {
            conocidos().then(function (n) { if (n === 0) sincronizar(); });
        });
        if (boton) boton.addEventListener('click', sincronizar);

        // Al abrir la pantalla con proveedor ya elegido, la lista aparece
        // sola; si no hay nada guardado aún y existe clave, se sincroniza.
        conocidos().then(function (n) { if (n === 0 && prov.value) sincronizar(); });
    }

    armar('llm');
    armar('llm2');
})();
<?php };

require __DIR__ . '/_disposicion.php';
