# El respaldo vive en su servidor, y no depende de nadie

## Por qué cambió

El respaldo lo disparaba GitHub. Funcionaba, pero ataba la copia de seguridad de
CrediSan a un tercero.

No es teoría. **Entre el 18 y el 21 de septiembre de 2026 falló cuatro noches
seguidas** porque la contraseña guardada en GitHub dejó de servir. Cuatro noches
sin copia, en silencio, y se supo por casualidad.

Ahora lo ejecuta el servidor de CrediSan, con su propio cron y sus propias
credenciales.

## Instalarlo (una vez)

En el VPS, como root:

```bash
cd /opt/proyectos/credisan-control/vps
sudo bash instalar-respaldo.sh
```

Pide la cadena del **Session pooler** de Supabase, la guarda en
`/etc/credisan/respaldo.env` con permisos de sólo root, deja la tarea a la 01:10
de Caracas — y **saca un respaldo en ese momento**, porque un respaldo que no se
ha ejecutado es una intención, no una copia.

No toca nada más del servidor: ni Caddy, ni las otras webs, ni la carpeta
publicada.

## Qué hace cada noche

1. Saca la copia de los esquemas `public` y `app`.
2. La escribe como `.parcial`. **Sólo toma nombre de respaldo si todo salió
   bien**: un archivo con nombre bueno y cero bytes dentro es peor que no tener
   ninguno, porque da tranquilidad justo hasta el día que hace falta.
3. Comprueba que esté íntegra, que pese algo real y que lleve dentro **cinco
   marcas**: las tres tablas que guardan a la gente, sus horas y las sucursales;
   la extensión sin la cual se pierden las restricciones de horarios; y esas
   restricciones.
4. Pide el archivo **por internet** para confirmar que no se puede descargar.
5. Conserva los últimos 14 días.
6. Deja constancia en `estado-respaldo.json`.

## Lo que ya no puede pasar en silencio

El panel lee ese archivo de estado y **avisa en pantalla** si el respaldo falló o
si lleva dos días o más sin hacerse. Si todo va bien no dice nada: un aviso que
sale siempre deja de leerse a la semana.

Lo ven dirección y administración. El jefe operativo no: no es asunto del
mostrador.

## El fallo que encontró restaurar de verdad

Esto es lo que ninguna comprobación de integridad habría visto.

`pg_dump --schema=public --schema=app` copia nuestras tablas pero **no las
extensiones**: en Supabase viven en su propio esquema. Al restaurar, PostgreSQL
se encontraba con esto —

```
ERROR: data type uuid has no default operator class for access method "gist"
```

— y se saltaba, sin más, **las dos restricciones que impiden que un trabajador
tenga dos horarios vigentes el mismo día**.

La base restaurada aceptaba los dos horarios solapados que la original rechaza.
El respaldo se restauraba roto por dentro, y no se habría notado hasta el peor
día posible.

Ahora el volcado lleva delante las extensiones que necesita, y **se basta solo**.

Lo importante de cómo se encontró: contando filas, tablas, funciones y permisos
**todo cuadraba**. El respaldo roto pasaba cada una de esas comprobaciones. Sólo
apareció al restaurarlo y pedirle a la base que se comportara como la original.

## Comprobarlo cuando quiera

```bash
PGPORT=54329 ./vps/probar-restauracion.sh
```

Monta una base como la de producción, le saca un respaldo **con el mismo guion
que corre en el VPS**, lo devuelve a una base vacía y exige que lo que vuelve sea
equivalente: mismas filas, mismas funciones, mismas reglas de seguridad, mismos
permisos por columna —los que esconden el salario— y, sobre todo, **el mismo
comportamiento**.

## Si algún día hace falta volver de un respaldo

1. Elija la copia: `ls -lt /opt/proyectos/credisan-control/respaldos/`
2. Compruébela antes de confiar en ella: `gzip -t <archivo>`
3. En un proyecto de Supabase nuevo (o en el mismo, vacío), SQL Editor:
   ```bash
   zcat credisan-AAAAMMDD-HHMM.sql.gz | psql "<cadena del Session pooler>"
   ```
4. Dos errores son **esperados** y no pierden nada: `schema "public" already
   exists` y `cannot drop schema public`. Ese esquema ya existe en cualquier base
   real. Cualquier **otro** error sí hay que mirarlo.
5. Los usuarios del panel no van dentro (viven en `auth` de Supabase): se vuelven
   a invitar desde **Más → Accesos**.
6. Las fotografías de las marcaciones tampoco: viven en el almacén de Supabase y
   se borran solas a los 180 días. Las horas, la clasificación y la auditoría de
   cada marcación sí están en el respaldo.

## Dónde está cada cosa

| | |
|---|---|
| El guion | `/usr/local/sbin/credisan-respaldo` |
| La contraseña | `/etc/credisan/respaldo.env` · sólo root |
| Las copias | `/opt/proyectos/credisan-control/respaldos/` · 600, sólo root |
| El registro | `/var/log/credisan-respaldo.log` |
| La tarea | `/etc/cron.d/credisan-respaldo` · 01:10 Caracas |

Las copias quedan **fuera** de lo que Caddy publica, y el guion lo comprueba de
dos maneras: por la ruta antes de escribir, y pidiendo el archivo por internet
después.

## Qué sigue dependiendo de Supabase, y qué no

Los datos **viven** en Supabase: eso no lo cambia un respaldo. Lo que cambia es
que ahora hay una copia completa y **probada** en infraestructura suya, y un
camino escrito para volver de ella.

GitHub ya no tiene horario para esto. Se le dejó el disparo a mano por si alguna
vez conviene sacar una copia desde fuera del servidor, pero el dueño del respaldo
es el VPS. **Un respaldo con dos dueños es un respaldo del que cada uno supone
que se encarga el otro.**
