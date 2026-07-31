# CONTRATOS DE SERVICIOS — PHP 8.2

> **Para Claude Code:** normativo. Ninguna implementación puede desviarse de
> estas firmas. Si necesitas un parámetro que no está aquí, **detente y pregunta**.
>
> Convenciones: `declare(strict_types=1)` en todo archivo. Namespace raíz `App\`.
> PSR-4 vía Composer. Fechas `'Y-m-d'`, horas `'H:i:s'`, dinero en **centavos de
> COP** en todo lo que toque pasarelas (400.000 COP = `40000000`).
> Zona horaria de la aplicación: `America/Bogota`. En base de datos todo va en UTC.

---

## Estructura del proyecto

```
/
├── index.php                 ← único punto de entrada (front controller)
├── .env                      ← fuera del control de versiones
├── composer.json
├── CLAUDE.md  README.md
├── docs/                     CONTRATOS, PANEL_ADMIN, PLAN_BUILD, PRUEBAS,
│                             RUNBOOK, RESPALDOS
├── motor/index.js            ← referencia conceptual de la Etapa 4, no se ejecuta
├── storage/                  logs/  cache/  config.sentinel   (no versionado)
├── public/
│   ├── img/                  ← fotos de Pedro; se sirve como URL /img
│   ├── css/  js/
├── src/
│   ├── Core/                 Router, Request, Response, Container, Csrf
│   ├── Motor/                MotorConversacional, Acciones, Estados
│   ├── Servicios/            Llm, Chatwoot, Agenda, Pagos, Outbox, Credenciales, Config, BaseConocimiento
│   ├── Repositorios/         ContactoRepo, CasoRepo, ConsultaRepo, …
│   ├── Modelos/              DTOs inmutables (readonly classes)
│   ├── Panel/                Controladores del panel administrativo
│   └── Soporte/              Fechas, Logger, Cifrado, Validador
├── plantillas/               Vistas (PHP plano, sin motor de plantillas)
├── db/                       schema.sql, schema_admin.sql, seeds.sql, migraciones/
└── bin/                      worker-outbox.php, cron-expirar-reservas.php
```

**index.php en la raíz** es requisito del PO. Todo lo demás va bajo `src/`, servido
con `AllowOverride` y un `.htaccess` que reescribe hacia `index.php`.

---

## `App\Servicios\Llm`

```php
interface Llm
{
    /**
     * @param array<int,array{role:'user'|'assistant',content:string}> $mensajes
     * @return RespuestaLlm  {texto, tokens, tokensEntrada, tokensSalida, modeloId, latenciaMs}
     * @throws LlmException  solo si fallan el primario y todos los fallbacks
     */
    public function chat(
        string $systemPrompt,
        array $mensajes,
        int $maxTokens = 600,
        float $temperatura = 0.4,
        string $proposito = 'conversacion',
        ?string $casoId = null
    ): RespuestaLlm;

    /** @param string|string[] $textos  @return array<int,array<int,float>> vectores de 1536 dims */
    public function embeddings(string|array $textos): array;

    public function recargarConfiguracion(): void;
}
```

Obligatorio: lee proveedor y modelo de `modelos_ia` (el que tenga `es_primario`),
cae en cascada por `orden_fallback` ante 5xx o timeout, escribe **siempre** una fila
en `consumo_ia` (también en el fallo), y corta si se superó
`presupuesto_ia_mensual_usd`. Transporte: cURL nativo, sin SDK.

---

## `App\Servicios\Chatwoot`

```php
interface Chatwoot
{
    public function responder(int $conversacionId, string $texto): int;    // → id del mensaje
    public function notaPrivada(int $conversacionId, string $texto): int;  // modo sombra
    public function etiquetar(int $conversacionId, array $etiquetas): void;
    public function cambiarPrioridad(int $conversacionId, string $prioridad): void; // urgent|high|medium|low
    public function asignarAlAbogado(int $conversacionId): void;
    public function cambiarEstado(int $conversacionId, string $estado): void;       // open|pending|resolved
    public function setAtributos(int $contactoId, array $atributos): void;
    public function sincronizarAgente(Usuario $usuario): int;              // → chatwoot_agent_id
}
```

Tres reintentos con backoff exponencial. Si Chatwoot no responde, el mensaje se
encola en `eventos_outbox`: nunca se pierde ni se duplica.

---

## `App\Servicios\Agenda`

```php
interface Agenda
{
    /** @return Modalidad[] activas, ordenadas por `orden` */
    public function getModalidades(): array;
    public function getModalidad(string $id): ?Modalidad;

    /**
     * Aplica horarios, bloqueos, consultas vivas, anticipacion_minima_horas
     * y dias_max_anticipacion. Nunca devuelve slots en el pasado.
     * @return array<int,array{hora:string,display:string}>
     */
    public function getSlotsDisponibles(string $modalidadId, string $fecha): array;

    public function proximaFechaConCupo(string $modalidadId, string $desde): ?string;
    public function generarEnlaceReunion(Consulta $consulta): ?string;
}
```

`Modalidad` es un `readonly class` con `id, nombre, duracionMin, precioCop,
modalidad, requierePago`.

---

## `App\Servicios\Pagos`

```php
interface Pagos
{
    /** @return array{url:string,referencia:string,pagoId:string,expiraEn:DateTimeImmutable} */
    public function crearLink(
        string $consultaId,
        int $montoCentavos,
        string $descripcion,
        Contacto $contacto
    ): array;

    /**
     * Valida la firma contra el CUERPO CRUDO, no contra el JSON parseado.
     * NO toca la base de datos: solo dice si la firma cuadra.
     * @return array{valido:bool,referencia:string,estado:string}
     */
    public function verificarWebhook(string $cuerpoCrudo, array $cabeceras): array;

    /**
     * Orquesta el webhook completo: llama a verificarWebhook() y, SOLO si la
     * firma valida, registra el pago y confirma la consulta. Si no valida,
     * no escribe nada en ninguna tabla.
     * Idempotente por `pagos.referencia`: el mismo evento dos veces confirma una.
     * @return array{valido:bool,procesado:bool,referencia:string,estado:string}
     */
    public function procesarWebhook(string $cuerpoCrudo, array $cabeceras): array;

    public function consultarEstado(string $referencia): array;
}
```

Innegociable: el webhook es idempotente — recibir dos veces el mismo evento no
confirma dos veces. Nunca se marca `pagada` sin `firma_verificada = 1`.

**Unidades (ADR-010).** `crearLink()` es el único punto del sistema donde los pesos
se convierten a centavos, y `pagos.monto_centavos` la única columna en centavos.
`modalidades_asesoria.precio_cop` y `consultas.precio_cop` están en pesos enteros.
Hay una prueba de nivel 1 que exige `40000000` para la modalidad sembrada.

---

## `App\Servicios\BaseConocimiento`

```php
interface BaseConocimiento
{
    /**
     * Sin pgvector. Estrategia en tres pasos:
     *  1. Prefiltro por `area` y `tipo_caso`.
     *  2. Prefiltro léxico con MATCH … AGAINST sobre el índice FULLTEXT.
     *  3. Coseno en PHP sobre los candidatos, usando `embedding_norma`
     *     precalculada. Con ~2.000 chunks son milisegundos.
     * Devuelve SOLO chunks de documentos con vigente=1 y verificado_por NOT NULL.
     * @return array<int,array{contenido:string,referencia:string,documentoId:string,similitud:float}>
     */
    public function buscar(string $texto, ?string $area, ?string $tipoCaso, int $limite = 4): array;

    public function indexarDocumento(string $documentoId): void;
}
```

Un fragmento sin verificación del abogado no entra al RAG bajo ninguna
circunstancia. Es la regla que impide que el bot cite algo que nadie revisó.

---

## `App\Servicios\Outbox`

```php
interface Outbox
{
    public function encolar(string $tipo, array $payload, ?DateTimeImmutable $disponibleEn = null): int;
    public function procesar(int $lote = 20): int;   // lo llama bin/worker-outbox.php
}
```

Tipos válidos: `notificar_abogado` · `enviar_mensaje` · `recordatorio_pago` ·
`recordatorio_consulta` · `email` · `sincronizar_chatwoot`.
Reintentos con backoff: 1 m, 5 m, 15 m, 1 h, 6 h. Al quinto fallo → `fallido` + alerta.

El worker se lanza con systemd o supervisor, no con `cron` cada minuto: necesita
correr en bucle con `sleep`, y reiniciarse solo si muere.

---

## `App\Servicios\Credenciales`

```php
interface Credenciales
{
    /** Descifra y devuelve el valor real. Registra la lectura en `auditoria`. */
    public function obtener(string $servicio, string $clave, string $entorno = 'produccion'): string;

    /** @return array{mascara:string} */
    public function guardar(string $servicio, string $clave, string $valor, string $entorno, string $usuarioId): array;

    /** @return array{ok:bool,mensaje:string} conectividad real contra el proveedor */
    public function probar(string $servicio, string $entorno): array;

    public function rotarClaveMaestra(string $nuevaClave): void;
}
```

Implementación: `openssl_encrypt($valor, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA,
$nonce, $tag)`. Nada casero. La clave maestra se lee de `MASTER_KEY` en el entorno,
32 bytes en base64. **Si no está definida, la aplicación no arranca.** Jamás se
persiste en base de datos. `obtener()` nunca se expone por HTTP: la API del panel
devuelve solo `mascara`.

**Formato del blob (ADR-011).** Un solo layout para todo campo cifrado del sistema:

```
v1 ‖ nonce(12) ‖ tag(16) ‖ ciphertext
```

Lo produce y lo consume `App\Soporte\Cifrado`. Aplica a `credenciales.valor_cifrado`,
`contactos.nit_cifrado` y `usuarios.totp_secret_cifrado`. Por eso `credenciales` ya
no tiene columnas `nonce` ni `tag`: eran un segundo camino para lo mismo.

`key_version` sobrevive y es otra cosa: dice **qué clave maestra** cifró el dato y lo
mueve `rotarClaveMaestra()`. El byte `v1` dice **qué layout** tiene el blob. Rotan
por razones distintas.

---

## `App\Servicios\Config`

```php
interface Config
{
    public function get(string $clave, mixed $porDefecto = null): mixed;
    public function set(string $clave, mixed $valor, string $usuarioId, ?string $motivo = null): void;
    /** @return Configuracion[] para pintar el formulario del panel */
    public function getGrupo(string $grupo): array;
    public function invalidarCache(?string $clave = null): void;
}
```

Caché de dos niveles con TTL de 60 s: **APCu** si la extensión está disponible
(obligatoria en el VPS: `pecl install apcu`), archivo en `storage/cache/` si no —
ese fallback existe para el entorno de desarrollo en Windows, no para producción.
`set()` valida tipo, rango y opciones contra la propia fila, escribe en
`configuraciones_historial` e invalida la caché en todos los procesos (incluido el
worker) tocando `storage/config.sentinel`, cuyo `mtime` se compara al leer.

---

## `App\Repositorios\*`

Un repositorio por agregado. Reciben y devuelven DTOs, nunca arrays crudos de PDO.
Todo el SQL vive aquí y solo aquí, siempre con sentencias preparadas.

| Repositorio | Métodos |
|---|---|
| `ContactoRepo` | `crear` · `porId` · `porTelefono` · `actualizarNombre` · `actualizarTipoPersona` |
| `CasoRepo` | `crear` · `actualizar` · `porId` · `porContacto` · `actualizarEstado` · `actualizarPuntaje` · `listarParaPanel` |
| `ConsultaRepo` | `reservar` · `porId` · `activasPorContacto` · `cambiarEstado` · `reagendar` · `expirarVencidas` |
| `ConversacionEstadoRepo` | `buscarOCrear` · `porCasoId` · `actualizar` · `acumularBuffer` · `reactivarIA` |
| `ConsentimientoRepo` | `registrar` · `vigentePorContacto` · `revocar` |
| `UsuarioRepo` | `crear` · `autenticar` · `porEmail` · `registrarAcceso` |

**`reservar()` — punto crítico.** Dos defensas, en este orden (ADR-015):

```php
$pdo->beginTransaction();

// 1ª línea: solapamiento REAL, no solo coincidencia de hora de inicio.
$vivas = $pdo->prepare(
    'SELECT hora_inicio, hora_fin FROM consultas
      WHERE fecha = ? AND estado IN (\'reservada\',\'pagada\',\'realizada\')
      FOR UPDATE'
);
$vivas->execute([$fecha]);
foreach ($vivas->fetchAll() as $v) {
    if ($horaInicio < $v['hora_fin'] && $v['hora_inicio'] < $horaFin) {
        $pdo->rollBack();
        throw new SlotOcupadoException();
    }
}

// 2ª línea: el índice único sobre `slot_unico`, por si algo se saltó la primera.
try {
    $stmt->execute($params);
} catch (\PDOException $e) {
    $pdo->rollBack();
    if ($e->errorInfo[1] === 1062) {
        throw new SlotOcupadoException();
    }
    throw $e;
}
$pdo->commit();
```

Ese `1062` es el equivalente MySQL del `23505` de Postgres. El motor traduce
`SlotOcupadoException` a "ese horario se acaba de tomar".

Por qué no basta el índice: `slot_unico` es `CONCAT(fecha,'T',hora_inicio)`, así que
solo bloquea horas de inicio idénticas. Con una modalidad de 30 minutos creada
desde el panel, 14:00–15:00 y 14:30–15:30 conviven sin violarlo. La comparación
`(inicio_a < fin_b) AND (inicio_b < fin_a)` bajo `FOR UPDATE` es la que realmente
protege; el índice es la red debajo.

**`crear()` de `CasoRepo` — radicado (ADR-014).** Asigna `PA-YYYY-NNNNNN` dentro de
la misma transacción que inserta el caso, tomando el consecutivo de
`secuencias (anio, ultimo)` con `SELECT … FOR UPDATE`. Nunca `MAX(id)+1`: con dos
mensajes concurrentes entrega el mismo radicado dos veces y el `UNIQUE` de
`casos.radicado_interno` revienta la creación en plena conversación.

**`crear()` de `ContactoRepo` — hash (ADR-012).** `telefono_hash` se calcula con
`Cifrado::hashTelefono()`, que es `HMAC-SHA256` con `PEPPER_TELEFONO`. Nunca con
`sha256()` a secas.

---

## `App\Soporte\Cifrado`

```php
final class Cifrado
{
    /** Devuelve el blob v1 ‖ nonce(12) ‖ tag(16) ‖ ciphertext */
    public function cifrar(string $claro): string;

    /** @throws CifradoException si el tag no valida (dato alterado o clave errónea) */
    public function descifrar(string $blob): string;

    /** HMAC-SHA256 con PEPPER_TELEFONO. Determinista: sirve para buscar. */
    public function hashTelefono(string $telefonoE164): string;

    /** '…123' — los últimos 3 caracteres. Lo único que puede salir por HTTP. */
    public static function mascara(string $valor): string;
}
```

Se construye con `MASTER_KEY` y `PEPPER_TELEFONO` ya decodificadas. Si cualquiera
de las dos falta o no mide 32 bytes, el constructor lanza
`ConfiguracionFatalException` y la aplicación no arranca. Es deliberado: arrancar
sin ellas significaría escribir datos que nadie podrá volver a leer.

---

## `App\Soporte\Fechas`

```php
final class Fechas
{
    public static function ahora(): DateTimeImmutable;              // en America/Bogota
    public static function fechaNatural(string $fecha): string;     // 'martes 4 de agosto'
    public static function horaNatural(string $hora): string;       // '2:30 p. m.'
    public static function sumarMinutos(string $hora, int $min): string;
    public static function restarHoras(string $fecha, string $hora, int $n): DateTimeImmutable;
    public static function aUtc(DateTimeImmutable $d): DateTimeImmutable;
}
```

Locale `es_CO`. **Nunca** `date()` o `new DateTime()` desnudos para lógica de
agenda: siempre por esta clase. Los meses y días se escriben a mano en un array,
no con `IntlDateFormatter`, para no depender de que la extensión `intl` esté
compilada en el servidor.

---

## Errores que NO se deben cometer

1. SQL fuera de `src/Repositorios/`.
2. Devolver el valor descifrado de una credencial en cualquier respuesta HTTP.
3. Llamar a Evolution directamente para responderle a un contacto — va por Chatwoot.
4. Escribir en las tablas de Chatwoot por SQL.
5. Hacer llamadas HTTP externas dentro de una transacción — para eso está el outbox.
6. Leer parámetros operativos de `.env` en vez de `configuraciones`.
7. Registrar en logs contenido de mensajes, NIT o credenciales.
8. Capturar `23505` en vez de `1062`: esto es MySQL, no Postgres.
9. Usar un ORM o un framework completo. PDO y clases propias.
10. Concatenar variables en SQL. Sentencias preparadas, siempre.
11. Multiplicar o dividir por 100 fuera de `Pagos::crearLink()`. Los pesos son
    pesos en todo el sistema menos en `pagos.monto_centavos` (ADR-010).
12. Hashear el teléfono con `sha256()` a secas, o derivar `PEPPER_TELEFONO` de
    `MASTER_KEY`. Rotar la clave maestra dejaría todos los hashes huérfanos y la
    búsqueda por hash fallaría en silencio (ADR-012).
13. Confiar solo en el índice `slot_unico` para evitar la doble reserva. Es la
    segunda línea; la primera es la validación de solapamiento (ADR-015).
