# POLÍTICA DE PRUEBAS

> En este sistema una regresión no rompe el build: hace que la página le diga un
> plazo equivocado a alguien con la mercancía retenida, o que el diagnóstico
> deje de verse sin que nada falle. Las pruebas no están aquí para subir un
> porcentaje de cobertura, sino para que eso no pase.

Herramienta: **PHPUnit 11**. Sin frameworks de prueba adicionales.

---

## 1. Qué se prueba y con qué severidad

Tres niveles. El nivel 1 bloquea el despliegue, sin excepciones.

### Nivel 1 — Bloqueantes

Fallo aquí = no se despliega, aunque el cliente esté esperando.

| Qué | Por qué |
|---|---|
| **El copy no nombra plazos ni normas numeradas** (`CuestionarioTest::elCopyNoNombraPlazosNiNormas`) | Es la razón de ser de este sistema: un plazo mal dicho cuesta un caso y compromete a Pedro bajo la Ley 1123 de 2007 |
| **La salida crítica sale de `Catalogo::esCritico()`**, no de una lista aparte | Con dos listas, la segunda se queda atrás y el diagnóstico le pregunta la cuantía a quien tiene la POLFA en la puerta |
| **«Todavía no hay nada abierto» termina el cuestionario en el paso 1** | Negar después de cinco preguntas es peor que no preguntar |
| Cifrado y descifrado del secreto TOTP | Si falla, nadie vuelve a entrar al panel |
| Antirreplay del TOTP (RFC 6238 §5.2) | Un código robado serviría treinta segundos |
| Permisos por rol | Un rol de más en el panel es acceso a todo el contenido público |
| Que la landing y `/perfil` se pinten **sin JavaScript** | La conversión no puede depender de un script (CLAUDE.md §4.5) |

### Nivel 2 — Importantes

Fallo = se arregla antes del siguiente despliegue.

Bloques de la landing y su orden. Caché de páginas y su invalidación.
Configuración: rangos, tipos e historial. Registro de eventos de la landing y
su tope por sesión. SEO: sitemap, robots y JSON-LD. Bitácora.

### Nivel 3 — Deseables

Formato de fechas en español, paginación, exportaciones.

---

## 2. Ejecución

```bash
composer test              # todo
composer test:criticas     # solo nivel 1 — antes de cada despliegue
```

`composer.json`:

```json
"scripts": {
  "test": "phpunit",
  "test:criticas": "phpunit --group critica"
}
```

Base de datos de pruebas separada (`pedro_pruebas`), recreada en cada corrida
aplicando `db/migraciones/` con `App\Core\Migrador` — el mismo runner que el
despliegue. Lo hace `tests/CasoBaseBd::recrear()`: elimina todas las tablas y
migra desde cero, así que una migración que falle rompe las pruebas antes que
la producción.

**Nunca** apuntar las pruebas a producción: `tests/arranque.php` verifica que
`DB_NAME` termine en `_pruebas` y aborta el arranque si no.

---

## 3. Cobertura

Sin objetivo global de porcentaje: invita a escribir pruebas triviales para subir
el número. Objetivos por zona:

| Zona | Mínimo | |
|---|---|---|
| `src/Motor/` (`Cuestionario` y `Catalogo`) | 90 % | Es el copy que no puede nombrar plazos |
| `src/Soporte/Cifrado` y `Totp` | 100 % | Si fallan, nadie entra al panel |
| `src/Servicios/` | 80 % | Landing, Perfil, Seo, CachePagina, MetricasLanding |
| `src/Repositorios/` | 70 % | |
| `src/Panel/` | 50 % | |
| Plantillas y vistas | sin objetivo | Lo cubre `bin/auditar-landing.mjs` |

> Las cifras medidas que había aquí eran de un árbol que ya no existe: la
> suite pasó de 530 pruebas a 200 al retirarse el motor y la pasarela. Hay que
> volver a medir antes de fijarlas.

### Cómo medirlo

Contar pruebas no es medir cobertura. Hace falta un driver, que **no** es
dependencia del proyecto — solo se necesita para medir, no para correr la
suite:

```bash
# Linux / VPS
pecl install pcov
php -d extension=pcov.so -d pcov.enabled=1 -d pcov.directory=src \
    vendor/bin/phpunit --coverage-text
```

En Windows, el DLL correspondiente a la versión y al *thread safety* del PHP
instalado se descarga de `windows.php.net/downloads/pecl/releases/pcov/` y se
carga con `-d extension=<ruta>`, sin tocar el `php.ini`.

**phpdbg ya no sirve**: `php-code-coverage` 11, la de PHPUnit 11, retiró ese
driver. Si aparece «No code coverage driver available», es eso.

---

## 4. Antes de cada despliegue

- [ ] `composer test:criticas` en verde.
- [ ] **Cobertura por zona contra los mínimos de §3.** No basta con que la
      suite pase: una zona entera puede quedarse sin cubrir sin que ninguna
      prueba se ponga roja. Pasó — al terminar el panel, `src/Panel/` estaba
      al **0 %** con 154 pruebas en verde, porque solo lo ejercitaba un script
      de navegador. Se degrada en silencio, así que se mide en cada despliegue.
- [ ] Si cambió el esquema: migración probada sobre copia del respaldo de anoche.
- [ ] Respaldo manual hecho.

---

## 5. Qué no vale la pena probar

Getters y setters. Que Nginx sirva un archivo estático. El HTML exacto de una
plantilla: cambia con cada retoque de diseño y una prueba sobre él se convierte
en algo que hay que actualizar en vez de algo que protege.

Del copy se prueba lo que **no** puede decir —plazos, normas numeradas,
promesas de resultado— nunca lo que debe decir palabra por palabra.
