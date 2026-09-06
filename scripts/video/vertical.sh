#!/usr/bin/env bash
#
# VERSIÓN VERTICAL 9:16 PARA TIKTOK, REELS Y SHORTS
#
# NO recorta a ciegas. Genera dos opciones y te deja elegir:
#
#   A) Recorte centrado: se pierden los lados. Solo sirve si la acción
#      está en el centro del cuadro.
#   B) Fondo desenfocado: conserva TODO el contenido, con el original
#      completo al centro sobre una versión difuminada de sí mismo.
#
# La opción B es la predeterminada, porque no pierde información. El
# recorte automático de contenido importante está prohibido en este
# proyecto: una cara cortada o un dato fuera de cuadro es un error
# editorial, no un detalle técnico.
#
set -euo pipefail

ORIGEN="${1:-}"
NOMBRE="${2:-}"
MODO="${3:-fondo}"

if [[ -z "$ORIGEN" || -z "$NOMBRE" || ! -f "$ORIGEN" ]]; then
  echo "Uso: $0 original.mp4 nombre-salida [fondo|recorte]"
  exit 1
fi

CARPETA="public/uploads/$(date -u +%Y/%m)"
mkdir -p "$CARPETA"
DESTINO="$CARPETA/$NOMBRE-vertical.mp4"

if [[ "$MODO" == "recorte" ]]; then
  echo "▸ Recorte centrado 9:16. Revisa que no se pierda nada importante."
  FILTRO="crop=ih*9/16:ih,scale=1080:1920"
else
  echo "▸ Vertical con fondo desenfocado. No se pierde nada del cuadro."
  FILTRO="[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,gblur=sigma=28,eq=brightness=-0.14[fondo];[0:v]scale=1080:-2[frente];[fondo][frente]overlay=(W-w)/2:(H-h)/2"
fi

if [[ "$MODO" == "recorte" ]]; then
  ffmpeg -hide_banner -loglevel error -y -i "$ORIGEN" \
    -vf "$FILTRO" -c:v libx264 -crf 21 -preset slow -pix_fmt yuv420p \
    -movflags +faststart -c:a aac -b:a 128k "$DESTINO"
else
  ffmpeg -hide_banner -loglevel error -y -i "$ORIGEN" \
    -filter_complex "$FILTRO" -c:v libx264 -crf 21 -preset slow -pix_fmt yuv420p \
    -movflags +faststart -c:a aac -b:a 128k "$DESTINO"
fi

ffmpeg -hide_banner -loglevel error -y -i "$DESTINO" -ss 1 -frames:v 1 -q:v 3 "$CARPETA/$NOMBRE-vertical-poster.jpg"

echo
echo "Listo: /uploads/$(date -u +%Y/%m)/$NOMBRE-vertical.mp4"
echo
echo "Regístralo en el panel como un video APARTE, con orientación vertical,"
echo "y relaciónalo con la misma noticia. Cada versión es un recurso propio:"
echo "así la horizontal y la vertical conviven sin pisarse."
