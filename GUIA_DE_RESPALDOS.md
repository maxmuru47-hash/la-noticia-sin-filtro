# Guía de respaldos

Un respaldo que nunca se restauró no es un respaldo: es una esperanza.

---

## 1. Qué se respalda

| Qué | Dónde vive | Se pierde si... |
|---|---|---|
| **Base de datos** | MySQL/MariaDB | ...sin ella no queda nada: ni noticias, ni usuarios, ni historial |
| **Medios publicados** | `public/uploads/` | ...las imágenes y videos de las piezas desaparecen |
| **Originales** | `storage/originals/` | ...no podrás volver a procesar un video sin recomprimirlo |
| **Configuración** | `.env` | ...tendrás que reconfigurar. **Guárdalo aparte, cifrado** |

`.env` **no** entra en el respaldo automático, a propósito: contiene la
contraseña de la base de datos. Guárdalo en un gestor de contraseñas, no
junto a los volcados.

---

## 2. Automático

Una línea en el cron del alojamiento:

```
15 3 * * *  /bin/bash /ruta/al/proyecto/scripts/respaldo.sh >> /ruta/al/proyecto/storage/logs/respaldo.log 2>&1
```

Cada noche a las 3:15 UTC el script:

1. Vuelca la base de datos comprimida, con `--single-transaction` para no
   bloquear el sitio mientras lo hace.
2. **Verifica el volcado.** Comprueba que el gzip está sano y que contiene
   al menos 20 tablas. Si algo falla, borra el archivo y devuelve error, en
   lugar de dejar un respaldo roto que parezca bueno.
3. Empaqueta `public/uploads/` y `storage/originals/`.
4. Aplica retención.

### Retención

- Los **últimos 14 volcados diarios**.
- El volcado **del día 1 de cada mes se conserva para siempre**.
- Los últimos 7 paquetes de medios.

El archivo editorial es permanente, así que sus respaldos también tienen que
poder alcanzar años atrás. Por eso el día 1 nunca se borra.

Se puede ajustar sin tocar el script:

```bash
RETENCION_DIARIA=30 RETENCION_MEDIOS=14 bash scripts/respaldo.sh
```

---

## 3. La parte que casi nadie hace, y es la que importa

**Copia los respaldos fuera del servidor.** Un respaldo que vive en la misma
máquina no te protege de perder la máquina, que es exactamente el caso para
el que existe.

Desde tu computadora, una vez por semana:

```bash
rsync -avz --progress \
  usuario@servidor:/ruta/al/proyecto/storage/backups/ \
  ~/Respaldos/lanoticia/
```

O configura un destino externo (Backblaze B2, Google Drive, un disco en
casa). Lo importante es que exista una copia en un sitio que no sea el
servidor.

---

## 4. Restaurar

```bash
./scripts/restaurar.sh storage/backups/bd_lanoticia_2026-09-05_0315.sql.gz
```

Sin argumentos, lista los respaldos disponibles.

El script, en este orden:

1. Verifica que el archivo no esté corrupto.
2. Te pide escribir el nombre de la base de datos para confirmar.
3. **Guarda un volcado del estado actual antes de tocar nada.** Si la
   restauración sale mal, ese archivo es el camino de vuelta.
4. Restaura.
5. Reconstruye el índice del buscador.

### Después de restaurar, comprueba en este orden

```
[ ] La portada carga
[ ] Una noticia carga por su URL permanente
[ ] El buscador encuentra una palabra que sabes que existe
[ ] El panel pide contraseña y entras
[ ] Las imágenes de las piezas se ven
```

Si el buscador no encuentra nada, ejecuta `php scripts/reindexar.php`.

---

## 5. El simulacro

**Al menos una vez cada tres meses**, restaura de verdad. En una base de
pruebas, no en producción.

```bash
# 1. Base de pruebas
mysql -u root -p -e "CREATE DATABASE lanoticia_simulacro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Restaurar el último respaldo ahí
zcat storage/backups/bd_lanoticia_MAS_RECIENTE.sql.gz | mysql -u root -p lanoticia_simulacro

# 3. Comprobar que llegó todo
mysql -u root -p lanoticia_simulacro -e "
  SELECT COUNT(*) AS piezas FROM articles;
  SELECT COUNT(*) AS publicadas FROM articles WHERE status IN ('publicada','actualizada');
  SELECT COUNT(*) AS usuarios FROM users;
  SELECT COUNT(*) AS correcciones FROM article_corrections;
  SELECT title FROM articles ORDER BY published_at DESC LIMIT 3;"

# 4. Comprobar que los acentos sobrevivieron
mysql --default-character-set=utf8mb4 -u root -p lanoticia_simulacro \
  -e "SELECT title FROM articles WHERE title LIKE '%ó%' LIMIT 3;"

# 5. Borrar la base de pruebas
mysql -u root -p -e "DROP DATABASE lanoticia_simulacro;"
```

Si los acentos salen como `Ã³` o como `?`, el volcado no se hizo con
`--default-character-set=utf8mb4`. Arréglalo antes de necesitarlo de verdad.

Anota la fecha del último simulacro. Si no la recuerdas, ya pasó demasiado.

---

## 6. Antes de cualquier cambio grande

Antes de actualizar PHP, migrar de servidor o cambiar el esquema:

```bash
bash scripts/respaldo.sh
```

Y **descarga ese respaldo a tu máquina** antes de empezar. No después.

---

## 7. Si algo se rompió

| Problema | Qué hacer |
|---|---|
| Se borró una noticia por error | Restaura en una base de pruebas, copia esa fila, insértala en producción. No restaures todo |
| La base no arranca | Restaura el último volcado bueno |
| El servidor entero se perdió | Instala de cero según `GUIA_DE_ALOJAMIENTO.md`, restaura la base y descomprime los medios |
| El buscador no encuentra nada | `php scripts/reindexar.php` |
| Las imágenes no se ven | Descomprime `medios_*.tar.gz` en la raíz del proyecto |

---

## 8. Cada cuánto

| Cuándo | Qué |
|---|---|
| Diario, automático | Volcado de base y medios |
| Semanal | Copiar los respaldos fuera del servidor |
| Mensual | Revisar que el cron sigue corriendo (`storage/logs/respaldo.log`) |
| Trimestral | **Simulacro completo de restauración** |
| Antes de cada cambio grande | Respaldo manual y descarga |
