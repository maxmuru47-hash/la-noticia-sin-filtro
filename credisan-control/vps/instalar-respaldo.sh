#!/usr/bin/env bash
# =====================================================================
# CrediSan Control · dejar el respaldo andando EN ESTE SERVIDOR
# =====================================================================
# Se ejecuta UNA vez, como root, en el VPS de CrediSan:
#
#     sudo bash instalar-respaldo.sh
#
# Deja tres cosas y nada más:
#   · /usr/local/sbin/credisan-respaldo      el guion
#   · /etc/credisan/respaldo.env             la contraseña, sólo para root
#   · una tarea de cron a la 01:10 de Caracas
#
# NO toca nada más del servidor. Ni Caddy, ni las otras webs, ni la
# carpeta publicada. La contraseña se pide por teclado y no se escribe
# en el historial ni en ningún registro.
# =====================================================================
set -euo pipefail

[ "$(id -u)" = "0" ] || { echo "Ejecútelo con sudo."; exit 1; }

RAIZ="${CREDISAN_RAIZ:-/opt/proyectos/credisan-control}"
GUION_ORIGEN="$(dirname "$(readlink -f "$0")")/respaldo.sh"
GUION="/usr/local/sbin/credisan-respaldo"
CONFIG_DIR="/etc/credisan"
CONFIG="$CONFIG_DIR/respaldo.env"

[ -f "$GUION_ORIGEN" ] || { echo "No encuentro respaldo.sh junto a este archivo."; exit 1; }

echo "── 1 · El cliente de PostgreSQL ───────────────────────────────"
# pg_dump tiene que ser igual o más nuevo que la base de Supabase (17),
# o se niega a copiarla. Y hace bien.
if ls /usr/lib/postgresql/1[78]/bin/pg_dump >/dev/null 2>&1; then
  echo "   ya está: $(ls /usr/lib/postgresql/1[78]/bin/pg_dump | tail -1)"
else
  echo "   instalando postgresql-client-17…"
  apt-get update -qq
  apt-get install -y -qq curl ca-certificates gnupg lsb-release
  curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    | gpg --dearmor -o /usr/share/keyrings/pgdg.gpg
  echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
    > /etc/apt/sources.list.d/pgdg.list
  apt-get update -qq
  apt-get install -y -qq postgresql-client-17
fi

echo "── 2 · El guion ───────────────────────────────────────────────"
install -m 750 -o root -g root "$GUION_ORIGEN" "$GUION"
echo "   $GUION"

echo "── 3 · La cadena de conexión ──────────────────────────────────"
mkdir -p "$CONFIG_DIR"; chmod 700 "$CONFIG_DIR"
if [ -s "$CONFIG" ]; then
  echo "   ya existe $CONFIG — NO se toca."
  echo "   (para cambiarla: sudo nano $CONFIG)"
else
  echo ""
  echo "   Pegue la cadena del **Session pooler** de Supabase."
  echo "   Supabase → Project Settings → Database → Connection string → Session pooler"
  echo "   No se va a ver mientras la escribe, ni queda en el historial."
  echo ""
  printf "   Cadena: "
  read -rs PGURL_NUEVA
  echo ""
  [ -n "$PGURL_NUEVA" ] || { echo "   Vacía. No se instaló nada."; exit 1; }
  umask 077
  printf 'PGURL=%s\n' "$PGURL_NUEVA" > "$CONFIG"
  chmod 600 "$CONFIG"; chown root:root "$CONFIG"
  unset PGURL_NUEVA
  echo "   guardada en $CONFIG (sólo root puede leerla)"
fi

echo "── 4 · La tarea nocturna ──────────────────────────────────────"
# 05:10 UTC = 01:10 en Caracas, después del mantenimiento.
cat > /etc/cron.d/credisan-respaldo <<CRON
# CrediSan Control · respaldo diario de la base. Lo ejecuta ESTE
# servidor: no depende de GitHub ni de ningún tercero.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
10 5 * * * root $GUION >> /var/log/credisan-respaldo.log 2>&1
CRON
chmod 644 /etc/cron.d/credisan-respaldo
echo "   01:10 (hora de Caracas), todos los días"

cat > /etc/logrotate.d/credisan-respaldo <<'ROT'
/var/log/credisan-respaldo.log {
  weekly
  rotate 8
  compress
  missingok
  notifempty
  create 640 root root
}
ROT

echo ""
echo "── 5 · Probarlo AHORA ─────────────────────────────────────────"
echo "   Un respaldo que no se ha ejecutado es una intención, no una copia."
echo ""
if "$GUION"; then
  echo ""
  echo "   ✔ LISTO. Copias en $RAIZ/respaldos/"
  echo "     Registro: /var/log/credisan-respaldo.log"
else
  echo ""
  echo "   ✖ La prueba falló. Mire el motivo arriba."
  echo "     Lo más habitual: la cadena no es la del Session pooler,"
  echo "     o la contraseña ya no es la buena."
  exit 1
fi
