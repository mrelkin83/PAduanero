#!/usr/bin/env bash
# =====================================================================
# VERIFICACIÓN DE SALUD — Pedro Abogado Aduanero
# Cron sugerido:  */10 * * * *  /var/www/pedro/bin/salud.sh
#
# Encogió con el sistema. Antes vigilaba seis subsistemas —Chatwoot,
# Evolution, el worker del outbox, el gasto de IA, el catálogo de modelos y
# la doble reserva de cupo— porque cada uno podía romperse solo y en
# silencio. Al retirarse el motor y la pasarela, lo que queda es una
# aplicación PHP contra MySQL: mucho menos que vigilar, y mucho menos que
# se pueda romper sin que se note.
#
# Cambió también cómo avisa. La alerta salía por WhatsApp a través de
# Evolution, que ya no existe; ahora el aviso es el código de salida y lo
# que se imprime. Cron manda por correo la salida de un trabajo que falla,
# así que el canal existe — solo que no lo pone este script.
# =====================================================================
set -uo pipefail

RAIZ="/var/www/pedro"
set -a; source "${RAIZ}/.env" 2>/dev/null; set +a

FALLOS=0

ok()    { printf '  \033[32m✓\033[0m %s\n' "$1"; }
mal()   { printf '  \033[31m✗\033[0m %s\n' "$1"; FALLOS=$((FALLOS+1)); }
aviso() { printf '  \033[33m!\033[0m %s\n' "$1"; }

echo "Salud del sistema — $(date -Is)"
echo

# --- Aplicación ------------------------------------------------------
echo "Aplicación"
if curl -sf -m 10 "${APP_URL}/salud" >/dev/null; then ok "landing y panel responden"
else mal "la aplicación no responde en ${APP_URL}"; fi

# La landing se sirve de una caché en disco. Que exista no prueba que sea
# correcta, pero que NO exista después de una visita sí prueba que algo
# impide escribirla —permisos casi siempre—, y entonces cada visita
# renderiza de cero contra MySQL.
if [[ -w "${RAIZ}/storage/cache" ]]; then ok "la caché de páginas es escribible"
else mal "storage/cache no es escribible: cada visita renderiza contra MySQL"; fi

# --- Base de datos ---------------------------------------------------
echo
echo "Base de datos"
# `--init-command` fija la misma zona que usa la aplicación al escribir
# (BD::pdo hace `SET time_zone = '+00:00'`). Sin esto, las consultas de abajo
# comparan columnas escritas en UTC contra un NOW() en la zona del servidor, y
# los conteos por ventana de tiempo salen desplazados sin que nada falle.
MYSQL="mysql -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER} -p${DB_PASS} ${DB_NAME} -N -B \
  --init-command=SET time_zone='+00:00' -e"

if $MYSQL "SELECT 1" >/dev/null 2>&1; then ok "MySQL responde"
else mal "MySQL no responde"; fi

# Migraciones aplicadas contra migraciones en disco. Un despliegue que copia
# los archivos pero no corre `bin/migrar.php` deja la aplicación pidiéndole a
# MySQL columnas que no existen, y eso se manifiesta como un 500 en una
# pantalla concreta, no como una caída — puede tardar días en notarse.
EN_DISCO=$(find "${RAIZ}/db/migraciones" -name '*.sql' 2>/dev/null | wc -l)
APLICADAS=$($MYSQL "SELECT COUNT(*) FROM migraciones" 2>/dev/null || echo '?')
if [[ "$APLICADAS" == "$EN_DISCO" ]]; then ok "las ${EN_DISCO} migraciones están aplicadas"
else mal "migraciones: ${APLICADAS} aplicadas de ${EN_DISCO} en disco — correr bin/migrar.php"; fi

# --- Retención de datos personales -----------------------------------
# `intentos_acceso` guarda IP, que es dato personal bajo la Ley 1581 de 2012.
# Si el cron deja de correr, la retención deja de cumplirse en silencio: nada
# se rompe, solo se acumulan datos que no deberían estar ahí.
echo
echo "Retención de datos"
PURGA=$($MYSQL "SELECT COUNT(*) FROM auditoria
  WHERE entidad='sistema' AND accion='purga'
    AND creado_en > NOW() - INTERVAL 24 HOUR" 2>/dev/null || echo "?")

if [[ "$PURGA" -ge 1 ]] 2>/dev/null; then
  ok "la purga corrió en las últimas 24 h"
else
  mal "cron-purgar.php NO ha corrido en 24 h: la retención de IP no se está cumpliendo"
fi

# --- Respaldo --------------------------------------------------------
echo
echo "Respaldos"
ULTIMO=$(find /var/respaldos/pedro -name 'pedro-*.tar.age' -mtime -1 2>/dev/null | head -1)
if [[ -n "$ULTIMO" ]]; then ok "respaldo de las últimas 24 h: $(basename "$ULTIMO")"
else mal "NO hay respaldo de las últimas 24 h"; fi

# --- Sistema ---------------------------------------------------------
echo
echo "Sistema"
USO=$(df -h / | awk 'NR==2 {print $5}' | tr -d '%')
[[ "$USO" -lt 85 ]] && ok "disco al ${USO}%" || mal "disco al ${USO}%"

# --- Resumen ---------------------------------------------------------
echo
if [[ "$FALLOS" -eq 0 ]]; then
  echo "Todo en orden."
  exit 0
fi

echo "${FALLOS} problema(s) detectado(s)."
exit 1
