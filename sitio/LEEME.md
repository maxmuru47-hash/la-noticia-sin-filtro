# El sitio de La Noticia SIN FILTRO

Páginas estáticas. Sin PHP, sin base de datos, sin panel. Nada que se
pueda caer y nada que mantener.

## Publicar una pieza

1. Crear `sitio/piezas/<direccion-de-la-pieza>.txt`:

```
TITULAR: ...
ENTRADILLA: ...
SECCION: Dinero real
FECHA: 2026-09-23
---
Cuerpo. Párrafos separados por línea en blanco.

Una línea suelta, corta y sin punto final se convierte en intertítulo

Y así.
---
FUENTES
https://...  |  Medio · titular  |  23 de septiembre de 2026
---
NOTA: nota de método, opcional.
```

2. `python3 sitio/generar.py`
3. Commit y push.

El VPS recoge el cambio solo en los siguientes minutos. **Nadie sube
archivos a mano.**

## Las cinco secciones

Venezuela · Dinero real · Emprendimiento · Sociedad y familia · IA y tecnología

## Por qué no hay llamadas a terceros

Las tipografías se sirven desde `sitio/tipos/`. No se carga Google Fonts,
ni analítica, ni CDN: ningún lector hace una petición a un servidor que
no sea el nuestro. Si alguien añadiera algo externo por descuido, la
cabecera `Content-Security-Policy` de `lanoticia.caddy` lo bloquea. Es
deliberado.
