# POLÍTICA DE RESPALDOS Y RECUPERACIÓN

> Un respaldo que nunca se restauró no es un respaldo: es una carpeta con
> archivos. La prueba mensual de restauración (§5) no es opcional.

---

## 1. Qué hay que respaldar

Son **tres** cosas distintas. Perder cualquiera duele de forma diferente.

Eran cinco: el Postgres de Chatwoot con todo el historial de conversaciones y
el volumen de sesión de Evolution se fueron con la bandeja y la pasarela de
WhatsApp. Ya no hay nada que respaldar fuera de este VPS.

| # | Qué | Dónde vive | Si se pierde |
|---|---|---|---|
| 1 | MySQL `pedro_aduanero` | MySQL propio | Configuración, contenido de las páginas, usuarios del panel y bitácora. |
| 2 | `/public/img` | Sistema de archivos | Las fotos de Pedro. |
| 3 | **`MASTER_KEY` y el `.env`** | Variable de entorno | **Irrecuperable.** Se pierden los segundos factores: nadie vuelve a entrar al panel. |

El punto 3 es el único que no se arregla con un restore, y por eso viaja por un
camino separado (§4).

---

## 2. Objetivos

| Métrica | Objetivo | Traducción |
|---|---|---|
| **RPO** (pérdida máxima aceptable) | 24 h | Lo que se pierde es, como mucho, un día de ediciones de contenido y de eventos de la landing. Nada de eso es irreemplazable. |
| **RTO** (tiempo máximo de recuperación) | 4 h | Medio día hábil sin atender es tolerable; un día completo, no. |

El volcado diario completo basta. El `binlog` con respaldo incremental horario
se montó para el RPO de una hora que exigían los pagos; sin pasarela, no hay
nada tan caro de perder como para justificarlo.

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

No va en el respaldo automático. Nunca. Va por su propio camino, en tres copias:

1. Copia en un gestor de contraseñas (1Password, Bitwarden, KeePass).
2. Copia impresa en papel, en sobre cerrado, fuera de la oficina.
3. Copia en poder de Pedro, no solo tuya. Si te pasa algo, el negocio no puede
   quedar sin acceso a sus propias credenciales.

**Rotación: cada 12 meses, y ahora cuesta más que antes.**

Rotar era barato mientras existió `Credenciales::rotarClaveMaestra()`, que
descifraba con la vieja, re-cifraba con la nueva y subía `key_version`. Esa
clase se fue con el motor.

Hoy lo único cifrado con esta llave son los secretos TOTP de `usuarios`, y no
hay rutina que los migre. Rotar significa **obligar a todos a volver a
configurar su segundo factor**, uno por uno con `bin/restablecer-2fa.php`. Con
tres o cuatro cuentas es asumible; conviene saberlo antes de empezar y no a
mitad.

Después de rotar, actualizar las tres copias.

> Aquí había también un `PEPPER_TELEFONO`, que no rotaba nunca porque un hash
> no es reversible y cambiarlo habría dejado huérfanos todos los
> `telefono_hash`. Se retiró con `contactos`, su único cliente. Si vuelve el
> motor, vuelve con la misma regla.

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
```

**Lista de verificación** — anotar el resultado en una bitácora, con fecha:

- [ ] El volcado de MySQL importa sin errores.
- [ ] `SELECT COUNT(*) FROM landing_bloques` coincide con producción del día
      del respaldo, y `configuraciones` también.
- [ ] Las columnas generadas del esquema existen. Se comprueban con
      `GENERATION_EXPRESSION`, no con `EXTRA` — MySQL marca
      `DEFAULT_GENERATED` en casi todas las claves primarias de este esquema.
- [ ] **Un usuario del panel entra con su segundo factor.** Es lo que prueba
      que la `MASTER_KEY` guardada es la correcta: si no lo es, el TOTP no
      valida y no hay otra forma de darse cuenta.
- [ ] La landing responde 200 y pinta sus bloques.
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
