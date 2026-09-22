#!/usr/bin/env bash
# =====================================================================
# CrediSan Control · respaldo diario, EJECUTADO POR EL PROPIO SERVIDOR
# =====================================================================
# POR QUÉ EXISTE ESTE ARCHIVO
# ---------------------------
# Hasta ahora el respaldo lo disparaba GitHub. Funcionaba, pero ataba la
# copia de seguridad de CrediSan a un tercero: si GitHub no ejecuta la
# tarea, si un secreto caduca o si la cuenta se queda sin minutos, no hay
# respaldo — y nadie se entera.
#
# No es teoría. Entre el 18 y el 21 de septiembre de 2026 el respaldo
# falló CUATRO noches seguidas porque la contraseña guardada en GitHub
# dejó de servir. Cuatro noches sin copia, en silencio.
#
# Esto lo ejecuta el servidor de CrediSan, con su propio cron y sus
# propias credenciales. No depende de nadie más.
#
# LO QUE GUARDA, Y POR QUÉ HAY QUE TRATARLO COMO LA NÓMINA
# --------------------------------------------------------
# Un respaldo completo lleva NOMBRES, CÉDULAS, SALARIOS y el historial de
# entradas y salidas de cada trabajador. Es el archivo más sensible del
# proyecto. Por eso, igual que en el flujo que sustituye:
#
#   · Va FUERA de lo que Caddy publica, comprobado de dos maneras: por la
#     ruta antes de escribir, y pidiéndolo por internet después.
#   · Permisos 600 y dueño root.
#   · Se escribe como «.parcial» y sólo toma su nombre definitivo si todo
#     salió bien. Un archivo con nombre de respaldo y cero bytes dentro
#     es PEOR que no tener ninguno: da tranquilidad hasta el día que hace
#     falta.
#   · Se verifica que esté íntegro y que lleve dentro las tablas que
#     importan, antes de darlo por bueno.
#
# Y una cosa que el flujo de GitHub no hacía: DEJA CONSTANCIA. Escribe un
# archivo de estado que el panel lee, para que un respaldo que lleva días
# fallando se vea en pantalla en vez de descubrirse por casualidad.
#
# CÓMO SE INSTALA
# ---------------
#   Ver credisan-control/docs/RESPALDO_EN_EL_VPS.md
# =====================================================================
set -uo pipefail

# ── Configuración ────────────────────────────────────────────────────
# Todo se puede sobrescribir por entorno, que es lo que permite probar
# este guion contra una base de mentira sin tocar nada de producción.
CONFIG="${CREDISAN_CONFIG:-/etc/credisan/respaldo.env}"
[ -r "$CONFIG" ] && . "$CONFIG"

RAIZ="${CREDISAN_RAIZ:-/opt/proyectos/credisan-control}"
PUBLICADO="${CREDISAN_PUBLICADO:-$RAIZ/public}"
RESPALDOS="${CREDISAN_RESPALDOS:-$RAIZ/respaldos}"
CONSERVAR="${CREDISAN_CONSERVAR:-14}"
SITIO="${CREDISAN_SITIO:-https://control.sinfiltroconmax.com}"
ESTADO="${CREDISAN_ESTADO:-$PUBLICADO/estado-respaldo.json}"
PGDUMP="${CREDISAN_PGDUMP:-}"
MINIMO="${CREDISAN_MINIMO:-10240}"      # bytes; por debajo no es un respaldo

fecha() { date -u +'%Y-%m-%dT%H:%M:%SZ'; }
decir() { echo "[$(fecha)] $*"; }

# El estado se escribe SIEMPRE, salga bien o mal. Es la única forma de
# que un fallo repetido se note sin que nadie tenga que ir a buscarlo.
#
# Lleva lo mínimo a propósito: este archivo lo sirve la web. No van
# rutas, ni nombres de archivo, ni nada que ayude a encontrar la copia.
escribir_estado() {
  local ok="$1" motivo="${2:-}" copias="${3:-0}"
  local tmp="$ESTADO.tmp"
  [ -d "$(dirname "$ESTADO")" ] || return 0
  printf '{"ultimo":"%s","ok":%s,"motivo":"%s","copias":%s}\n' \
    "$(fecha)" "$ok" "$motivo" "$copias" > "$tmp" 2>/dev/null || return 0
  chmod 644 "$tmp" 2>/dev/null
  mv -f "$tmp" "$ESTADO" 2>/dev/null || true
}

abortar() {
  decir "FALLÓ: $1"
  escribir_estado false "$1"
  exit 1
}

# ── 1 · ¿Está todo lo que hace falta? ────────────────────────────────
[ -n "${PGURL:-}" ] || abortar "falta_PGURL"

# pg_dump tiene que ser igual o más nuevo que el servidor o se niega a
# trabajar, y hace bien: una herramienta vieja no sabe copiar una base
# nueva y lo que saldría sería una copia a medias.
if [ -z "$PGDUMP" ]; then
  for v in 18 17 16; do
    [ -x "/usr/lib/postgresql/$v/bin/pg_dump" ] && PGDUMP="/usr/lib/postgresql/$v/bin/pg_dump" && break
  done
  [ -n "$PGDUMP" ] || PGDUMP="$(command -v pg_dump || true)"
fi
[ -n "$PGDUMP" ] && [ -x "$PGDUMP" ] || abortar "sin_pg_dump"
decir "usando $($PGDUMP --version)"

# ── 2 · Que la copia no acabe publicada en internet ──────────────────
case "$RESPALDOS/" in
  "$PUBLICADO"/*) abortar "respaldos_dentro_de_lo_publicado" ;;
esac

mkdir -p "$RESPALDOS" || abortar "no_se_pudo_crear_la_carpeta"
chmod 700 "$RESPALDOS"

# ── 3 · La copia ─────────────────────────────────────────────────────
archivo="credisan-$(date -u +%Y%m%d-%H%M).sql.gz"
destino="$RESPALDOS/$archivo"

decir "sacando copia → $archivo"

# LAS EXTENSIONES VAN DELANTE, Y NO ES UN ADORNO.
#
# `pg_dump --schema=public --schema=app` copia nuestras tablas, pero NO
# las extensiones: en Supabase viven en su propio esquema. Restaurando
# sólo lo nuestro, PostgreSQL se encuentra con esto —
#
#   ERROR: data type uuid has no default operator class for access
#          method "gist"
#
# — y se salta, sin más, las DOS restricciones que impiden que un
# trabajador tenga dos horarios vigentes el mismo día.
#
# Comprobado restaurando de verdad: la base devuelta ACEPTABA los dos
# horarios solapados que la original rechaza. El respaldo se restauraba
# roto por dentro, y eso no se habría notado hasta el peor día posible.
#
# Con estas dos líneas delante, el archivo se basta solo.
( umask 077
  { echo '-- CrediSan · lo que el volcado necesita para restaurarse ENTERO.'
    echo 'CREATE EXTENSION IF NOT EXISTS btree_gist;'
    echo 'CREATE EXTENSION IF NOT EXISTS pgcrypto;'
    "$PGDUMP" "$PGURL" \
      --schema=public --schema=app \
      --no-owner --clean --if-exists
  } | gzip -9 > "$destino.parcial" )
# El estado de un grupo { } es el de su ÚLTIMA orden, así que
# PIPESTATUS[0] sigue siendo el de pg_dump.
estado_tuberia=("${PIPESTATUS[@]}")

if [ "${estado_tuberia[0]}" != "0" ]; then
  rm -f "$destino.parcial"
  abortar "pg_dump_fallo"
fi

# ── 4 · ¿Sirve de algo lo que acabamos de guardar? ───────────────────
# Antes de ponerle nombre de respaldo. Un archivo corrupto con nombre
# bueno es una mentira que alguien va a creerse el peor día.
gzip -t "$destino.parcial" 2>/dev/null || { rm -f "$destino.parcial"; abortar "archivo_corrupto"; }

bytes=$(stat -c%s "$destino.parcial")
[ "$bytes" -gt "$MINIMO" ] || { rm -f "$destino.parcial"; abortar "demasiado_pequeno_$bytes"; }

# ¿ESTÁ TODO LO QUE HACE FALTA PARA VOLVER ENTERO?
#
# En UNA sola pasada, y sin `grep -q`. Las dos cosas por el mismo
# motivo, que costó encontrar: `grep -q` corta en cuanto encuentra, y
# entonces `zcat` muere con SIGPIPE; con `pipefail` puesto, la tubería
# entera se da por fallida AUNQUE LO BUSCADO ESTUVIERA. Tal cual, este
# guion rechazaba todos los respaldos buenos y los borraba.
#
# Se buscan cinco marcas:
#   · las tres tablas que guardan a la gente, sus horas y las sucursales
#   · la extensión sin la cual se pierden las restricciones de horarios
#   · esas restricciones
#
# La cuarta y la quinta no sobran. Sin ellas, el archivo restauraba una
# base MÁS PERMISIVA que la original —aceptaba dos horarios vigentes a
# la vez para la misma persona— y eso no se habría notado hasta el peor
# día posible. Un respaldo así no es un respaldo: es una trampa.
marcas=$(zcat "$destino.parcial" | grep -oE \
  'CREATE TABLE public\.(employees|attendance_events|branches)|CREATE EXTENSION IF NOT EXISTS btree_gist|assignment_sin_solape' \
  | sed 's/assignment_sin_solape.*/assignment_sin_solape/' \
  | sort -u | wc -l)

if [ "$marcas" -ne 5 ]; then
  rm -f "$destino.parcial"
  abortar "volcado_incompleto_${marcas}_de_5"
fi

# Ahora sí: nombre definitivo. Sólo llegan aquí las copias comprobadas.
chmod 600 "$destino.parcial"
mv "$destino.parcial" "$destino" || abortar "no_se_pudo_renombrar"
decir "copia verificada · $bytes bytes"

# ── 5 · Y que de verdad no esté en internet ──────────────────────────
# Lo de arriba era la teoría —la ruta—; esto es la comprobación. Si el
# servidor web está mal configurado, aquí se ve.
if command -v curl >/dev/null 2>&1; then
  for ruta in "/respaldos/$archivo" "/$archivo" "/respaldos/"; do
    codigo=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "$SITIO$ruta" || echo 000)
    case "$codigo" in
      200|206) abortar "descargable_desde_internet_en_$ruta" ;;
    esac
  done
  decir "comprobado: no se alcanza desde la web"
else
  decir "aviso: sin curl, no se pudo comprobar por internet"
fi

# ── 6 · Conservar sólo los últimos días ──────────────────────────────
# Se barren los .parcial de intentos fallidos. Nunca se toca un respaldo
# con nombre bueno que no sea de los más viejos.
cd "$RESPALDOS" || abortar "no_se_pudo_entrar_en_la_carpeta"
rm -f ./*.parcial
ls -1t credisan-*.sql.gz 2>/dev/null | tail -n +$((CONSERVAR + 1)) | xargs -r rm -f
copias=$(ls -1 credisan-*.sql.gz 2>/dev/null | wc -l)

escribir_estado true "" "$copias"
decir "LISTO · $copias copias guardadas (se conservan $CONSERVAR)"
