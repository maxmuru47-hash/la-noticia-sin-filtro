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

# La casa es sinfiltroconmax.com. Las noticias viven dentro, no al lado:
# un solo dominio concentra toda la fuerza en buscadores y evita que el
# lector tenga que recordar dos direcciones.
BASE = "https://sinfiltroconmax.com/noticias"

RAIZ = Path(__file__).resolve().parent
PIEZAS = RAIZ / "piezas"

MESES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio",
         "agosto", "septiembre", "octubre", "noviembre", "diciembre"]

def cabecera(pref=""):
    """pref es lo que hay que anteponer para llegar a la raiz del sitio
    de noticias. Vacio en las piezas publicadas, "../" en los borradores.
    Todos los enlaces son relativos: asi el sitio funciona igual colgado
    en /noticias que en cualquier otro sitio, sin tocar una linea."""
    return """<header class="sf-cab">
  <a class="sf-marca" href="%(p)sindex.html">La Noticia SIN <em>FILTRO</em></a>
  <nav class="sf-nav">
    <a href="%(p)sindex.html">Portada</a>
    <a href="https://sinfiltroconmax.com/">SIN FILTRO con Max</a>
  </nav>
</header>""" % {"p": pref}

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

    # Borrador por defecto: una pieza solo se publica si lo dice
    # explicitamente. Equivocarse hacia "no publicado" no cuesta nada;
    # equivocarse hacia "publicado" es irreversible.
    pieza = {"slug": ruta.stem, "fuentes": [], "nota": "", "estado": "borrador"}
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


AVISO_BORRADOR = """<div style="background:#E1121F;color:#fff;padding:12px 20px;
  font:700 12px/1.4 'Archivo',Arial,sans-serif;letter-spacing:.14em;text-transform:uppercase;text-align:center">
  Borrador &middot; esta pieza todav&iacute;a no est&aacute; publicada &middot; no la compartas
</div>
"""


def recortar(texto, limite):
    """Corta por palabra, no a mitad de una."""
    if len(texto) <= limite:
        return texto
    corte = texto[:limite].rsplit(" ", 1)[0]
    return corte.rstrip(" ,;:") + "..."


def url_de(pieza):
    if pieza["estado"] == "publicado":
        return "%s/%s.html" % (BASE, pieza["slug"])
    return "%s/borradores/%s.html" % (BASE, pieza["slug"])


def jsonld_pieza(pieza):
    """Datos estructurados: es lo que hace que un buscador entienda que
    esto es una pieza periodistica con autor y fecha, y no una pagina
    cualquiera. Sin esto, la web compite en peores condiciones."""
    import json
    datos = {
        "@context": "https://schema.org",
        "@type": "NewsArticle",
        "headline": recortar(pieza["titular"], 110),
        "description": pieza["entradilla"],
        "datePublished": pieza["fecha"],
        "dateModified": pieza["fecha"],
        "inLanguage": "es-VE",
        "articleSection": pieza["seccion"],
        "author": {"@type": "Person", "name": "Max Gonzalez"},
        "publisher": {"@type": "Organization", "name": "La Noticia SIN FILTRO"},
        "mainEntityOfPage": {"@type": "WebPage", "@id": url_de(pieza)},
        "isBasedOn": [f[0] for f in pieza["fuentes"]],
    }
    bruto = json.dumps(datos, ensure_ascii=False, indent=2)
    # Un titular que contuviera </script> cerraria la etiqueta y dejaria
    # meter codigo en la pagina. Escapar la barra lo impide sin cambiar
    # lo que lee un buscador. Esto ya nos pasó una vez en este proyecto.
    return bruto.replace("</", "<\\/").replace("<!--", "<\\!--")


def pagina_pieza(pieza, pref=""):
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
<link rel="stylesheet" href="%(pref)sestilo.css">
<link rel="canonical" href="%(canonica)s">
%(robots)s<script type="application/ld+json">%(jsonld)s</script>
</head>
<body>
%(aviso)s%(cabecera)s
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
        "pref": pref,
        "cabecera": cabecera(pref),
        "pie": PIE,
        "canonica": e(url_de(pieza)),
        "robots": '<meta name="robots" content="noindex, nofollow">\n' if pieza["estado"] != "publicado" else "",
        "jsonld": jsonld_pieza(pieza),
        "aviso": AVISO_BORRADOR if pieza["estado"] != "publicado" else "",
    }


def pagina_portada(piezas):
    tarjetas = []
    for p in piezas:
        tarjetas.append("""      <article class="sf-tarjeta">
        <div class="sf-meta">
          <span class="sf-seccion">%s</span>
          <span class="sf-fecha">%s</span>
        </div>
        <h2><a href="%s.html">%s</a></h2>
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
<link rel="stylesheet" href="estilo.css">
<link rel="canonical" href="%s">
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
""" % (BASE + "/", cabecera(), "\n".join(tarjetas), PIE)



def fragmento(publicadas, cuantas=3):
    """Un trozo de HTML con las ultimas piezas, listo para incrustar en
    sinfiltroconmax.com.

    Lleva su propio estilo, encerrado bajo .sfx-ultimas, para que no
    toque ni una regla de la web principal. Y no lleva JavaScript: se
    inserta del lado del servidor, asi que funciona aunque el visitante
    tenga los scripts desactivados y no hace ninguna llamada a nadie.
    """
    tarjetas = []
    for p in publicadas[:cuantas]:
        tarjetas.append("""      <article class="sfx-item">
        <span class="sfx-seccion">%s</span>
        <h3><a href="%s">%s</a></h3>
        <p>%s</p>
      </article>""" % (e(p["seccion"]), e(url_de(p)), e(p["titular"]),
                       e(recortar(p["entradilla"], 160))))

    return """<!-- LA NOTICIA SIN FILTRO · se regenera solo. No editar a mano. -->
<section class="sfx-ultimas">
  <style>
    .sfx-ultimas{--sfx-rojo:#E1121F;padding:clamp(40px,6vw,72px) clamp(20px,5vw,56px);
      background:#000;color:#F4F3F1;font-family:"Archivo","Helvetica Neue",Arial,sans-serif}
    .sfx-ultimas *{box-sizing:border-box}
    .sfx-cab{display:flex;align-items:baseline;justify-content:space-between;gap:16px;
      flex-wrap:wrap;margin-bottom:28px;padding-bottom:16px;border-bottom:2px solid var(--sfx-rojo)}
    .sfx-cab h2{font-family:"Anton","Arial Narrow",Impact,sans-serif;font-weight:400;
      text-transform:uppercase;font-size:clamp(1.5rem,3.5vw,2.2rem);line-height:1;margin:0}
    .sfx-cab a{color:#9A9AA0;text-decoration:none;font-size:11px;font-weight:700;
      letter-spacing:.16em;text-transform:uppercase}
    .sfx-cab a:hover{color:#F4F3F1}
    .sfx-rejilla{display:grid;gap:26px}
    @media(min-width:760px){.sfx-rejilla{grid-template-columns:repeat(3,minmax(0,1fr))}}
    .sfx-item{display:flex;flex-direction:column;gap:10px}
    .sfx-seccion{align-self:flex-start;padding:4px 10px;border:1px solid var(--sfx-rojo);
      color:var(--sfx-rojo);font-size:10px;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
    .sfx-item h3{font-family:"Anton","Arial Narrow",Impact,sans-serif;font-weight:400;
      text-transform:uppercase;font-size:clamp(1.15rem,2vw,1.4rem);line-height:1.05;margin:0}
    .sfx-item h3 a{color:#F4F3F1;text-decoration:none}
    .sfx-item h3 a:hover{color:var(--sfx-rojo)}
    .sfx-item p{margin:0;color:#9A9AA0;font-size:14.5px;line-height:1.55}
  </style>

  <div class="sfx-cab">
    <h2>La noticia sin filtro</h2>
    <a href="%s/">Ver todas &rarr;</a>
  </div>

  <div class="sfx-rejilla">
%s
  </div>
</section>
""" % (BASE, "\n".join(tarjetas))

def sitemap(publicadas):
    urls = ['  <url><loc>%s/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>' % BASE]
    for p in publicadas:
        urls.append('  <url><loc>%s</loc><lastmod>%s</lastmod></url>' % (url_de(p), p["fecha"]))
    return ('<?xml version="1.0" encoding="UTF-8"?>\n'
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
            + "\n".join(urls) + "\n</urlset>\n")


def robots():
    # Los borradores no se indexan nunca. Ademas llevan su propia
    # etiqueta noindex, porque robots.txt pide y la etiqueta obliga.
    return ("User-agent: *\n"
            "Allow: /\n"
            "Disallow: /borradores/\n\n"
            "Sitemap: %s/sitemap.xml\n" % BASE)


def main():
    archivos = sorted(PIEZAS.glob("*.txt"))
    if not archivos:
        sys.exit("No hay piezas en %s" % PIEZAS)

    piezas = [leer(a) for a in archivos]
    piezas.sort(key=lambda p: p["fecha"], reverse=True)

    publicadas = [p for p in piezas if p["estado"] == "publicado"]
    borradores = [p for p in piezas if p["estado"] != "publicado"]

    # Los borradores se limpian y se vuelven a escribir en cada pasada,
    # para que una pieza que pasa a publicada no deje rastro colgando.
    carpeta = RAIZ / "borradores"
    carpeta.mkdir(exist_ok=True)
    for viejo in carpeta.glob("*.html"):
        viejo.unlink()

    for p in publicadas:
        (RAIZ / (p["slug"] + ".html")).write_text(pagina_pieza(p), encoding="utf-8")
    for p in borradores:
        (carpeta / (p["slug"] + ".html")).write_text(pagina_pieza(p, "../"), encoding="utf-8")
        # Si estuvo publicada antes, se retira de su sitio publico.
        antiguo = RAIZ / (p["slug"] + ".html")
        if antiguo.exists():
            antiguo.unlink()

    (RAIZ / "index.html").write_text(pagina_portada(publicadas), encoding="utf-8")
    (RAIZ / "sitemap.xml").write_text(sitemap(publicadas), encoding="utf-8")
    (RAIZ / "robots.txt").write_text(robots(), encoding="utf-8")
    (RAIZ / "ultimas.html").write_text(fragmento(publicadas), encoding="utf-8")

    print("Publicadas: %d   Borradores: %d" % (len(publicadas), len(borradores)))
    for p in borradores:
        print("   borrador -> %s" % url_de(p))


if __name__ == "__main__":
    main()
