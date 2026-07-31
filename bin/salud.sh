#!/usr/bin/env bash
# =====================================================================
# VERIFICACIÓN DE SALUD — Pedro Abogado Aduanero
# Cron sugerido:  */10 * * * *  /var/www/pedro/bin/salud.sh
#
# Salida legible para ejecución manual; alerta por WhatsApp solo cuando
# algo está mal, para que la alerta signifique algo.
# =====================================================================
set -uo pipefail

RAIZ="/var/www/pedro"
set -a; source "${RAIZ}/.env" 2>/dev/null; set +a

FALLOS=0
DETALLE=""

ok()    { printf '  \033[32m✓\033[0m %s\n' "$1"; }
mal()   { printf '  \033[31m✗\033[0m %s\n' "$1"; FALLOS=$((FALLOS+1)); DETALLE="${DETALLE}• $1"$'\n'; }
aviso() { printf '  \033[33m!\033[0m %s\n' "$1"; }

echo "Salud del sistema — $(date -Is)"
echo

# --- Aplicación ------------------------------------------------------
echo "Aplicación"
if curl -sf -m 10 "${APP_URL}/salud" >/dev/null; then ok "landing y panel responden"
else mal "la aplicación no responde en ${APP_URL}"; fi

if systemctl is-active --quiet pedro-outbox; then ok "worker del outbox activo"
else mal "worker del outbox detenido"; fi

# --- Base de datos ---------------------------------------------------
echo
echo "Base de datos"
MYSQL="mysql -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER} -p${DB_PASS} ${DB_NAME} -N -B -e"

if $MYSQL "SELECT 1" >/dev/null 2>&1; then ok "MySQL responde"
else mal "MySQL no responde"; fi

# La columna generada que impide la doble reserva. Si alguien la eliminó
# "porque MySQL se quejaba", el sistema puede agendar dos clientes a la
# misma hora sin que nada más lo detecte.
SLOT=$($MYSQL "SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='consultas'
  AND COLUMN_NAME='slot_unico'" 2>/dev/null || echo 0)
if [[ "$SLOT" == "1" ]]; then ok "protección contra doble reserva presente"
else mal "FALTA la columna slot_unico: se pueden agendar dos consultas a la misma hora"; fi

# --- Chatwoot --------------------------------------------------------
echo
echo "Chatwoot"
if curl -sf -m 10 "${CHATWOOT_URL}/api" >/dev/null; then ok "Chatwoot responde"
else mal "Chatwoot no responde"; fi

# --- WhatsApp --------------------------------------------------------
echo
echo "WhatsApp (Evolution)"

# El código HTTP se mira aparte del cuerpo: un 503 no es «desconectado», es
# «licencia sin activar», y el remedio es otro (CLAUDE.md §1.3). Confundirlos
# manda a alguien a buscar un QR cuando lo que hay que hacer es entrar al
# Manager.
HTTP=$(curl -s -o /tmp/pedro-evolution.json -w '%{http_code}' -m 10 \
  -H "apikey: ${EVOLUTION_API_KEY}" \
  "${EVOLUTION_URL}/instance/connectionState/${EVOLUTION_INSTANCE}" 2>/dev/null || echo "000")

if [[ "$HTTP" == "503" ]]; then
  mal "LICENCIA SIN ACTIVAR (503): el contenedor se recreó. Activar en /manager y revisar EVOLUTION_OPERATOR_EMAIL — CLAUDE.md §1.3"
elif [[ "$HTTP" == "000" ]]; then
  mal "Evolution no responde en ${EVOLUTION_URL}"
elif [[ "$HTTP" != "200" ]]; then
  mal "Evolution devolvió HTTP ${HTTP}"
else
  ESTADO=$(jq -r '.instance.state // .state // "desconocido"' /tmp/pedro-evolution.json 2>/dev/null || echo "desconocido")
  case "$ESTADO" in
    open)       ok "instancia conectada" ;;
    connecting) mal "WhatsApp CONECTANDO: falta escanear el QR — ver RUNBOOK §3.1" ;;
    close)      mal "WhatsApp DESCONECTADO — ver RUNBOOK §3.1" ;;
    *)          mal "WhatsApp en estado «${ESTADO}» — ver RUNBOOK §3.1" ;;
  esac
fi
rm -f /tmp/pedro-evolution.json

# --- Outbox ----------------------------------------------------------
echo
echo "Cola de salida"
PEND=$($MYSQL "SELECT COUNT(*) FROM eventos_outbox WHERE estado='pendiente'" 2>/dev/null || echo "?")
FALL=$($MYSQL "SELECT COUNT(*) FROM eventos_outbox WHERE estado='fallido'" 2>/dev/null || echo "?")
ATASC=$($MYSQL "SELECT COUNT(*) FROM eventos_outbox WHERE estado='procesando'
  AND creado_en < NOW() - INTERVAL 15 MINUTE" 2>/dev/null || echo "?")

[[ "$PEND" -lt 50 ]]  2>/dev/null && ok "pendientes: ${PEND}" || aviso "pendientes acumulados: ${PEND}"
[[ "$FALL" -eq 0 ]]   2>/dev/null && ok "sin fallidos" || mal "eventos fallidos: ${FALL}"
[[ "$ATASC" -eq 0 ]]  2>/dev/null && ok "sin atascos" || mal "atascados en 'procesando': ${ATASC} — ver RUNBOOK §3.5"

# --- Estado del motor ------------------------------------------------
echo
echo "Motor conversacional"
PAUSA=$($MYSQL "SELECT valor FROM configuraciones WHERE clave='motor_ia_pausado'" 2>/dev/null || echo '?')
SOMBRA=$($MYSQL "SELECT valor FROM configuraciones WHERE clave='motor_modo_sombra'" 2>/dev/null || echo '?')
[[ "$PAUSA" == "true" ]]  && aviso "la IA está PAUSADA" || ok "IA activa"
[[ "$SOMBRA" == "true" ]] && aviso "modo sombra encendido (no envía al cliente)" || ok "envío automático"

GASTO=$($MYSQL "SELECT COALESCE(ROUND(SUM(costo_usd),2),0) FROM consumo_ia
  WHERE creado_en >= DATE_FORMAT(NOW(),'%Y-%m-01')" 2>/dev/null || echo '?')
echo "  · gasto de IA este mes: USD ${GASTO}"

# --- Respaldo --------------------------------------------------------
echo
echo "Respaldos"
ULTIMO=$(find /var/respaldos/pedro -name 'pedro-*.tar.age' -mtime -1 2>/dev/null | head -1)
if [[ -n "$ULTIMO" ]]; then ok "respaldo de las últimas 24 h: $(basename "$ULTIMO")"
else mal "NO hay respaldo de las últimas 24 h"; fi

# --- Disco -----------------------------------------------------------
echo
echo "Sistema"
USO=$(df -h / | awk 'NR==2 {print $5}' | tr -d '%')
[[ "$USO" -lt 85 ]] && ok "disco al ${USO}%" || mal "disco al ${USO}% — Chatwoot cae cuando se llena"

# --- Resumen y alerta ------------------------------------------------
echo
if [[ "$FALLOS" -eq 0 ]]; then
  echo "Todo en orden."
  exit 0
fi

echo "${FALLOS} problema(s) detectado(s)."
if [[ -n "${ALERTA_WHATSAPP:-}" ]]; then
  curl -sf -X POST "${EVOLUTION_URL}/message/sendText/${EVOLUTION_INSTANCE}" \
    -H "apikey: ${EVOLUTION_API_KEY}" -H 'Content-Type: application/json' \
    -d "$(jq -nc --arg n "$ALERTA_WHATSAPP" \
          --arg t "Alerta del sistema ($(hostname)):"$'\n'"${DETALLE}" \
          '{number:$n,text:$t}')" >/dev/null || true
fi
exit 1
