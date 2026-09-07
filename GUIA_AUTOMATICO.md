# Que funcione solo

Tres cosas que, una vez puestas, no vuelves a tocar.

---

## 1 · Que la noticia aparezca sola en tu página principal

Hoy las tres noticias de tu «Sala de redacción» están escritas a mano dentro
de `index.html`. Eso obliga a copiar cada pieza dos veces, y lo copiado a mano
envejece: si corriges algo en la plataforma, allí sigue el error.

**Qué hacer, una sola vez:**

1. Abre `index.html` de `sinfiltroconmax.com` en el gestor de archivos.
2. Busca al final la línea que dice `</body>`.
3. Pega justo **antes** de esa línea todo el contenido del archivo
   `docs/enganche-sala-de-redaccion.html`.
4. Guarda.

A partir de ahí, cada vez que publiques una pieza aparece en las dos webs.
No hay que tocar nada más nunca.

**Si algo falla** —no hay internet, la plataforma está caída, todavía no has
publicado nada— la sección se queda con las noticias que ya tienes escritas.
Está hecho así a propósito: tu página nunca se queda con un hueco.

---

## 2 · Que una pieza programada se publique sola

En el panel puedes dejar una pieza programada para una fecha y una hora. Para
que se publique sin que tú estés delante, hace falta decirle al servidor que
mire cada cierto tiempo si hay algo pendiente.

**En Hostinger:** panel → *Avanzado* → *Trabajos cron* → *Crear nuevo*.

- **Frecuencia:** cada 5 minutos
- **Comando:**

```
/usr/bin/php /home/u884991609/domains/lanoticia.sinfiltroconmax.com/lanoticia-privado/scripts/cron.php
```

**Antes de guardarlo, comprueba la ruta.** En el gestor de archivos, entra en
`lanoticia-privado` y mira la dirección del navegador: te dice dónde está de
verdad. Si tu ruta es distinta, cambia esa parte del comando.

Esa tarea, además de publicar lo programado, marca los Lives que empiezan y
los que terminan, y limpia registros viejos. No se puede lanzar desde
internet: solo funciona desde dentro del servidor.

**Para comprobar que quedó bien:** programa una pieza para dentro de diez
minutos y vuelve a mirar. Si se publicó sola, está funcionando.

---

## 3 · Que publicar te cueste dos minutos

Ya está puesto. En el panel, **Publicar rápido**: titular, entradilla, cuerpo
y sección. Dos botones: publicar ahora o guardar como borrador.

Y en la lista de Noticias tienes **Publicar** y **Archivar** directos, sin
abrir la pieza.

---

## El ritmo, que es lo único que no puedo automatizar

La plataforma aguanta el ritmo que le pongas. Lo que no puede es inventarse el
contenido: eso lo escribes tú.

Una recomendación, por lo que se ve en medios pequeños que sí duran: **es
mejor una pieza buena a la semana, sostenida durante un año, que cinco piezas
una semana y silencio el mes siguiente.** La portada está montada suponiendo
ritmo alto; si vas a publicar poco, dímelo y la ajusto para que se vea llena
con menos.
