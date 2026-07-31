# POLÍTICA DE RESPALDOS Y RECUPERACIÓN

> Un respaldo que nunca se restauró no es un respaldo: es una carpeta con
> archivos. La prueba mensual de restauración (§5) no es opcional.

---

## 1. Qué hay que respaldar

Son **cinco** cosas distintas, en tres sistemas distintos. Perder cualquiera duele
de forma diferente.

| # | Qué | Dónde vive | Si se pierde |
|---|---|---|---|
| 1 | MySQL `pedro_aduanero` | MySQL propio | Casos, consultas, pagos, configuración, contenido. El negocio. |
| 2 | Postgres de Chatwoot | Docker `/opt/chatwoot` | Todo el historial de conversaciones con clientes. |
| 3 | Sesión de Evolution | Volumen `/opt/evolution/instances` | Hay que reescanear el QR. Recuperable, pero deja WhatsApp caído mientras tanto. |
| 4 | `/public/img` y adjuntos | Sistema de archivos | Fotos de Pedro y documentos subidos. |
| 5 | **`MASTER_KEY` y `.env`** | Variable de entorno | **Irrecuperable.** Todas las credenciales cifradas se pierden. |

El punto 5 es el único que no se arregla con un restore, y por eso viaja por un
camino separado (§4).

---

## 2. Objetivos

| Métrica | Objetivo | Traducción |
|---|---|---|
| **RPO** (pérdida máxima aceptable) | 24 h para bases · 1 h para pagos | Un día de casos se puede reconstruir desde Chatwoot. Un pago perdido, no. |
| **RTO** (tiempo máximo de recuperación) | 4 h | Medio día hábil sin atender es tolerable; un día completo, no. |

Para cumplir el RPO de una hora en pagos: MySQL con `binlog` activo y respaldo
incremental horario del binlog, además del volcado diario completo.

```ini
# my.cnf
log_bin = /var/log/mysql/binlog
binlog_expire_logs_seconds = 604800   # 7 días
sync_binlog = 1
```

---

## 3. Calendario y retención

| Tipo | Frecuencia | Retención | Dónde |
|---|---|---|---|
| Completo (todo lo del §1) | Diario, 3:15 a. m. | 7 días | VPS + fuera del servidor |
| Binlog de MySQL | Horario | 7 días | Fuera del servidor |
| Completo semanal | Domingo | 4 semanas | Fuera del servidor |
| Completo mensual | Día 1 | 12 meses | Fuera del servidor, almacenamiento frío |
| Previo a despliegue | Manual | 3 versiones | VPS |

**Regla 3-2-1:** tres copias, en dos medios distintos, una fuera del sitio. El
respaldo que solo vive en el mismo VPS no cuenta: si se pierde el servidor, se
pierde con él.

Destino externo sugerido: object storage con `rclone` (Backblaze B2, Wasabi o
similar). Barato y suficiente para este volumen.

---

## 4. Cifrado y la clave maestra

Todos los respaldos se cifran **antes** de salir del servidor. Contienen datos de
clientes bajo secreto profesional y datos personales bajo la Ley 1581 de 2012;
subirlos en claro a un bucket sería el peor incidente posible del proyecto.

```bash
# Cifrado con clave pública: el servidor puede cifrar pero no descifrar.
# Si comprometen el VPS, los respaldos siguen siendo ilegibles.
age -r "$AGE_CLAVE_PUBLICA" -o respaldo.tar.age respaldo.tar
```

La clave **privada** de `age` no vive en el servidor. Vive donde tú puedas
alcanzarla y el atacante no.

### La MASTER_KEY

No va en el respaldo automático. Nunca. Va por su propio camino:

1. Copia en un gestor de contraseñas (1Password, Bitwarden, KeePass).
2. Copia impresa en papel, en sobre cerrado, fuera de la oficina.
3. Copia en poder de Pedro, no solo tuya. Si te pasa algo, el negocio no puede
   quedar sin acceso a sus propias credenciales.

Se rota cada 12 meses con `Credenciales::rotarClaveMaestra()`, que re-cifra todo e
incrementa `key_version`. Después de rotar, actualizar las tres copias.

---

## 5. Prueba de restauración — mensual, obligatoria

El primer lunes de cada mes, en un entorno aparte (VPS de pruebas o contenedor
local), no en producción:

```bash
# 1. Traer el respaldo más reciente
rclone copy remoto:pedro-respaldos/ultimo ./restauracion/

# 2. Descifrar
age -d -i clave-privada.txt -o restauracion.tar restauracion.tar.age
tar xf restauracion.tar

# 3. Restaurar MySQL
mysql -u root -p pedro_prueba < mysql/pedro_aduanero.sql

# 4. Restaurar Postgres de Chatwoot
docker exec -i chatwoot-postgres psql -U postgres chatwoot_prueba < chatwoot/dump.sql
```

**Lista de verificación** — anotar el resultado en una bitácora, con fecha:

- [ ] El volcado de MySQL importa sin errores.
- [ ] `SELECT COUNT(*) FROM casos` coincide con producción del día del respaldo.
- [ ] Las columnas generadas (`slot_unico`, `activo_key`, `primario_key`) existen.
- [ ] Las credenciales se descifran con la `MASTER_KEY` guardada.
- [ ] Chatwoot levanta y muestra conversaciones.
- [ ] Tiempo total de restauración **< 4 h** (RTO).

Si algo falla, se arregla ese mes. No el siguiente.

---

## 6. Retención de datos personales

Los respaldos guardan datos personales, así que la retención tiene un límite legal
además de uno técnico. Cuando un titular ejerce supresión (Ley 1581 de 2012), el
borrado en producción es inmediato, pero **no se reescriben los respaldos
históricos**: se documenta que el dato quedará eliminado cuando ese respaldo salga
del ciclo de retención, y se registra la solicitud en `auditoria`.

Por eso la retención mensual es de 12 meses y no indefinida. Guardar respaldos
para siempre es un pasivo, no un activo.

Configuraciones relacionadas, editables desde el panel:
`retencion_conversaciones_dias` (730 por defecto) y
`retencion_casos_descartados_dias` (365).

---

## 7. Qué NO respaldar

- `vendor/` — se reconstruye con `composer install`.
- Logs de aplicación — van a su propia rotación con `logrotate`, 30 días.
- Caché de APCu, archivos temporales, `storage/cache`.
- Imágenes de Docker — se reconstruyen desde los compose.

El respaldo debe ser pequeño para poder restaurarse rápido. Meter dentro cosas
reconstruibles alarga el RTO sin ganar nada.
