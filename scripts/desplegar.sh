#!/usr/bin/env bash
#
# DESPLIEGUE AUTOMATICO DESDE GITHUB
#
# Se monta UNA vez. Despues, cada cambio que se publique en la rama
# llega solo a la web y no hay que subir nada a mano nunca mas.
#
# Lo que NO toca, pase lo que pase:
#   - el archivo .env (las claves)
#   - storage/  (subidas, respaldos, registros)
# Eso no vive en GitHub y no puede sobrescribirse por accidente.
#
# Uso:
#   1. Rellena las tres rutas de abajo.
#   2. chmod +x desplegar.sh
#   3. Pruebalo a mano una vez:  ./desplegar.sh
#   4. Si fue bien, ponlo en el cron cada 5 minutos.
#
set -euo pipefail

# ---------------------------------------------------------------------
# LAS TRES COSAS QUE HAY QUE RELLENAR
# ---------------------------------------------------------------------

# Donde esta el clon del repositorio en el servidor.
# Si todavia no existe, crealo una vez con:
#   git clone https://github.com/maxmuru47-hash/la-noticia-sin-filtro.git /ruta/al/clon
CLON="/home/TU-USUARIO/despliegue/la-noticia-sin-filtro"

# La carpeta privada: donde viven app, config, scripts y el .env.
PRIVADA="/home/TU-USUARIO/domains/lanoticia.sinfiltroconmax.com/lanoticia-privado"

# La carpeta que ve internet: donde vive index.php.
PUBLICA="/home/TU-USUARIO/domains/lanoticia.sinfiltroconmax.com/public_html"

# La rama de la que se despliega.
RAMA="main"

# ---------------------------------------------------------------------
# A PARTIR DE AQUI NO HAY QUE TOCAR NADA
# ---------------------------------------------------------------------

registro() { echo "$(date -u '+%Y-%m-%d %H:%M:%S') UTC · $*"; }

for carpeta in "$CLON" "$PRIVADA" "$PUBLICA"; do
    if [ ! -d "$carpeta" ]; then
        registro "ERROR: no existe la carpeta $carpeta. Revisa las rutas de arriba."
        exit 1
    fi
done

cd "$CLON"

# Traer los cambios sin reescribir nada. Si alguien toco el clon a mano,
# esto falla en vez de machacar su trabajo en silencio.
git fetch --quiet origin "$RAMA"

ANTES="$(git rev-parse HEAD)"
DESPUES="$(git rev-parse "origin/$RAMA")"

if [ "$ANTES" = "$DESPUES" ]; then
    exit 0   # No hay nada nuevo. Silencio: el cron corre cada 5 minutos.
fi

registro "Hay cambios: ${ANTES:0:7} -> ${DESPUES:0:7}"

git merge --ff-only --quiet "origin/$RAMA"

# Copia de seguridad antes de escribir nada, con la fecha en el nombre.
RESPALDO="$PRIVADA/storage/respaldo-despliegue/$(date -u '+%Y%m%d-%H%M%S')"
mkdir -p "$RESPALDO"
cp -a "$PRIVADA/app" "$PRIVADA/config" "$RESPALDO/" 2>/dev/null || true
registro "Respaldo guardado en $RESPALDO"

# El codigo, a la carpeta privada. --delete quita lo que ya no existe en
# el repositorio, pero .env y storage estan excluidos y no se rozan.
rsync -a --delete \
      --exclude='.env' \
      --exclude='storage/' \
      "$CLON/app/" "$PRIVADA/app/"

rsync -a --delete "$CLON/config/"   "$PRIVADA/config/"
rsync -a --delete "$CLON/scripts/"  "$PRIVADA/scripts/"
rsync -a          "$CLON/database/" "$PRIVADA/database/"

# Lo que ve internet. Aqui NO se usa --delete: en public_html puede haber
# cosas que no estan en el repositorio (imagenes subidas, por ejemplo) y
# borrarlas seria perderlas.
rsync -a --exclude='rutas.php' "$CLON/public/" "$PUBLICA/"

# Rehacer el indice del buscador: despues de un despliegue puede quedar
# desfasado, y un buscador que no encuentra nada no avisa de que falla.
if [ -f "$PRIVADA/scripts/reindexar.php" ]; then
    php "$PRIVADA/scripts/reindexar.php" >/dev/null 2>&1 || \
        registro "AVISO: no se pudo rehacer el índice del buscador"
fi

registro "Despliegue terminado en ${DESPUES:0:7}"
