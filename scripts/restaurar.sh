#!/usr/bin/env bash
#
# RESTAURACIÓN
#
#   ./scripts/restaurar.sh storage/backups/bd_lanoticia_2026-09-05_0315.sql.gz
#
# Antes de tocar nada, hace un volcado de seguridad del estado actual.
# Si la restauración sale mal, ese volcado es el camino de vuelta.
#
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

ARCHIVO="${1:-}"

if [[ -z "$ARCHIVO" || ! -f "$ARCHIVO" ]]; then
  echo "Uso: $0 storage/backups/bd_<base>_<fecha>.sql.gz"
  echo
  echo "Respaldos disponibles:"
  ls -1t storage/backups/bd_*.sql.gz 2>/dev/null | head -20 || echo "  (ninguno)"
  exit 1
fi

leer() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed 's/^["'"'"']//;s/["'"'"']$//'; }

DB_HOST="$(leer DB_HOST)"; DB_PORT="$(leer DB_PORT)"
DB_NAME="$(leer DB_DATABASE)"; DB_USER="$(leer DB_USERNAME)"; DB_PASS="$(leer DB_PASSWORD)"

echo "Vas a restaurar sobre la base «$DB_NAME»."
echo "Archivo: $ARCHIVO"
echo
read -r -p "Escribe el nombre de la base de datos para confirmar: " CONFIRMA

if [[ "$CONFIRMA" != "$DB_NAME" ]]; then
  echo "La confirmación no coincide. No se tocó nada."
  exit 1
fi

echo "▸ Verificando el archivo..."
gzip -t "$ARCHIVO" || { echo "El archivo está corrupto. Se aborta."; exit 1; }

SEGURIDAD="storage/backups/antes-de-restaurar_$(date -u +%Y-%m-%d_%H%M).sql.gz"
echo "▸ Guardando el estado actual en $SEGURIDAD..."
MYSQL_PWD="$DB_PASS" mysqldump --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
  --single-transaction --quick --default-character-set=utf8mb4 "$DB_NAME" | gzip -9 > "$SEGURIDAD"

echo "▸ Restaurando..."
zcat "$ARCHIVO" | MYSQL_PWD="$DB_PASS" mysql --host="$DB_HOST" --port="$DB_PORT" \
  --user="$DB_USER" --default-character-set=utf8mb4 "$DB_NAME"

echo "▸ Reconstruyendo el índice del buscador..."
php scripts/reindexar.php

echo
echo "─────────────────────────────────────────────"
echo " Restauración terminada."
echo " Copia de seguridad previa: $SEGURIDAD"
echo "─────────────────────────────────────────────"
echo
echo "Comprueba ahora, en este orden:"
echo "  1. Abre la portada."
echo "  2. Abre una noticia por su URL permanente."
echo "  3. Busca una palabra que sepas que existe."
echo "  4. Entra al panel."
