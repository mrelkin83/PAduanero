# Certificado de finalización — diseño

**Fecha:** 2026-08-29 · **Sub-proyecto:** 4 de 4 del módulo de cursos, el último
**Depende de:** sub-proyecto 2 (cobro + cuenta de comprador — `compradores.numero_documento_cifrado`
ya existe pensado para esto) y sub-proyecto 3 (contenido protegido — `AulaControlador::leccion()`),
ambos ya en producción.

## 0. Qué resuelve esto

Hasta hoy el comprador puede pagar, ver el temario y consumir el contenido de cada lección,
pero no hay ninguna noción de «terminé el curso» ni nada que mostrar por haberlo hecho. El
sub-proyecto 3 dejó esto fuera de alcance a propósito, señalando que era este sub-proyecto
quien debía decidir qué significa completar un curso y qué seguimiento hacía falta agregar.

Este sub-proyecto agrega: (1) seguimiento de qué lecciones ya vio cada comprador, (2) la
noción de «curso completado» derivada de eso, (3) un certificado en PDF descargable cuando
se completa, y (4) una página pública donde cualquiera puede verificar que un certificado es
legítimo.

## 1. Decisiones del Product Owner

Todas tomadas en la sesión de brainstorming del 2026-08-29:

| Punto | Decisión |
|---|---|
| Qué significa «completar» | Ver todas las lecciones del curso — automático, sin botón manual |
| Formato del certificado | PDF descargable |
| Generación del PDF | `dompdf/dompdf` vía Composer — **primera dependencia de producción del proyecto**, decisión consciente del PO |
| Verificación pública | Código único + página pública `/certificados/verificar`, sin necesidad de cuenta |
| Número de documento en el certificado | Sí, en el PDF que descarga el propio comprador — **nunca** en la página pública de verificación |
| Cuándo se emite | En tiempo real, al registrar la vista que completa la última lección — sin cron ni trabajo en segundo plano |

## 2. Modelo de datos

Migración aditiva `db/migraciones/0035_certificados.sql` (ADR-013).

**Tabla nueva `curso_progreso`** — una fila la primera vez que un comprador ve una lección:

```sql
CREATE TABLE curso_progreso (
  id            CHAR(36)   NOT NULL DEFAULT (UUID()),
  comprador_id  CHAR(36)   NOT NULL,
  leccion_id    CHAR(36)   NOT NULL,
  visto_en      TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ix_progreso_unico (comprador_id, leccion_id),
  CONSTRAINT fk_progreso_comprador FOREIGN KEY (comprador_id) REFERENCES compradores(id) ON DELETE CASCADE,
  CONSTRAINT fk_progreso_leccion FOREIGN KEY (leccion_id) REFERENCES curso_lecciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

El `UNIQUE (comprador_id, leccion_id)` es lo que hace que «registrar una vista» sea
idempotente — verla dos veces no duplica la fila (se usa `INSERT IGNORE`).

**Tabla nueva `certificados`** — una fila por certificado emitido, única por compra:

```sql
CREATE TABLE certificados (
  id                    CHAR(36)      NOT NULL DEFAULT (UUID()),
  compra_id             CHAR(36)      NOT NULL,
  codigo_verificacion   VARCHAR(20)   NOT NULL,
  emitido_en            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ix_certificados_compra (compra_id),
  UNIQUE KEY ix_certificados_codigo (codigo_verificacion),
  CONSTRAINT fk_certificados_compra FOREIGN KEY (compra_id) REFERENCES compras_curso(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

`compra_id` (no `comprador_id`+`curso_id`) porque `compras_curso` ya ata exactamente un
comprador a exactamente un curso — es la misma fila que ya identifica de forma única «este
comprador compró este curso», y evita una segunda forma de expresar lo mismo.

El `codigo_verificacion` tiene el formato `PA-XXXXXXXX` (8 caracteres hexadecimales en
mayúscula, de `bin2hex(random_bytes(4))`) — corto para copiar a mano, con entropía de sobra
para que adivinar uno ajeno no sea práctico. El `UNIQUE` fuerza a reintentar en la
generación si por azar colisiona (mismo patrón que `PanelCursosControlador::slugUnico()`).

## 3. Registro de progreso y emisión — `App\Cuenta\ProgresoCurso`

Servicio nuevo, paralelo a `AccesoLeccion` en estilo:

```php
final class ProgresoCurso
{
    public function __construct(
        private readonly BD $bd,
        private readonly CertificadoRepo $certificados,
    ) {}

    /** Registra la vista y emite el certificado si con esta lección se completó el curso. */
    public function registrarVista(string $compradorId, string $leccionId, string $cursoId, string $compraId): void
    {
        $this->bd->pdo()->prepare(
            'INSERT IGNORE INTO curso_progreso (comprador_id, leccion_id) VALUES (?, ?)'
        )->execute([$compradorId, $leccionId]);

        if ($this->estaCompleto($compradorId, $cursoId) && $this->certificados->porCompra($compraId) === null) {
            $this->certificados->crear($compraId, $this->codigoUnico());
        }
    }

    public function estaCompleto(string $compradorId, string $cursoId): bool { /* ... */ }

    /** @return array{vistas:int,total:int} */
    public function conteo(string $compradorId, string $cursoId): array { /* ... */ }

    private function codigoUnico(): string { /* reintenta si colisiona, mismo patrón que slugUnico() */ }
}
```

`CertificadoRepo` es un repo nuevo con el mismo patrón que `CursoMaterialRepo`:
`crear(string $compraId, string $codigo): void`, `porCompra(string $compraId): ?array`,
`porCodigo(string $codigo): ?array` (esta última con `JOIN` hasta `compradores` y `cursos`
para traer de una vez lo que necesita la página de verificación).

`registrarVista()` necesita el `compraId`, no solo el `cursoId` — `CompraCursoRepo` gana
`idDePagadaPorComprador(string $compradorId, string $cursoId): ?string` (una consulta
`SELECT id FROM compras_curso WHERE comprador_id = ? AND curso_id = ? AND estado = 'pagada'`),
al lado de `tienePagada()`, que ya hace casi la misma consulta pero devuelve `bool` en vez
del `id`. `AulaControlador::leccion()` ya tiene todo lo demás que hace falta: `$comprador`,
`$curso['id']`, `$leccion['id']`.

`AulaControlador::leccion()` llama a `registrarVista()` justo después de que
`AccesoLeccion::puedeVer()` confirma acceso — **solo si el acceso vino por compra real**
(`$comprador !== null && $compras->tienePagada(...)`), nunca para una vista previa gratis
vista por un anónimo o por un comprador que no compró ese curso. Ver una lección de vista
previa DENTRO de un curso que sí se compró cuenta igual que cualquier otra — para el
comprador que ya pagó, «vista previa» es solo una etiqueta de marketing, no una lección de
segunda categoría.

## 4. El PDF — `App\Cuenta\CertificadoPdf`

Con `dompdf/dompdf`. Arma un HTML fijo (nombre completo, curso, fecha de emisión, código de
verificación, número de documento) y lo convierte a PDF en memoria — nunca se guarda un
archivo en disco, se genera en cada descarga.

`CompradorRepo` gana `numeroDocumento(string $compradorId): ?string`, el único lugar del
código (además de `crear()`, que lo escribe) que descifra esa columna — ninguna plantilla ni
pantalla lo selecciona en ningún otro sitio, igual que ya establecía el sub-proyecto 2.

**Ruta:** `GET /mis-cursos/{slug}/certificado` — exige sesión de comprador y que exista un
certificado para su compra de ese curso (si no existe todavía, redirige a
`/mis-cursos/{slug}` sin más: no ha terminado, no hay nada que descargar).

## 5. Verificación pública

`GET /certificados/verificar` — formulario simple, pide el código.
`GET /certificados/verificar/{codigo}` — sin sesión, cualquiera. Si el código existe,
muestra nombre completo del comprador, título del curso y fecha de emisión. Si no existe,
un mensaje neutral («ese código no corresponde a ningún certificado») — nunca se distingue
entre «código mal escrito» y «código que nunca existió», mismo principio anti-enumeración ya
usado en el resto del sitio. **Nunca** se muestra el número de documento aquí.

## 6. En el sitio

**`/mis-cursos/{slug}` (el aula, sub-proyecto 3):** se agrega el conteo de progreso («3 de 5
lecciones vistas») y, cuando `ProgresoCurso::estaCompleto()` es cierto, un enlace «Descargar
certificado» en vez del contador.

**Panel — `/panel/cursos/compras` (ya existe):** una columna nueva «Certificado»: «Sí
(PA-XXXXXXXX)» o «—». Sin pantalla nueva — reutiliza la tabla que Pedro ya mira.

### 6.1 Un certificado ya emitido nunca se revoca

Si Pedro agrega una lección nueva a un curso después de que un comprador ya se ganó su
certificado, ese certificado sigue siendo válido — no se revisa retroactivamente. Es la
misma lógica de cualquier diploma real: si el programa crece después, no le quita el título
a quien ya se graduó. `estaCompleto()` solo importa ANTES de que exista la fila en
`certificados`; una vez existe, `registrarVista()` ni siquiera vuelve a evaluarla (el
`$this->certificados->porCompra($compraId) === null` de la Sección 3 corta antes).

## 7. Manejo de errores

- `registrarVista()` nunca lanza si ya existe la fila (`INSERT IGNORE`) — ver la misma
  lección cien veces no rompe nada ni duplica el certificado.
- Descargar el certificado sin haberlo ganado → redirige a `/mis-cursos/{slug}`, sin error
  alarmante (es un estado normal, no una falla).
- Código de verificación inexistente → mensaje neutral, nunca un 404 crudo ni una pista de
  si el formato era válido.
- Si `dompdf` falla al generar (memoria, HTML mal formado) → se captura y se muestra un
  error genérico «no se pudo generar el certificado en este momento», nunca la traza cruda.

## 8. Pruebas

- `ProgresoCursoTest`: registrar la misma vista dos veces no duplica la fila; completar la
  última lección emite el certificado exactamente una vez; completar dos veces (webhook /
  vista duplicada) no emite un segundo certificado ni cambia el código ya emitido.
- `CertificadoPdfTest`: el PDF generado no está vacío y contiene el nombre del comprador (se
  puede extraer texto de un PDF simple para la aserción, o verificar que el HTML fuente antes
  de pasarlo a dompdf contiene los datos esperados — decisión de implementación).
- Rutas: sin certificado → `/mis-cursos/{slug}/certificado` redirige; con certificado →
  descarga PDF. Verificación: código real → muestra datos; código inventado → mensaje
  neutral, nunca el número de documento en la respuesta.

## 9. Pendiente operativo (no es código)

- [ ] `composer require dompdf/dompdf` — confirmar que el VPS tiene las extensiones PHP que
      dompdf necesita (gd o similar para imágenes, si el diseño del certificado las usa).
- [ ] Diseño visual del certificado (más allá de lo funcional) — logo, tipografía, borde —
      es un ajuste de la plantilla HTML, no bloquea la funcionalidad.
