#!/usr/bin/env bash
#
# VIDEO EDITORIAL DENTRO DE UNA NOTICIA
#
# A diferencia del hero, este SÍ conserva el audio y no necesita
# fotogramas clave tan juntos: nadie lo va a recorrer con el ratón.
#
#   ./scripts/video/procesar-editorial.sh storage/originals/entrevista.mov resumen-tarifa
#
# Genera:
#   public/uploads/AAAA/MM/<nombre>.mp4        el video
#   public/uploads/AAAA/MM/<nombre>-poster.jpg el póster
#   public/uploads/AAAA/MM/<nombre>-mini.jpg   la miniatura
#
# Regla: NUNCA se reprocesa un archivo ya comprimido. Se parte siempre
# del original, que vive en storage/originals/ y no se toca.
#
set -euo pipefail

ORIGEN="${1:-}"
NOMBRE="${2:-}"
CRF="${CRF:-22}"
ANCHO="${ANCHO:-1280}"

if [[ -z "$ORIGEN" || -z "$NOMBRE" || ! -f "$ORIGEN" ]]; then
  echo "Uso: $0 ruta/al/original.mov nombre-de-salida"
  exit 1
fi

CARPETA="public/uploads/$(date -u +%Y/%m)"
mkdir -p "$CARPETA"

DESTINO="$CARPETA/$NOMBRE.mp4"
POSTER="$CARPETA/$NOMBRE-poster.jpg"
MINI="$CARPETA/$NOMBRE-mini.jpg"

echo "▸ Codificando..."
ffmpeg -hide_banner -loglevel error -y -i "$ORIGEN" \
  -c:v libx264 -crf "$CRF" -preset slow -profile:v high -level 4.0 \
  -pix_fmt yuv420p -movflags +faststart \
  -c:a aac -b:a 128k -ac 2 \
  -vf "scale='min($ANCHO,iw)':-2" \
  "$DESTINO"

echo "▸ Póster y miniatura..."
# Se toma un fotograma al 10% de la duración: el primero suele estar en negro.
DURACION=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$DESTINO")
MOMENTO=$(awk "BEGIN {printf \"%.2f\", $DURACION * 0.1}")

ffmpeg -hide_banner -loglevel error -y -ss "$MOMENTO" -i "$DESTINO" -frames:v 1 -q:v 3 "$POSTER"
ffmpeg -hide_banner -loglevel error -y -i "$POSTER" -vf "scale=640:-2" -q:v 4 "$MINI"

SEGUNDOS=$(printf "%.0f" "$DURACION")
BYTES=$(stat -c%s "$DESTINO" 2>/dev/null || stat -f%z "$DESTINO")

echo
echo "─────────────────────────────────────────────"
echo " Video:      /uploads/$(date -u +%Y/%m)/$NOMBRE.mp4"
echo " Póster:     /uploads/$(date -u +%Y/%m)/$NOMBRE-poster.jpg"
echo " Miniatura:  /uploads/$(date -u +%Y/%m)/$NOMBRE-mini.jpg"
echo " Duración:   ${SEGUNDOS} segundos"
echo " Peso:       $(awk "BEGIN {printf \"%.2f\", $BYTES / 1048576}") MB"
echo "─────────────────────────────────────────────"
echo
echo "Siguiente paso en el panel (/panel/videos/nuevo):"
echo "  1. Pega esas rutas."
echo "  2. Escribe la duración: ${SEGUNDOS}"
echo "  3. Sube los subtítulos .vtt."
echo "  4. Pega la transcripción: sin ella, el buscador no encuentra este video."
echo "  5. Escribe la alternativa textual. Es obligatoria en la práctica."
