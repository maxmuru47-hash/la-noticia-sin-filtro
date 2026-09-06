#!/usr/bin/env bash
#
# RESPALDO DE BASE DE DATOS Y MEDIOS
#
#   ./scripts/respaldo.sh
#
# Lee las credenciales de .env, así que no hay contraseñas aquí dentro.
# Guarda en storage/backups/ y aplica retención automática.
#
# En el cron del alojamiento, una vez al día:
#   (a las 3:15) /bin/bash /ruta/al/proyecto/scripts/respaldo.sh >> /ruta/al/proyecto/storage/logs/respaldo.log 2>&1
#
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

if [[ ! -f .env ]]; then
  echo "ERROR: no encuentro .env. Sin credenciales no hay respaldo."
  exit 1
fi

# Se leen solo las variables que hacen falta, sin volcar todo el entorno.
leer() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed 's/^["'"'"']//;s/["'"'"']$//'; }

DB_HOST="$(leer DB_HOST)"
DB_PORT="$(leer DB_PORT)"
DB_NAME="$(leer DB_DATABASE)"
DB_USER="$(leer DB_USERNAME)"
DB_PASS="$(leer DB_PASSWORD)"

DESTINO="storage/backups"
SELLO="$(date -u +%Y-%m-%d_%H%M)"
RETENCION_DIARIA="${RETENCION_DIARIA:-14}"
RETENCION_MEDIOS="${RETENCION_MEDIOS:-7}"

mkdir -p "$DESTINO"

VOLCADO="$DESTINO/bd_${DB_NAME}_${SELLO}.sql.gz"

echo "▸ Volcando la base de datos..."
# La contraseña va por variable de entorno, no por argumento: un
# argumento de línea de comandos es visible para cualquier proceso.
MYSQL_PWD="$DB_PASS" mysqldump \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
  --single-transaction --quick --routines --triggers --events \
  --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF 2>/dev/null \
  "$DB_NAME" | gzip -9 > "$VOLCADO" \
  || MYSQL_PWD="$DB_PASS" mysqldump \
       --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
       --single-transaction --quick --routines --triggers \
       --default-character-set=utf8mb4 \
       "$DB_NAME" | gzip -9 > "$VOLCADO"

# VERIFICACIÓN. Un respaldo que no se comprueba no es un respaldo.
if ! gzip -t "$VOLCADO" 2>/dev/null; then
  echo "ERROR: el volcado está corrupto. Se elimina."
  rm -f "$VOLCADO"
  exit 1
fi

TABLAS=$(zcat "$VOLCADO" | grep -c "^CREATE TABLE" || true)
if [[ "$TABLAS" -lt 20 ]]; then
  echo "ERROR: el volcado solo tiene $TABLAS tablas. Se esperaban al menos 20."
  exit 1
fi

echo "  Base de datos: $VOLCADO ($TABLAS tablas)"

echo "▸ Empaquetando los medios..."
MEDIOS="$DESTINO/medios_${SELLO}.tar.gz"
tar -czf "$MEDIOS" \
  --exclude='*.tmp' \
  public/uploads storage/originals 2>/dev/null || true
echo "  Medios: $MEDIOS"

# --- Retención ---------------------------------------------------------
# Se conservan los últimos N diarios, y el primero de cada mes para
# siempre. El archivo editorial es permanente: sus respaldos también
# deben poder alcanzar años atrás.

echo "▸ Aplicando retención..."
ls -1t "$DESTINO"/bd_*.sql.gz 2>/dev/null | tail -n +$((RETENCION_DIARIA + 1)) | while read -r viejo; do
  DIA=$(basename "$viejo" | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}' | cut -d- -f3)
  if [[ "$DIA" != "01" ]]; then
    rm -f "$viejo"
    echo "  Eliminado: $(basename "$viejo")"
  fi
done

ls -1t "$DESTINO"/medios_*.tar.gz 2>/dev/null | tail -n +$((RETENCION_MEDIOS + 1)) | while read -r viejo; do
  rm -f "$viejo"
done

echo
echo "─────────────────────────────────────────────"
du -sh "$DESTINO" | awk '{print " Espacio ocupado: " $1}'
echo " Respaldos guardados: $(ls -1 "$DESTINO"/bd_*.sql.gz 2>/dev/null | wc -l)"
echo "─────────────────────────────────────────────"
echo
echo "IMPORTANTE: copia estos archivos FUERA del servidor."
echo "Un respaldo que vive en la misma máquina no protege de perder la máquina."
