#!/usr/bin/env bash
# =====================================================================
# CrediSan Control · ¿de verdad se puede volver de este respaldo?
# =====================================================================
# Un respaldo que nadie ha restaurado NO ES UN RESPALDO: es un archivo
# del que se supone algo. Esta prueba lo supone de verdad — saca una
# copia, la devuelve a una base vacía y exige que lo que vuelve sea
# EQUIVALENTE a lo que había, no sólo parecido.
#
# Encontró algo que ninguna comprobación de integridad habría visto: el
# volcado restauraba una base MÁS PERMISIVA que la original, porque las
# extensiones vivían en otro esquema y con ellas se perdían, en
# silencio, las restricciones que impiden que un trabajador tenga dos
# horarios vigentes a la vez.
#
# Uso:  PGPORT=54329 ./vps/probar-restauracion.sh
# =====================================================================
set -uo pipefail
cd "$(dirname "$0")/.."

export PGHOST="${PGHOST:-/tmp}" PGPORT="${PGPORT:-54329}" PGUSER="${PGUSER:-postgres}"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fallos=0
ok()  { echo "  ✔ $1${2:+  → $2}"; }
mal() { fallos=$((fallos+1)); echo "  ✖ $1  → $2"; }
es()  { [ "$2" = "$3" ] && ok "$1" "$2" || mal "$1" "$2 ≠ $3"; }

q() { psql -d "$1" -tAc "$2" 2>/dev/null; }

echo ""
echo "1 · Se monta una base como la de producción"
psql -tAc "drop database if exists credisan_origen" >/dev/null
psql -tAc "create database credisan_origen"        >/dev/null
psql -d credisan_origen -q -f supabase/tests/00_local_stub.sql >/dev/null 2>&1
for f in supabase/migrations/*.sql; do
  psql -d credisan_origen -v ON_ERROR_STOP=1 -q -f "$f" >/dev/null 2>&1 \
    || { echo "  ✖ no se pudo aplicar $f"; exit 1; }
done
psql -d credisan_origen -q -f supabase/seed/seed.sql >/dev/null 2>&1
psql -d credisan_origen -q -c "
  insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
  select id, 'REST-1','Prueba','Restauración','V-777','Cajera','2026-01-15' from branches limit 1" >/dev/null 2>&1
ok "base de origen lista" "$(q credisan_origen 'select count(*)||" trabajadores, "||(select count(*) from branches)||" sedes" from employees')"

echo ""
echo "2 · Se saca el respaldo con el mismo guion que corre en el VPS"
mkdir -p "$TMP/public" "$TMP/respaldos"
CREDISAN_CONFIG=/dev/null \
CREDISAN_RAIZ="$TMP" CREDISAN_PUBLICADO="$TMP/public" CREDISAN_RESPALDOS="$TMP/respaldos" \
CREDISAN_PGDUMP="${CREDISAN_PGDUMP:-}" CREDISAN_SITIO="http://127.0.0.1:9" \
PGURL="postgresql://$PGUSER@/credisan_origen?host=$PGHOST&port=$PGPORT" \
  bash vps/respaldo.sh > "$TMP/salida.log" 2>&1
if [ $? -ne 0 ]; then sed 's/^/     /' "$TMP/salida.log"; mal "el respaldo falló" "ver arriba"; exit 1; fi
copia=$(ls "$TMP"/respaldos/credisan-*.sql.gz 2>/dev/null | head -1)
[ -n "$copia" ] && ok "copia sacada" "$(stat -c%s "$copia") bytes" || { mal "no se creó la copia" "—"; exit 1; }
es "y con permisos de sólo root" "$(stat -c%a "$copia")" "600"
es "el estado quedó anotado" "$(grep -o '"ok":true' "$TMP/public/estado-respaldo.json" | head -1)" '"ok":true'

echo ""
echo "3 · Se devuelve a una base vacía"
psql -tAc "drop database if exists credisan_vuelta" >/dev/null
psql -tAc "create database credisan_vuelta"        >/dev/null
psql -d credisan_vuelta -q -f supabase/tests/00_local_stub.sql >/dev/null 2>&1
zcat "$copia" | psql -d credisan_vuelta -q > "$TMP/restaurar.log" 2>&1
# Dos errores son ESPERADOS y no se pueden evitar: el volcado lleva
# «DROP SCHEMA public» y «CREATE SCHEMA public» por el --clean, y en
# cualquier base real —incluida una de Supabase recién hecha— ese
# esquema ya existe y tiene cosas dentro. Los dos fallan sin perder
# nada. Se descartan por su texto exacto, no se ignoran a bulto:
# cualquier OTRO error sí tumba la prueba.
otros=$(grep "^ERROR" "$TMP/restaurar.log" \
        | grep -vE 'schema "public" already exists|cannot drop schema public' | wc -l)
if [ "$otros" != "0" ]; then
  grep "^ERROR" "$TMP/restaurar.log" \
    | grep -vE 'schema "public" already exists|cannot drop schema public' | head -5 | sed 's/^/     /'
fi
es "la restauración no da ningún error que importe" "$otros" "0"

echo ""
echo "4 · Lo que volvió es lo que había"
for t in employees branches system_settings incident_kinds work_schedules; do
  es "  tabla $t" "$(q credisan_vuelta "select count(*) from $t")" "$(q credisan_origen "select count(*) from $t")"
done
# Se cuentan NUESTRAS funciones, descartando las que trae una
# extensión: en una base de rescate vacía, btree_gist y pgcrypto se
# crean en `public` y añaden más de doscientas que en producción viven
# en otro esquema. Contarlas todas comparaba dos cosas distintas.
propias="select count(*) from pg_proc p
           join pg_namespace n on n.oid = p.pronamespace
           left join pg_depend d on d.objid = p.oid and d.deptype = 'e'
          where n.nspname = 'public' and d.objid is null"
es "  funciones del panel" "$(q credisan_vuelta "$propias")" "$(q credisan_origen "$propias")"
es "  reglas de seguridad por fila" \
   "$(q credisan_vuelta "select count(*) from pg_policies where schemaname='public'")" \
   "$(q credisan_origen "select count(*) from pg_policies where schemaname='public'")"
es "  permisos por columna (los que esconden el salario)" \
   "$(q credisan_vuelta "select count(*) from information_schema.column_privileges where table_name='employee_compensation'")" \
   "$(q credisan_origen "select count(*) from information_schema.column_privileges where table_name='employee_compensation'")"

echo ""
echo "5 · Y se comporta igual, que es lo que de verdad importa"
# LA comprobación de esta prueba. Antes, aquí volvía una base que
# aceptaba dos horarios vigentes a la vez para la misma persona.
es "  restricciones anti-solape de horarios" \
   "$(q credisan_vuelta "select count(*) from pg_constraint where conname like 'assignment_sin_solape%'")" "2"

rechazo=$(psql -d credisan_vuelta -tAc "
do \$\$ declare e uuid; w uuid; begin
  select id into e from employees limit 1;
  select id into w from work_schedules limit 1;
  insert into schedule_assignments (schedule_id, employee_id, valid_from) values (w,e,current_date);
  insert into schedule_assignments (schedule_id, employee_id, valid_from) values (w,e,current_date);
  raise notice 'ADMITIDO';
exception when others then raise notice 'RECHAZADO'; end \$\$;" 2>&1 | grep -o 'ADMITIDO\|RECHAZADO' | head -1)
es "  y de hecho rechaza dos horarios solapados" "$rechazo" "RECHAZADO"

psql -tAc "drop database if exists credisan_origen" >/dev/null
psql -tAc "drop database if exists credisan_vuelta" >/dev/null

echo ""
[ "$fallos" = 0 ] \
  && { echo "  ✔  SE PUEDE VOLVER DE ESTE RESPALDO — comprobado restaurándolo"; echo ""; exit 0; } \
  || { echo "  ✖  $fallos comprobaciones fallaron"; echo ""; exit 1; }
