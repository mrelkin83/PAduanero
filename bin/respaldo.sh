#!/usr/bin/env bash
# =====================================================================
# RESPALDO DIARIO — Pedro Abogado Aduanero
# Cron sugerido:  15 3 * * *  /var/www/pedro/bin/respaldo.sh
#
# Respalda los cuatro componentes respaldables. La MASTER_KEY NO va aquí:
# viaja por su propio camino (ver docs/RESPALDOS.md §4).
#
# Cifra con clave PÚBLICA de age: el servidor puede cifrar pero no
# descifrar. Si comprometen el VPS, los respaldos siguen ilegibles.
# =====================================================================
set -euo pipefail
IFS=$'\n\t'

RAIZ="/var/www/pedro"
DESTINO="/var/respaldos/pedro"
REMOTO="remoto:pedro-respaldos"
RETENCION_LOCAL_DIAS=7
SELLO="$(date +%Y%m%d-%H%M%S)"
TRABAJO="${DESTINO}/tmp-${SELLO}"
LOG="/var/log/pedro/respaldo.log"

log() { echo "[$(date -Is)] $*" | tee -a "$LOG"; }
fallo() { log "ERROR: $*"; alerta "Respaldo FALLIDO: $*"; exit 1; }

alerta() {
  # Avisa por WhatsApp al número de alertas usando Evolution.
  # Un respaldo que falla en silencio es peor que no tenerlo.
  [[ -n "${ALERTA_WHATSAPP:-}" ]] || return 0
  curl -sf -X POST "${EVOLUTION_URL}/message/sendText/${EVOLUTION_INSTANCE}" \
    -H "apikey: ${EVOLUTION_API_KEY}" -H 'Content-Type: application/json' \
    -d "$(jq -nc --arg n "$ALERTA_WHATSAPP" --arg t "$1" '{number:$n,text:$t}')" \
    >/dev/null || true
}

# --- Cargar entorno --------------------------------------------------
[[ -f "${RAIZ}/.env" ]] || fallo "no existe ${RAIZ}/.env"
set -a; source "${RAIZ}/.env"; set +a
[[ -n "${AGE_CLAVE_PUBLICA:-}" ]] || fallo "AGE_CLAVE_PUBLICA no definida"

mkdir -p "$TRABAJO"/{mysql,chatwoot,evolution,archivos} "$(dirname "$LOG")"
trap 'rm -rf "$TRABAJO"' EXIT

log "=== Respaldo ${SELLO} ==="

# --- 1. MySQL de la aplicación ---------------------------------------
log "MySQL: volcando ${DB_NAME}"
mysqldump \
  --host="${DB_HOST}" --port="${DB_PORT}" \
  --user="${DB_USER}" --password="${DB_PASS}" \
  --single-transaction --quick --routines --triggers --events \
  --no-tablespaces \
  --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF \
  "${DB_NAME}" > "${TRABAJO}/mysql/${DB_NAME}.sql" \
  || fallo "mysqldump"

# Verificación mínima: un volcado truncado también pesa.
#
# Se comprueban las tablas que de verdad duele perder, y el sello que
# mysqldump escribe al final: sin él, el volcado se cortó a la mitad.
# Antes se miraba `casos`, que es una tabla huérfana del motor retirado
# (CLAUDE.md §0.1) — comprobar algo que ya nadie usa es comprobar que el
# archivo existe, no que sirva.
#
# `--no-tablespaces` arriba, por lo mismo: el usuario de la aplicación no
# tiene el privilegio PROCESS, y sin esa bandera mysqldump escupe un error
# por los tablespaces aunque el volcado salga bien.
for TABLA in landing_bloques configuraciones usuarios; do
  grep -q "CREATE TABLE \`${TABLA}\`" "${TRABAJO}/mysql/${DB_NAME}.sql" \
    || fallo "el volcado de MySQL no trae la tabla ${TABLA}"
done

grep -q '^-- Dump completed' "${TRABAJO}/mysql/${DB_NAME}.sql" \
  || fallo "el volcado de MySQL se cortó antes de terminar"

# Binlogs de la última hora, para el RPO de pagos.
if [[ -d /var/log/mysql ]]; then
  find /var/log/mysql -name 'binlog.*' -mmin -1500 \
    -exec cp {} "${TRABAJO}/mysql/" \; 2>/dev/null || true
fi

# --- 2. Postgres de Chatwoot -----------------------------------------
log "Chatwoot: volcando Postgres"
if docker ps --format '{{.Names}}' | grep -q chatwoot-postgres; then
  docker exec chatwoot-postgres \
    pg_dump -U postgres -Fc chatwoot > "${TRABAJO}/chatwoot/chatwoot.dump" \
    || fallo "pg_dump de Chatwoot"
  docker exec chatwoot-postgres \
    tar cf - /app/storage 2>/dev/null > "${TRABAJO}/chatwoot/adjuntos.tar" || true
else
  log "AVISO: contenedor chatwoot-postgres no encontrado, se omite"
fi

# --- 3. Sesión de Evolution ------------------------------------------
# Sin esto hay que reescanear el QR y WhatsApp queda caído mientras tanto.
log "Evolution: copiando instancias"
if [[ -d /opt/evolution/instances ]]; then
  tar cf "${TRABAJO}/evolution/instances.tar" -C /opt/evolution instances
else
  log "AVISO: /opt/evolution/instances no existe, se omite"
fi

# --- 4. Archivos de la aplicación ------------------------------------
log "Archivos: img y adjuntos"
tar cf "${TRABAJO}/archivos/publicos.tar" \
  -C "${RAIZ}/public" img 2>/dev/null || log "AVISO: sin carpeta img"
# Con `set -e`, un `[[ … ]] && cmd` que da falso devuelve 1 y mata el
# script entero. Aquí eso significaba que NO tener adjuntos abortaba el
# respaldo completo, después de haber volcado bien MySQL. Va como `if`.
if [[ -d "${RAIZ}/storage/adjuntos" ]]; then
  tar cf "${TRABAJO}/archivos/adjuntos.tar" -C "${RAIZ}/storage" adjuntos
fi

# .env SIN las claves de cifrado: se respalda la configuración de conexión,
# no las llaves. Esas van por otro canal (docs/RESPALDOS.md §4).
grep -vE '^MASTER_KEY=' "${RAIZ}/.env" \
  > "${TRABAJO}/archivos/env.sinclave" || true

# Verificación: si el filtro se rompe, el respaldo cifrado contendría las
# llaves que lo protegen. Preferimos fallar el respaldo a filtrarlas.
if grep -qE '^MASTER_KEY=.' "${TRABAJO}/archivos/env.sinclave"; then
  fallo "el .env filtrado todavía contiene claves de cifrado"
fi

# --- 5. Empaquetar y cifrar ------------------------------------------
log "Empaquetando"
PAQUETE="${DESTINO}/pedro-${SELLO}.tar"
tar cf "${PAQUETE}" -C "${TRABAJO}" .

log "Cifrando con age"
age -r "${AGE_CLAVE_PUBLICA}" -o "${PAQUETE}.age" "${PAQUETE}" || fallo "age"
rm -f "${PAQUETE}"

TAMANO=$(du -h "${PAQUETE}.age" | cut -f1)
log "Listo: ${PAQUETE}.age (${TAMANO})"

# Un respaldo sospechosamente pequeño casi siempre es un respaldo roto.
BYTES=$(stat -c%s "${PAQUETE}.age")
[[ "$BYTES" -gt 51200 ]] || fallo "el respaldo pesa solo ${BYTES} bytes"

# --- 6. Fuera del servidor -------------------------------------------
#
# La regla 3-2-1 (docs/RESPALDOS.md §3) pide una copia fuera del sitio, y
# esta es la mitad que todavía no está: hace falta un bucket y sus
# credenciales, que no se inventan desde aquí.
#
# Mientras no lo haya, el respaldo local SÍ se hace y el script NO falla.
# La alternativa —abortar— era peor de lo que parece: dejaba el sistema sin
# ningún respaldo, ni siquiera el local, por no tener la copia remota. Un
# respaldo en el mismo servidor no protege contra perder el servidor, pero
# sí contra lo que de verdad pasa a diario: un borrado por error, una
# migración que sale mal, un contenido que alguien pisó desde el panel.
#
# El aviso es RUIDOSO a propósito. Que salga en cada corrida es la única
# forma de que esto no se quede así para siempre.
if rclone listremotes 2>/dev/null | grep -q "^${REMOTO%%:*}:"; then
  log "Subiendo a ${REMOTO}"
  rclone copy "${PAQUETE}.age" "${REMOTO}/diario/" || fallo "rclone"

  # Semanal (domingo) y mensual (día 1). Mismo motivo que arriba para no
  # usar `&&`: cualquier día que no fuera domingo mataba el script justo
  # después de haber subido bien el respaldo diario.
  if [[ "$(date +%u)" == "7" ]]; then
    rclone copy "${PAQUETE}.age" "${REMOTO}/semanal/"
  fi
  if [[ "$(date +%d)" == "01" ]]; then
    rclone copy "${PAQUETE}.age" "${REMOTO}/mensual/"
  fi

  rclone delete "${REMOTO}/diario/"  --min-age 7d  || true
  rclone delete "${REMOTO}/semanal/" --min-age 28d || true
  rclone delete "${REMOTO}/mensual/" --min-age 365d || true
else
  log "AVISO: sin destino externo configurado (rclone remoto «${REMOTO%%:*}»)."
  log "AVISO: el respaldo queda SOLO en este servidor — la regla 3-2-1 no se cumple."
fi

# --- 7. Rotación local -----------------------------------------------
find "${DESTINO}" -name 'pedro-*.tar.age' -mtime "+${RETENCION_LOCAL_DIAS}" -delete

log "=== Respaldo ${SELLO} completado ==="
