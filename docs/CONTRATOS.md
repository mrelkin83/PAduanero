# CONTRATOS DE SERVICIOS — PHP 8.2

> **Para Claude Code:** normativo. Ninguna implementación puede desviarse de
> estas firmas. Si necesitas un parámetro que no está aquí, **detente y pregunta**.
>
> Convenciones: `declare(strict_types=1)` en todo archivo. Namespace raíz `App\`.
> PSR-4 vía Composer. Fechas `'Y-m-d'`, horas `'H:i:s'`, dinero en **PESOS
> enteros**: ya no queda ninguna pasarela que cobre en centavos (ADR-010).
> Zona horaria de la aplicación: `America/Bogota`. En base de datos todo va en UTC.

---

## Estructura del proyecto

```
/
├── index.php                 ← único punto de entrada (front controller)
├── .env                      ← fuera del control de versiones
├── composer.json  package.json
├── CLAUDE.md  README.md
├── docs/                     CONTRATOS, PANEL_ADMIN, PRUEBAS, RUNBOOK,
│                             RESPALDOS, ARRANQUE_LOCAL
├── stitch_customs_law_digital_experience/   ← especificación visual
├── storage/                  logs/  cache/  config.sentinel   (no versionado)
├── public/
│   ├── img/                  ← fotos de Pedro; se sirve como URL /img
│   ├── fonts/                ← Geist y Geist Mono, servidas locales
│   ├── css/  js/
├── src/
│   ├── Core/                 Aplicacion, Router, Peticion, Respuesta, Contenedor, Csrf
│   ├── Motor/                Cuestionario y Catalogo — lo único que sobrevive
│   │                         del motor, porque de ellos cuelga /perfil
│   ├── Servicios/            Landing, Perfil, Seo, CachePagina, MetricasLanding,
│   │                         Config, Autenticacion, Permisos
│   ├── Repositorios/         UsuarioRepo, SesionRepo, IntentoAccesoRepo, AuditoriaRepo
│   ├── Modelos/              DTOs inmutables (readonly classes)
│   ├── Panel/                Controladores del panel administrativo
│   └── Soporte/              Fechas, Logger, Cifrado, Totp, Base32, Vista
├── plantillas/               landing/  perfil/  panel/   (PHP plano)
├── db/migraciones/           esquema MySQL 8 y semillas
└── bin/                      migrar, crear-usuario, cron-purgar, salud, respaldo,
                              auditar-landing.mjs, capturar.mjs
```

**El árbol conserva tablas y migraciones de un sistema que ya no existe.**
`casos`, `consultas`, `pagos`, `contactos`, `prompts`, `kb_*` y
`eventos_outbox` quedaron huérfanas al retirarse el motor y la pasarela, y se
conservan intactas por el ADR-013. Ninguna clase las toca. **No las uses para
nada nuevo:** si hace falta guardar algo, tabla nueva.

**index.php en la raíz** es requisito del PO. Todo lo demás va bajo `src/`, servido
con `AllowOverride` y un `.htaccess` que reescribe hacia `index.php`.

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

## `App\Soporte\Cifrado`

```php
final class Cifrado
{
    /** Devuelve el blob v1 ‖ nonce(12) ‖ tag(16) ‖ ciphertext */
    public function cifrar(string $claro): string;

    /** @throws CifradoException si el tag no valida (dato alterado o clave errónea) */
    public function descifrar(string $blob): string;

    /** '…123' — los últimos 3 caracteres. Lo único que puede salir por HTTP. */
    public static function mascara(string $valor): string;
}
```

Se construye con `MASTER_KEY` ya decodificada. Si falta o no mide 32 bytes, el
constructor lanza `ConfiguracionFatalException` y la aplicación no arranca. Es
deliberado: arrancar sin ella significaría escribir segundos factores que nadie
podrá volver a validar.

Hoy su único cliente es `usuarios.totp_secret_cifrado`. Cifraba también las
credenciales de proveedores y el NIT de los contactos, y traía un
`hashTelefono()` con su propio pepper (ADR-012); las tres cosas se fueron con
el motor y la pasarela, y con ellas la variable `PEPPER_TELEFONO`.

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

Locale `es_CO`. **Nunca** `date()` o `new DateTime()` desnudos: siempre por
esta clase. Los meses y días se escriben a mano en un array,
no con `IntlDateFormatter`, para no depender de que la extensión `intl` esté
compilada en el servidor.

---

## Errores que NO se deben cometer

1. SQL fuera de `src/Repositorios/`.
2. Escribir en las tablas huérfanas del motor (`casos`, `consultas`, `pagos`,
   `contactos`, `prompts`, `kb_*`, `eventos_outbox`). Están ahí por el
   ADR-013, no para reutilizarlas: si hace falta guardar algo, tabla nueva.
3. Guardar cualquier cosa que el visitante responda en `/perfil`. Cero
   persistencia es lo que mantiene esa página fuera de la ley de datos
   personales, y es la primera tentación que alguien va a querer romper.
4. Leer parámetros operativos de `.env` en vez de `configuraciones`.
5. Registrar en logs cualquier dato personal.
6. Capturar `23505` en vez de `1062`: esto es MySQL, no Postgres.
7. Usar un ORM o un framework completo. PDO y clases propias.
8. Concatenar variables en SQL. Sentencias preparadas, siempre.
9. Multiplicar o dividir por 100 en ninguna parte. Los pesos son pesos en
   todo el sistema; la única conversión que existía murió con la pasarela
   (ADR-010).
10. Hacer que la conversión dependa de un script. El sitio entero tiene que
    seguir funcionando con JavaScript apagado, y hay que **comprobarlo con el
    navegador**, no razonarlo (CLAUDE.md §4.5).
11. **Escribir sintaxis de MariaDB creyendo que es de MySQL.** La que más
    engaña es `ALTER TABLE … ADD COLUMN IF NOT EXISTS`: existe en MariaDB, y
    MySQL la rechaza con un error de sintaxis. Para una migración idempotente
    hay que consultar `information_schema` y preparar la sentencia solo si
    hace falta — el patrón está en `db/migraciones/0005_totp_endurecido.sql`.
    Otras de la misma familia: `DROP INDEX IF EXISTS`, `RENAME COLUMN` en
    versiones viejas, `SEQUENCE` y `RETURNING`.
12. Devolver un booleano donde el estándar exige más información. `Totp`
    devuelve el contador con el que casó, no un `true`: sin ese dato no se
    puede aplicar el antirreplay del RFC 6238 §5.2, y el fallo es silencioso
    — todo parece funcionar y un código robado sirve treinta segundos.
13. Detectar columnas generadas con `EXTRA NOT LIKE '%GENERATED%'`. MySQL
    también pone `DEFAULT_GENERATED` en `EXTRA` para toda columna con
    `DEFAULT (UUID())` o `DEFAULT CURRENT_TIMESTAMP` — es decir, casi todas
    las claves primarias de este esquema. El filtro correcto es
    `GENERATION_EXPRESSION`, que está vacía salvo en columnas realmente
    generadas. El modo de falla no se ve en rojo donde está el error: las
    semillas se restauran con UUID nuevos y lo que se rompe son las foráneas
    de otras pruebas, por motivos aparentemente inconexos. Pasó en
    `CasoBaseBd::restaurarSemillas()`.
14. Comparar un `DATETIME` de la base con el reloj de PHP usando
    `strtotime()`. La conexión fija `time_zone = '+00:00'`: **la base guarda
    UTC** y la aplicación convierte a Bogotá al presentar. `strtotime()` lee
    la cadena en la zona por defecto de PHP, así que el resultado se desvía
    cinco horas — y hacia el lado peligroso, porque una pausa parece haber
    vencido cuando no. Usar `Fechas::deUtc()` y comparar objetos
    `DateTimeImmutable`, que es una comparación absoluta. Síntoma
    característico: la prueba pasa aislada y falla en la suite completa,
    según qué otra clase haya construido `Aplicacion` —y con ella fijado la
    zona— antes.

    **Regla positiva: toda comparación de tiempo pasa por `App\Soporte\Fechas`.
    Nunca `strtotime()` suelto.** `time()` sí es legítimo cuando ambos lados
    son marcas Unix generadas por PHP —cookies, TTL de caché, el contador del
    TOTP— porque ahí no hay zona que interpretar; el veneno es mezclar una
    columna de la base con el reloj de PHP. Y cuando la comparación puede
    hacerse **en SQL** (`WHERE reserva_expira <= NOW()`), esa es la forma
    preferida: los dos lados salen del mismo reloj y no hay nada que
    convertir.

    El modo de falla importa más que las cinco horas: un fallo que depende del
    orden de ejecución se declara «flaky», alguien con prisa lo marca para
    saltarlo y el defecto vuelve invisible. Por eso la zona se fija en
    `tests/arranque.php` y no solo en `Aplicacion`.
18. Añadir una variable a una plantilla del panel sin ponerla en la lista
    `use` de su clausura `$contenido`. Dentro vale «indefinido», y el `?? 0`
    o el `?? []` de la línea que la usa la convierte en un valor **creíble y
    falso**, sin error, sin aviso y sin página en blanco.

    Ocurrió: `panel/ia.php` pintaba «—» en la columna «Modelos» para un
    proveedor que tenía 336 en la base. Nadie investiga un cero razonable.

    Lo cubre `PlantillasCapturanSusVariablesTest`, que compara las variables
    que el cuerpo de cada clausura **lee** contra las que **captura**. El
    aislamiento de la clausura vale la pena —impide que una plantilla lea
    datos que el controlador no le pasó— pero la lista tiene doce nombres,
    hay que tocarla cada vez que llega un dato nuevo, y olvidarla no rompe
    nada visible. El patrón invita al defecto; por eso lleva cinturón.

