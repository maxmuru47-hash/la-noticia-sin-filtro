# Origen de los archivos de marca

Todos los archivos de esta carpeta salen del **Manual de identidad CrediSan**
entregado por el cliente. **No hay ningún logo redibujado, reinterpretado ni
generado por IA.**

Procedimiento exacto:

1. Se extrajeron las imágenes incrustadas del PDF del manual (páginas «Logo» e
   «Icono»), que vienen como siluetas monocromas de 1080 px.
2. Esa silueta se usó como **canal alfa**, sin tocar un solo trazo, para
   producir cada versión de color de la paleta oficial.

Es decir: misma geometría, mismas proporciones, sólo cambia el color plano.

| Archivo | Uso |
|---|---|
| `credisan-logo-morado.png` | Marca completa sobre fondo claro |
| `credisan-logo-blanco.png` | Marca completa sobre fondo oscuro o morado |
| `credisan-logo-amarillo.png` | Marca completa sobre morado |
| `credisan-logo-negro.png` | Impresión a una tinta |
| `credisan-isotipo-*.png` | Isotipo suelto (círculo + mano) |
| `../icons/*` | Iconos de la PWA: isotipo amarillo sobre morado |

## Reglas

- El logo **no** se redibuja, ni se rehace en CSS o SVG a mano, ni se rescribe
  con otra tipografía. Si hace falta otra versión, se pide el original.
- Proporciones bloqueadas: escalar siempre de forma proporcional.
- Espacio libre alrededor: como mínimo la altura del isotipo, dividida entre dos.

## Tipografía

El manual especifica **Code Next** (Heavy / Regular), que es una fuente
comercial y en el manual aparece como versión de prueba (*Trial*). El sistema
usa **Poppins** —geométrica, del mismo carácter y con licencia libre (SIL OFL)—
autohospedada en `public/assets/fonts/`. Si CrediSan adquiere la licencia de
Code Next para web, se sustituye cambiando una variable en `src/ui/tokens.css`.

## Paleta

| Color | Hex | Uso |
|---|---|---|
| Morado | `#662D91` | Color principal, encabezados, fondo del kiosco |
| Amarillo | `#FFF200` | Acento, confirmaciones, isotipo sobre morado |
| Negro | `#000000` | Texto |
| Blanco | `#FFFFFF` | Fondo |
