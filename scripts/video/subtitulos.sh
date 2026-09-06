#!/usr/bin/env bash
#
# SUBTÍTULOS Y ALTERNATIVA TEXTUAL
#
# Este script NO genera subtítulos por sí solo: los convierte y los
# valida. La transcripción automática puede hacerse fuera (Whisper, el
# editor de YouTube, o quien transcriba a mano), pero SIEMPRE la revisa
# una persona antes de publicarse. Es la política de uso de IA del medio.
#
#   ./scripts/video/subtitulos.sh entrada.srt salida.vtt
#
set -euo pipefail

ENTRADA="${1:-}"
SALIDA="${2:-}"

if [[ -z "$ENTRADA" || -z "$SALIDA" || ! -f "$ENTRADA" ]]; then
  echo "Uso: $0 entrada.srt salida.vtt"
  echo
  echo "Para obtener el .srt de partida:"
  echo "  · Whisper:  whisper video.mp4 --language Spanish --output_format srt"
  echo "  · YouTube:  Studio → Subtítulos → Descargar"
  echo "  · A mano:   cualquier editor de subtítulos"
  exit 1
fi

ffmpeg -hide_banner -loglevel error -y -i "$ENTRADA" "$SALIDA"

# Validación mínima: un WebVTT debe empezar por WEBVTT o el navegador lo
# ignora en silencio, que es la peor forma de fallar.
if ! head -c 6 "$SALIDA" | grep -q "WEBVTT"; then
  echo "ERROR: el archivo generado no empieza por WEBVTT."
  exit 1
fi

LINEAS=$(grep -c -- '-->' "$SALIDA" || true)
echo "WebVTT válido: $SALIDA ($LINEAS bloques de tiempo)"
echo
echo "Ahora, en el panel:"
echo "  1. Sube el .vtt en la ficha del video."
echo "  2. Pega el texto plano como transcripción."
echo "  3. Marca el estado como «revisada» solo cuando una persona lo haya leído."
