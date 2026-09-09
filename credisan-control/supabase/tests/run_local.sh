#!/usr/bin/env bash
# Reconstruye bases limpias y ejecuta las baterías de validación.
# Cada batería corre en su propia base: no se contaminan entre sí.
# Uso: PGPORT=54329 ./supabase/tests/run_local.sh
set -euo pipefail
PGHOST=${PGHOST:-/tmp}; PGPORT=${PGPORT:-54329}; PGUSER=${PGUSER:-postgres}

correr() {                       # $1 = nombre de base, $2 = batería
  local db="$1" suite="$2"
  psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -tAc "drop database if exists $db" >/dev/null
  psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -tAc "create database $db"        >/dev/null
  local q="psql -h $PGHOST -p $PGPORT -U $PGUSER -d $db -v ON_ERROR_STOP=1 -q"
  $q -f supabase/tests/00_local_stub.sql
  for f in supabase/migrations/*.sql; do $q -f "$f"; done
  $q -f supabase/seed/seed.sql
  $q -f "$suite"
}

correr credisan_f1 supabase/tests/01_suite.sql
correr credisan_f2 supabase/tests/02_fase2.sql
