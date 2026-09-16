#!/usr/bin/env bash
# Rehace INSTALAR.sql a partir de las piezas reales del proyecto.
#
#   cabecera + todas las migraciones + semilla + comprobación final
#
# Existe para que el instalador no se quede atrás cuando se añade una
# migración: en vez de copiar y pegar a mano, se ejecuta esto.
#
# Uso, desde credisan-control/:   ./supabase/instalador/construir.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

DESTINO=supabase/instalador/INSTALAR.sql
TMP=$(mktemp)

cat supabase/instalador/partes/cabecera.sql > "$TMP"

for f in supabase/migrations/*.sql; do
  {
    printf '\n\n-- ####################################################################\n'
    printf -- '-- ##  %s\n' "$(basename "$f")"
    printf -- '-- ####################################################################\n\n'
    cat "$f"
  } >> "$TMP"
done

{
  printf '\n-- ####################################################################\n'
  printf -- '-- ##  seed.sql — sedes, terminales, horarios y configuración\n'
  printf -- '-- ####################################################################\n\n'
  cat supabase/seed/seed.sql
  printf '\n'
  cat supabase/instalador/partes/comprobacion.sql
} >> "$TMP"

mv "$TMP" "$DESTINO"
echo "INSTALAR.sql reconstruido: $(wc -l < "$DESTINO") líneas, $(ls supabase/migrations/*.sql | wc -l) migraciones."
