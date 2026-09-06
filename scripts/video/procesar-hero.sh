#!/usr/bin/env bash
#
# HERO CINEMATOGRÁFICO CONTROLADO POR DESPLAZAMIENTO
#
# Convierte el video aprobado en el archivo que la portada puede recorrer
# hacia adelante y hacia atrás con el desplazamiento, y genera su póster
# y su fotograma final.
#
#   ./scripts/video/procesar-hero.sh storage/originals/hero/aprobado.mp4
#
# Reglas que este script aplica, y por qué:
#
#   -g 8 -keyint_min 8   Un fotograma clave cada 8 cuadros. Es LO MÁS
#                        IMPORTANTE del encode: el navegador solo puede
#                        saltar a un fotograma clave, así que con el
#                        intervalo por defecto (250) el video se movería
#                        a tirones. Encarece el archivo, y vale la pena.
#   -pix_fmt yuv420p     Sin esto, Safari no reproduce nada.
#   -movflags +faststart Mueve el índice al principio: el video empieza
#                        a poder usarse antes de terminar de descargarse.
#   -an                  Sin audio. El hero nunca suena.
#
set -euo pipefail

ORIGEN="${1:-}"
DESTINO="public/assets/video/hero-scrub.mp4"
POSTER="public/assets/images/hero-poster.jpg"
FINAL="public/assets/images/hero-final.jpg"
CRF="${CRF:-20}"

if [[ -z "$ORIGEN" || ! -f "$ORIGEN" ]]; then
  echo "Uso: $0 ruta/al/video-aprobado.mp4"
  echo "El original debe vivir en storage/originals/, fuera de la carpeta pública."
  exit 1
fi

command -v ffmpeg  >/dev/null || { echo "Falta ffmpeg.";  exit 1; }
command -v ffprobe >/dev/null || { echo "Falta ffprobe."; exit 1; }

mkdir -p "$(dirname "$DESTINO")" "$(dirname "$POSTER")"

echo "▸ Codificando el hero para barrido..."
ffmpeg -hide_banner -loglevel error -y -i "$ORIGEN" \
  -c:v libx264 -crf "$CRF" -preset slow \
  -g 8 -keyint_min 8 -sc_threshold 0 \
  -pix_fmt yuv420p -movflags +faststart -an \
  -vf "scale='min(1600,iw)':-2" \
  "$DESTINO"

echo "▸ Extrayendo el póster (primer fotograma)..."
ffmpeg -hide_banner -loglevel error -y -i "$DESTINO" -frames:v 1 -q:v 3 "$POSTER"

DURACION=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$DESTINO")
ULTIMO=$(awk "BEGIN {printf \"%.3f\", $DURACION - 0.06}")

echo "▸ Extrayendo el fotograma final (la llegada)..."
ffmpeg -hide_banner -loglevel error -y -ss "$ULTIMO" -i "$DESTINO" -frames:v 1 -q:v 3 "$FINAL"

# --- Verificación. Nunca se declara terminado sin comprobar. ----------

BYTES=$(stat -c%s "$DESTINO" 2>/dev/null || stat -f%z "$DESTINO")
MB=$(awk "BEGIN {printf \"%.2f\", $BYTES / 1048576}")
CLAVES=$(ffprobe -v error -select_streams v:0 -show_entries frame=key_frame -of csv=p=0 "$DESTINO" | grep -c '^1$' || true)
CUADROS=$(ffprobe -v error -select_streams v:0 -count_frames -show_entries stream=nb_read_frames -of csv=p=0 "$DESTINO")

echo
echo "─────────────────────────────────────────────"
echo " Archivo:            $DESTINO"
echo " Peso:               ${MB} MB"
echo " Duración:           ${DURACION}s"
echo " Cuadros:            ${CUADROS}"
echo " Fotogramas clave:   ${CLAVES}"
echo " Póster:             $POSTER"
echo " Fotograma final:    $FINAL"
echo "─────────────────────────────────────────────"

if (( $(echo "$MB > 8" | bc -l) )); then
  echo
  echo "AVISO: el hero pesa más de 8 MB."
  echo "El anillo de carga de hero.js mostrará el progreso real, así que la"
  echo "primera impresión no se congela. Aun así, conviene revisar duración,"
  echo "resolución y CRF antes de publicar: prueba con CRF=23 $0 $ORIGEN"
fi

RATIO=$(awk "BEGIN {printf \"%.1f\", $CUADROS / $CLAVES}")
echo
echo "Un fotograma clave cada ${RATIO} cuadros."
if (( $(echo "$RATIO > 12" | bc -l) )); then
  echo "AVISO: demasiado espaciados. El barrido se verá a tirones."
fi
