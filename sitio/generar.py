#!/usr/bin/env python3
"""
Genera el sitio estatico de La Noticia SIN FILTRO.

Cada pieza es un archivo de texto en sitio/piezas/<slug>.txt. Publicar una
pieza nueva es anadir un archivo y volver a ejecutar esto: no hay base de
datos, no hay panel, no hay nada que se pueda caer.

El formato del archivo de pieza es el mismo que se escribe a mano:

    TITULAR: ...
    ENTRADILLA: ...
    SECCION: ...
    FECHA: 2026-09-23
    ---
    (cuerpo, parrafos separados por linea en blanco; una linea suelta y
     corta sin punto final es un intertitulo)
    ---
    FUENTES
    https://...  |  Medio · titular  |  23 de septiembre de 2026
    ---
    NOTA: (opcional, nota de metodo)
"""
import html
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent
PIEZAS = RAIZ / "piezas"

MESES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio",
         "agosto", "septiembre", "octubre", "noviembre", "diciembre"]

CABECERA = """<header class="sf-cab">
  <a class="sf-marca" href="/">La Noticia SIN <em>FILTRO</em></a>
  <nav class="sf-nav">
    <a href="/">Portada</a>
    <a href="https://sinfiltroconmax.com">SIN FILTRO con Max</a>
  </nav>
</header>"""

PIE = """<footer class="sf-pie">
  La Noticia SIN <em>FILTRO</em> &middot; Toda cifra con su fecha y su fuente. Cuando no lo sabemos, lo decimos.
</footer>"""


def e(t):
    return html.escape(t, quote=True)


def fecha_larga(iso):
    a, m, d = iso.split("-")
    return "%d de %s de %s" % (int(d), MESES[int(m) - 1], a)


def leer(ruta):
    bruto = ruta.read_text(encoding="utf-8")
    bloques = [b.strip() for b in bruto.split("\n---\n")]

    pieza = {"slug": ruta.stem, "fuentes": [], "nota": ""}
    for linea in bloques[0].splitlines():
        if ":" in linea:
            clave, valor = linea.split(":", 1)
            pieza[clave.strip().lower()] = valor.strip()

    pieza["cuerpo"] = bloques[1] if len(bloques) > 1 else ""

    for bloque in bloques[2:]:
        if bloque.upper().startswith("FUENTES"):
            for linea in bloque.splitlines()[1:]:
                partes = [p.strip() for p in linea.split("|")]
                if len(partes) >= 2:
                    pieza["fuentes"].append(partes)
        elif bloque.upper().startswith("NOTA:"):
            pieza["nota"] = bloque.split(":", 1)[1].strip()

    faltan = [c for c in ("titular", "entradilla", "seccion", "fecha") if not pieza.get(c)]
    if faltan:
        sys.exit("ERROR en %s: falta %s" % (ruta.name, ", ".join(faltan)))
    return pieza


def cuerpo_html(texto):
    """Misma regla de siempre: linea suelta, corta y sin punto final es
    intertitulo. Asi el texto se escribe igual que se dicta."""
    trozos = [t.strip() for t in re.split(r"\n{2,}", texto) if t.strip()]
    salida = []
    dentro_nosabe = False

    for i, trozo in enumerate(trozos):
        una_linea = "\n" not in trozo
        sin_punto = not re.search(r"[.:;!?]$", trozo)
        viene_otro = i + 1 < len(trozos)

        if una_linea and sin_punto and len(trozo) <= 80 and viene_otro:
            titulo = trozo.lower()
            if dentro_nosabe:
                salida.append("</div>")
                dentro_nosabe = False
            if titulo.startswith("lo que no se sabe"):
                salida.append('<div class="sf-nosabe">')
                dentro_nosabe = True
            elif titulo.startswith("qué te llevas") or titulo.startswith("que te llevas"):
                salida.append('<div class="sf-llevas">')
                dentro_nosabe = True
            salida.append("<h2>%s</h2>" % e(trozo))
        else:
            salida.append("<p>%s</p>" % e(trozo))

    if dentro_nosabe:
        salida.append("</div>")
    return "\n    ".join(salida)


def fuentes_html(pieza):
    if not pieza["fuentes"]:
        return ""
    filas = []
    for partes in pieza["fuentes"]:
        url, etiqueta = partes[0], partes[1]
        fecha = (" &mdash; " + e(partes[2])) if len(partes) > 2 else ""
        filas.append('      <li><a href="%s" target="_blank" rel="noopener">%s</a>%s</li>'
                     % (e(url), e(etiqueta), fecha))
    nota = ('\n    <p class="sf-metodo"><strong>Nota de método.</strong> %s</p>' % e(pieza["nota"])) if pieza["nota"] else ""
    return """
  <div class="sf-fuentes">
    <h2>Fuentes</h2>
    <ul>
%s
    </ul>%s
  </div>""" % ("\n".join(filas), nota)


def pagina_pieza(pieza):
    return """<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>%(titular)s &middot; La Noticia SIN FILTRO</title>
<meta name="description" content="%(entradilla)s">
<meta property="og:title" content="%(titular)s">
<meta property="og:description" content="%(entradilla)s">
<meta property="og:type" content="article">
<link rel="stylesheet" href="/estilo.css">
</head>
<body>
%(cabecera)s
<div class="sf-envol">
  <div class="sf-eyebrow">
    <span class="sf-seccion">%(seccion)s</span>
    <span class="sf-fecha">%(fecha_larga)s</span>
  </div>
  <h1>%(titular)s</h1>
  <p class="sf-entradilla">%(entradilla)s</p>
  <div class="sf-cuerpo">
    %(cuerpo)s
  </div>%(fuentes)s
</div>
%(pie)s
</body>
</html>
""" % {
        "titular": e(pieza["titular"]),
        "entradilla": e(pieza["entradilla"]),
        "seccion": e(pieza["seccion"]),
        "fecha_larga": e(fecha_larga(pieza["fecha"])),
        "cuerpo": cuerpo_html(pieza["cuerpo"]),
        "fuentes": fuentes_html(pieza),
        "cabecera": CABECERA,
        "pie": PIE,
    }


def pagina_portada(piezas):
    tarjetas = []
    for p in piezas:
        tarjetas.append("""      <article class="sf-tarjeta">
        <div class="sf-meta">
          <span class="sf-seccion">%s</span>
          <span class="sf-fecha">%s</span>
        </div>
        <h2><a href="/%s.html">%s</a></h2>
        <p>%s</p>
      </article>""" % (e(p["seccion"]), e(fecha_larga(p["fecha"])), e(p["slug"]),
                       e(p["titular"]), e(p["entradilla"])))

    return """<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>La Noticia SIN FILTRO</title>
<meta name="description" content="Economía, negocios e inteligencia artificial explicados en consecuencias concretas. Cada cifra con su fecha y su fuente.">
<link rel="stylesheet" href="/estilo.css">
</head>
<body>
%s
<main class="sf-portada">
  <p class="sf-kicker">Análisis económico y emprendimiento &middot; Venezuela</p>
  <h1 class="sf-titulo">La noticia<span>sin filtro</span></h1>
  <p class="sf-lema">Economía, negocios e inteligencia artificial explicados en consecuencias concretas.
     Toda cifra lleva su fecha y su fuente, y cuando no lo sabemos, lo decimos.</p>
  <div class="sf-rejilla">
%s
  </div>
</main>
%s
</body>
</html>
""" % (CABECERA, "\n".join(tarjetas), PIE)


def main():
    archivos = sorted(PIEZAS.glob("*.txt"))
    if not archivos:
        sys.exit("No hay piezas en %s" % PIEZAS)

    piezas = [leer(a) for a in archivos]
    piezas.sort(key=lambda p: p["fecha"], reverse=True)

    for p in piezas:
        (RAIZ / (p["slug"] + ".html")).write_text(pagina_pieza(p), encoding="utf-8")

    (RAIZ / "index.html").write_text(pagina_portada(piezas), encoding="utf-8")
    print("Generadas %d piezas y la portada." % len(piezas))


if __name__ == "__main__":
    main()
