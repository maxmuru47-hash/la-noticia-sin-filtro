# Cómo separar CrediSan Control a su propio repositorio

Ahora mismo `credisan-control/` vive dentro del repositorio
`la-noticia-sin-filtro` porque esta sesión sólo tiene acceso a ese repositorio.
**No comparte ni una línea de código con el proyecto editorial**: es una carpeta
autónoma, con su propio backend, sus propias migraciones y sus propios activos.

Cuando cree el repositorio `credisan-control` en GitHub, sepárelo conservando
todo el historial:

```bash
# 1. Extraer la carpeta como una rama con su historia propia
git subtree split --prefix=credisan-control -b credisan-solo

# 2. Publicarla en el repositorio nuevo
git push git@github.com:<usuario>/credisan-control.git credisan-solo:main

# 3. Ya en el repositorio editorial, retirar la carpeta
git rm -r credisan-control
git commit -m "CrediSan Control se muda a su propio repositorio"
```

Si prefiere empezar limpio, sin historial, basta con copiar la carpeta:

```bash
cp -r credisan-control /ruta/credisan-control && cd /ruta/credisan-control
git init && git add . && git commit -m "CrediSan Control Multisede v1.0 — Fase 1"
```

Nada dentro de la carpeta apunta hacia afuera: no hay rutas relativas al
proyecto editorial, ni dependencias compartidas, ni configuración común.
