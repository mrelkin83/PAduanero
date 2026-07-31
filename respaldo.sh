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
  --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF \
  "${DB_NAME}" > "${TRABAJO}/mysql/${DB_NAME}.sql" \
  || fallo "mysqldump"

# Verificación mínima: un volcado truncado también pesa.
grep -q "CREATE TABLE \`casos\`" "${TRABAJO}/mysql/${DB_NAME}.sql" \
  || fallo "el volcado de MySQL parece incompleto (falta la tabla casos)"

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
[[ -d "${RAIZ}/storage/adjuntos" ]] && \
  tar cf "${TRABAJO}/archivos/adjuntos.tar" -C "${RAIZ}/storage" adjuntos

# .env SIN la MASTER_KEY: se respalda la configuración de conexión,
# no la llave que descifra todo. Esa va por otro canal.
grep -v '^MASTER_KEY=' "${RAIZ}/.env" > "${TRABAJO}/archivos/env.sinclave" || true

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
log "Subiendo a ${REMOTO}"
rclone copy "${PAQUETE}.age" "${REMOTO}/diario/" || fallo "rclone"

# Semanal (domingo) y mensual (día 1).
[[ "$(date +%u)" == "7" ]] && rclone copy "${PAQUETE}.age" "${REMOTO}/semanal/"
[[ "$(date +%d)" == "01" ]] && rclone copy "${PAQUETE}.age" "${REMOTO}/mensual/"

# --- 7. Rotación -----------------------------------------------------
find "${DESTINO}" -name 'pedro-*.tar.age' -mtime "+${RETENCION_LOCAL_DIAS}" -delete
rclone delete "${REMOTO}/diario/"  --min-age 7d  || true
rclone delete "${REMOTO}/semanal/" --min-age 28d || true
rclone delete "${REMOTO}/mensual/" --min-age 365d || true

log "=== Respaldo ${SELLO} completado ==="
