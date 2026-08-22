# Motor conversacional de WhatsApp con IA

Atiende clientes por WhatsApp: entiende lo que piden, consulta el catálogo real
del negocio, arma el pedido, cobra y pasa a una persona cuando toca. Escucha
notas de voz, lee fotos de comprobantes y responde hablando si le hablan.

**No sabe nada del negocio que atiende.** Sirve igual a un bar que a una tienda
de tecnología: lo que cambia se conecta por contratos.

## Qué trae

| Capa | Qué hace |
|---|---|
| `Providers/` | Anthropic, Gemini y los ~14 compatibles con OpenAI. Descubre modelos por API: uno nuevo aparece sin tocar código |
| `Channel/` | Evolution API (WhatsApp), detrás de una interfaz por si se cambia de puente |
| `Core/` | El orquestador, las herramientas del modelo, el prompt, el techo de mensajes, el traspaso a un humano |
| `Media/` | Voz a texto, texto a voz y lectura de imágenes — con proveedor de pago **o servidor propio** |
| `Payments/` | Cobro con pasarela, transferencia o contra entrega, con verificación por webhook |

## Conectarlo a un proyecto

Se implementan siete puertos pequeños y el contrato del negocio. En
ControlBarMax son **~230 líneas** en un solo archivo:

```php
use ElkinLinan\WhatsappAiEngine\Engine;

Engine::arrancar([
    'db'      => new MiDb($conexion),   // obligatorio
    'dominio' => new MiAdaptador(),     // obligatorio: qué vende este negocio
    'secreto' => new MiCifrado(),       // cómo guarda sus claves
    'archivo' => new MiAlmacen(),       // dónde van las fotos y audios
    'formato' => new MiMoneda(),        // cómo se escribe el dinero
    // 'config', 'funcion' y 'negocio' son opcionales: sin planes de suscripción
    // y sin multi-negocio, los valores por defecto hacen lo evidente.
]);
```

## Conexión y voz preconfiguradas (defaults de plataforma)

Sin pasar un `ConfigPort` propio, el motor usa `Defecto\ConfigDeEntorno`: lee
del entorno la misma configuración predeterminada que el módulo original trae
en sus pantallas de **Conexión** y **Voz**. Basta definir estas constantes PHP
o variables de entorno y todos los negocios quedan servidos sin teclear nada:

| Variable | Qué preconfigura |
|---|---|
| `APP_URL` | Base pública del sitio (enlaces que manda el bot) |
| `WA_EVOLUTION_URL` / `WA_EVOLUTION_APIKEY` | Servidor Evolution de la plataforma: el negocio solo pone su instancia y su número |
| `WA_TTS_URL` / `WA_TTS_MODELO` | Voz open source de la plataforma (Piper): el bot responde hablando (modo `espejo` por defecto) |
| `WA_STT_URL` / `WA_STT_MODELO` | Transcripción open source (Whisper/speaches): el bot entiende notas de voz |
| `WA_VISION_URL` / `WA_VISION_MODELO` | Visión open source (Ollama y compatibles): el bot lee fotos y comprobantes |

Todo lo que el negocio configure en su panel **gana** sobre estos defaults, y
sin variables definidas el comportamiento es el de siempre: cada negocio pone
lo suyo. Para anular los defaults aunque el entorno los tenga, se pasa
`'config' => new Defecto\SinUrl()`.

Lo obligatorio es solo la base de datos y el adaptador de dominio. **Los dos
fallan ruidosamente si faltan**, en vez de inventar: un `DbPort` de mentira
convertiría un error de arranque en datos que no se guardan, y un dominio de
mentira, en un bot que conversa pero no vende.

## El contrato del negocio

`DomainAdapter` habla en neutro —`buscarItems`, `crearTransaccion`,
`confirmarTransaccion`— porque un nombre calcado de un dominio obliga al otro a
fingir. En un bar, «confirmar» manda la comanda a cocina; en una tienda, manda
el equipo a despacho.

Lo que no es universal va en interfaces aparte y el negocio declara en
`capacidades()` lo que de verdad tiene:

    SoportaEntrega · SoportaPromociones · SoportaMenuDelDia
    SoportaGarantias · SoportaServicioTecnico · SoportaCredito

**El motor solo le enseña al modelo las herramientas declaradas.** No es
cosmética: cada herramienta que sobra son tokens en todos los mensajes y una
puerta que el modelo puede ofrecerle a un cliente para nada.

## Probarlo

```bash
php tests/prueba.php
```

Corre el motor contra un negocio de mentira **que es una tienda, no un bar**:
sin base de datos real, sin el proyecto de origen, con dólares en vez de pesos.
Si pasa, el paquete está listo para otro proyecto; si falla, el contrato está
mal y hay que arreglarlo aquí, no en cada consumidor.

## De dónde sale

Se extrajo de ControlBarMax (donde lleva desde agosto de 2026 en producción:
pedidos, cobro con Wompi verificado de punta a punta, voz open source) el día
que apareció el segundo consumidor —MayTech POS— porque las abstracciones
equivocadas solo se ven cuando aparece el segundo.
