# Informe de seguridad

Revisión hecha **atacando el servidor**, no leyendo el código. Cada punto
tiene una prueba que se ejecutó de verdad contra la plataforma corriendo.

Fecha de la revisión: septiembre de 2026.

---

## Resumen

| Área | Pruebas | Resultado |
|---|---|---|
| Exposición de archivos | 11 | Todo devuelve 404 |
| Travesía de rutas | 6 | Ninguna filtración |
| Inyección SQL | 9 | Ninguna, y la base quedó intacta |
| API pública sin autenticar | 3 | Todo exige token CSRF |
| Integridad del voto | 4 | No se puede votar dos veces ni por opción ajena |
| Privacidad del Pulso | 2 | No se guarda ninguna IP |
| Escalada de privilegios | 18 | Ningún rol pudo salirse de lo suyo |
| XSS almacenado y reflejado | 5 | Todo llega escapado |
| Redirección abierta | 4 | Ningún destino externo aceptado |
| Sesión | 2 | Se regenera al entrar, cookie HttpOnly |
| Subida de archivos | 6 | Ningún ejecutable entró |
| Límite de intentos | 1 | Bloquea tras varios fallos |
| Cabeceras | 6 | Todas presentes |
| SSRF | 4 | Ninguna URL peligrosa se guardó |
| Fuga en errores | 4 | Nada se filtra |
| Enumeración de usuarios | 2 | El mensaje no distingue |

**Cuatro fallos reales encontrados. Los cuatro corregidos y con prueba
automática que impide que vuelvan.**

---

## Los cuatro fallos, y qué se hizo

### 1. La contraseña provisional no se cambiaba nunca

`users.must_change_password` se guardaba al crear una cuenta y **nadie lo
miraba**. Una persona entraba con la clave que le dio el administrador y se
quedaba con ella para siempre. Además, no existía ninguna pantalla para
cambiar la propia contraseña.

**Corregido.** Ahora, mientras la clave sea provisional, el panel no deja ir
a ninguna otra pantalla: cualquier ruta redirige a `/panel/clave`. Se
comprobó ruta por ruta.

La pantalla de cambio exige:

- La contraseña actual, **aunque la sesión ya esté abierta**. Si alguien
  deja el panel abierto, que no puedan cambiarle la clave y quedarse con la
  cuenta.
- Mínimo 12 caracteres.
- Que las dos coincidan.
- Que sea distinta de la actual.
- Que no contenga su propio correo.

Al cambiarla se regenera el identificador de sesión, así que **cualquier
sesión robada deja de servir**. Verificado: la contraseña vieja dejó de
funcionar inmediatamente.

### 2. Arrancaba en silencio sin APP_KEY

`APP_KEY` es la sal de las huellas anónimas del Pulso. Con una sal vacía esa
huella se vuelve adivinable: cualquiera podría calcular la de otra persona a
partir de su IP y su navegador, y **el voto anónimo dejaría de ser anónimo**.

La aplicación arrancaba igual, sin avisar.

**Corregido.** Sin `APP_KEY` de al menos 32 caracteres, la aplicación se
niega a arrancar y explica cómo generarla. Verificado: devuelve 500 con
instrucciones.

### 3. Una sesión olvidada duraba para siempre

No había caducidad. Una sesión abierta en un ordenador prestado seguía
válida indefinidamente.

**Corregido.** Cuatro horas de **inactividad** cierran la sesión. Se mide
inactividad, no duración: trabajar no te echa fuera.

### 4. Un id concatenado en una consulta

Al construir el panel del expediente concatené un identificador dentro de
una consulta en lugar de usar un marcador. El valor ya venía casteado a
entero, así que no era explotable, pero rompía la regla del proyecto.

**Lo encontró una prueba propia del repositorio**, no una persona.
Corregido con marcador.

---

## Lo que ya estaba bien, comprobado

### Permisos

Se abrió sesión como **autor** y se intentó, una por una, cada acción que no
le corresponde. Las 18 devolvieron 403:

- Publicar, archivar, retirar, eliminar, cambiar la URL de una pieza ajena.
- Abrir o editar una pieza que no es suya.
- Tocar el pulso, las fuentes, la cronología, los actores, las
  consecuencias, la herencia o las relaciones de una pieza ajena.
- Entrar a usuarios, auditoría, redirecciones, portada o métricas.

Como **editor** tampoco se pudo eliminar definitivamente ni gestionar
usuarios. Después de todos los intentos, las cinco piezas seguían vivas y el
pulso ajeno intacto.

### El voto no se puede falsear

- Votar por una opción que pertenece a otro pulso: rechazado.
- Votar con un identificador negativo: rechazado.
- Votar dos veces: el primero entra, el segundo devuelve 409.
- Una opción que ya tiene votos **no se puede borrar** desde el panel: la
  transacción se revierte entera.
- **Ningún controlador del panel escribe en `poll_responses`.** Hay una
  prueba automática que recorre el código y lo verifica.

### Privacidad

`poll_responses` no tiene columna de IP. Solo guarda un sha256 con la sal
del servidor, que no permite reconstruir la dirección ni cruzarla con nada.

### Subidas

Se intentó subir, y todo fue rechazado:

- Un `.php` con su extensión.
- Un `.php` renombrado a `.jpg` y declarado como `image/jpeg`.
- Un GIF políglota con código PHP dentro.
- Un binario aleatorio como `.png`.
- Una imagen legítima **sin texto alternativo**.

Solo entró la imagen legítima con su alt. En `public/uploads` no quedó
ningún ejecutable, y el original se guardó fuera de la carpeta pública.

### XSS

Se envió `<script>alert(1)</script><img src=x onerror=alert(2)>` como
pregunta de la comunidad y se aprobó para publicarla. Llegó a la página
**escapada como texto literal**, sin ningún elemento ni atributo ejecutable.

En el buscador, `<script>` y `<svg onload=>` se reflejaron escapados.

### Redirección abierta

Se intentó que el login redirigiera a `https://malo.example`,
`//malo.example`, `/\/malo.example` y `https:/malo.example`. Los cuatro
terminaron en `/panel`.

---

## Lo que queda pendiente

Ninguno es un fallo explotable hoy, pero conviene cerrarlos:

1. **Sin segundo factor.** Para las cuentas con rol superior sería lo
   siguiente que yo añadiría.
2. **Sin recuperación de contraseña.** Hoy, si alguien la olvida, un
   administrador tiene que crearle una provisional. Es una decisión
   defendible: un flujo de recuperación por correo mal hecho es una puerta
   de entrada. Cuando exista envío de correo, conviene hacerlo bien.
3. **Originales huérfanos.** Si una subida falla a medias, el original
   copiado en `storage/originals` se queda. No es accesible desde internet y
   los originales son valiosos, así que se prefiere el huérfano a borrar de
   más. Falta una herramienta de limpieza.
4. **`style-src-attr 'unsafe-inline'` en la política de contenido.** Se
   necesita para los anchos calculados de las barras del Pulso. El
   saneamiento elimina cualquier atributo `style` del contenido escrito en
   el panel, así que no es una vía de inyección, pero es la única concesión
   de la política.
5. **Las páginas públicas se sirven con `no-store`**, porque PHP lo impone
   al iniciar sesión para el token CSRF. No es un problema de seguridad,
   pero impide cachear y penaliza la velocidad. Merece una vuelta.

---

## Cómo repetir esta revisión

Las comprobaciones que se pueden automatizar están dentro de las pruebas:

```bash
php tests/ejecutar.php
```

Los grupos 6, 7, 8, 14, 15, 16 y 17 cubren saneamiento, permisos, consultas
preparadas, integridad del Pulso, restauración de versiones y seguridad de
la cuenta.

Las pruebas de ataque contra el servidor corriendo hay que repetirlas a mano
cuando se toque la autenticación, las subidas o la API pública. Están
descritas una por una en este documento, con el resultado esperado.
