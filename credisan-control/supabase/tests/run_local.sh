#!/usr/bin/env bash
# Reconstruye una base local desde cero y ejecuta la batería de pruebas.
# Uso: PGPORT=54329 ./supabase/tests/run_local.sh
set -euo pipefail
PGHOST=${PGHOST:-/tmp}; PGPORT=${PGPORT:-54329}; PGUSER=${PGUSER:-postgres}; DB=${DB:-credisan}
psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -tAc "drop database if exists $DB" >/dev/null
psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -tAc "create database $DB"        >/dev/null
q(){ psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d "$DB" -v ON_ERROR_STOP=1 -q -f "$1"; }
q supabase/tests/00_local_stub.sql
for f in supabase/migrations/*.sql; do q "$f"; done
q supabase/seed/seed.sql
q supabase/tests/01_suite.sql
